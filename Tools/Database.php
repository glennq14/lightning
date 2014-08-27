<?php

namespace Lightning\Tools;

use Exception;
use PDO;
use PDOException;
use PDOStatement;

class Database extends Singleton {
    /**
     * The mysql connection.
     *
     * @var PDO
     */
    var $connection;

    /**
     * Determines if queries and errors should be collected and output.
     *
     * @var boolean
     */
    private $verbose = false;

    /**
     * An array of all queries called in this page request.
     *
     * @var array
     */
    private $history = array();

    /**
     * The result of the last query.
     *
     * @var PDOStatement
     */
    private $result;

    /**
     * The timer start time.
     *
     * @var float
     */
    private $start;

    /**
     * The mysql execution end time.
     *
     * @var float
     */
    private $end1;

    /**
     * The php execution end time.
     *
     * @var float
     */
    private $end2;

    /**
     * The total number of queries executed.
     *
     * @var integer
     */
    private $query_count = 0;

    /**
     * The total time to execute mysql queries.
     *
     * @var integer
     */
    private $mysql_time = 0;

    /**
     * The total time to execute the php post processing of mysql queries data.
     *
     * @var integer
     */
    private $php_time = 0;

    /**
     * Whether this is in read only mode.
     *
     * @var boolean
     */
    private $readOnly = FALSE;

    /**
     * Whether the current connection is in the transaction state.
     *
     * @var boolean
     */
    private $inTransaction = FALSE;

    /**
     * Construct this object.
     *
     * @param string $url
     *   Database URL.
     */
    public function __construct($url=''){
        $this->verbose = Configuration::get('debug');

        try {
            // Extract user data.
            $results = NULL;
            preg_match('|user=(.*)[;$]|U', $url, $results);
            $username = !empty($results[1]) ? $results[1] : '';
            preg_match('|password=(.*)[;$]|U', $url, $results);
            $password = !empty($results[1]) ? $results[1] : '';

            // @todo remove @ when php header 5.6 is updated
            $this->connection = @new PDO($url, $username, $password);
        } catch (PDOException $e) {
            // Error handling.
            syslog(LOG_EMERG, 'Connection failed: ' . $e->getMessage());
            if ($this->verbose) {
                die('Connection failed: ' . $e->getMessage());
            }
            else {
                die('Connection Failed.');
            }
        }
    }

    /**
     * @return Database
     */
    public static function getInstance() {
        return parent::getInstance();
    }

    /**
     * Create a database instance with the default database.
     *
     * @return Database
     *   The database object.
     */
    public static function createInstance() {
        return new self(Configuration::get('database'));
    }

    /**
     * Set the controller to only execute select queries.
     *
     * @param boolean $value
     *   Whether readOnly should be on or off.
     *
     * @notice
     *   This has no effect on direct query functions like query() and assoc()
     */
    public function readOnly($value = TRUE){
        $this->readOnly = $value;
    }

    /**
     * Whether to enable verbose messages in output.
     *
     * @param boolean $value
     *   Whether to switch to verbose mode.
     */
    public function verbose($value = TRUE){
        $this->verbose = $value;
    }

    /**
     * Outputs a list of queries that have been called during this page request.
     *
     * @return array
     */
    public function getQueries(){
        return $this->history;
    }

    /**
     * Called whenever mysql returns an error executing a query.
     *
     * @param array $error
     *   The PDO error.
     * @param string $sql
     *   The original query.
     *
     * @throws Exception
     *   When a mysql error occurs.
     */
    public function errorHandler($error, $sql){
        $errors = array();

        // Add a header.
        $errors[] = "MYSQL ERROR ($error[0]:$error[1]): $error[2]";
        // Add the full query.
        $errors[] = $sql;

        // Show the stack trace.
        $backtrace = debug_backtrace();
        foreach($backtrace as $call){
            if(!preg_match('/class_database\.php$/', $call['file'])){
                $errors[] = 'Called from: ' .$call['file'] . ' : ' . $call['line'];
            }
        }

        // Show actual mysql error.
        $errors[] = $error[2];

        if ($this->verbose) {
            // Add a footer.
            // @todo change this so it doesn't require an input.
            foreach ($errors as $e) {
                Messenger::error($e);
            }
            throw new Exception("***** MYSQL ERROR *****");
        } else {
            foreach ($errors as $e) {
                Logger::error($e);
            }
            Logger::error($sql);
        }
        exit;
    }

    /**
     * Saves a query to the history and should be called on each query.
     *
     * @param $sql
     */
    public function log($sql){
        $this->history[] = $sql;
    }

    /**
     * Start a query.
     */
    public function timerStart(){
        $this->start = microtime(TRUE);
    }

    /**
     * A query is done, add up the times.
     */
    public function timerQueryEnd(){
        $this->end1 = microtime(TRUE);
    }

    /**
     * Stop the timer and add up the times.
     */
    public function timerEnd(){
        $this->end2 = microtime(TRUE);
        $this->mysql_time += $this->end1-$this->start;
        $this->php_time += $this->end2-$this->start;
    }

    /**
     * Reset the clock.
     */
    public function timerReset(){
        $this->query_count = 0;
        $this->mysql_time = 0;
        $this->php_time = 0;
    }

    /**
     * Output a time report
     */
    public function timeReport(){
        return array(
            "Total Queries: {$this->query_count}",
            "Total SQL Time: {$this->mysql_time}",
            "Total PHP Time: {$this->php_time}",
        );
    }

    /**
     * Raw query handler.
     */
    private function _query($query, $vars = array()){
        if ($this->readOnly) {
            if (!preg_match("/^SELECT /i", $query)) {
                return;
            }
        }
        $this->query_count ++;
        if ($this->verbose) {
            $this->log($query);
            $this->timerStart();
            $this->__query_execute($query, $vars);
            $this->timerQueryEnd();
        }
        else {
            $this->__query_execute($query, $vars);
        }
        if (!$this->result) {
            $this->errorHandler($this->connection->errorInfo(), $query);
        }
        elseif ($this->result->errorCode() != "00000") {
            $this->errorHandler($this->result->errorInfo(), $query);
        }
    }

    /**
     * Execute query and pull results object.
     *
     * @param $query
     * @param $vars
     */
    private function __query_execute($query, $vars) {
        if (!empty($vars)) {
            $this->result = $this->connection->prepare($query);
            $this->result->execute($vars);
        }
        else {
            $this->result = $this->connection->query($query);
        }
    }

    /**
     * Simple query execution.
     *
     * @param $sql
     * @param array $vars
     *
     * @return PDOStatement
     */
    public function query($sql, $vars = array()){
        $this->_query($sql, $vars);
        $this->timerEnd();
        return $this->result;
    }

    /**
     * Checks if at least one entry exists.
     */
    public function check($table, $where = array()){
        $fields = empty($fields) ? '*' : implode($fields);
        $values = array();
        if (!empty($where)) {
            $where = ' WHERE ' . $this->sqlImplode($where, $values, 'AND');
        }
        $this->_query('SELECT ' . $fields . ' FROM ' . $table . $where . ' LIMIT 1', $values);
        $this->timerEnd();
        return $this->result->rowCount() > 0;
    }

    /**
     * Counts total number of matching rows.
     */
    public function count($table, $where = array()){
        return $this->selectField(array('count' => 'COUNT(*)'), $table, $where);
    }

    /**
     * Update a row.
     *
     * @param $table
     * @param $data
     * @param $where
     * @return db_query
     */
    public function update($table, $data, $where){
        $vars = array();
        $query = 'UPDATE ' . $table . ' SET ' . $this->sqlImplode($data, $vars) . ' WHERE ';
        if (is_array($where)) {
            $query .= $this->sqlImplode($where, $vars);
        }
        $this->timerEnd();
        $this->query($query, $vars);
    }

    /**
     * Insert a new row into a table.
     *
     * @param string $table
     *   The table to insert into.
     * @param array $data
     *   An array of columns and values to set.
     * @param boolean|array $existing
     *   TRUE to ignore, an array to update.
     *
     * @return int
     *   The last inserted id.
     */
    public function insert($table, $data, $existing = FALSE) {
        $vars = array();
        $ignore = $existing === TRUE ? 'IGNORE' : '';
        $set = $this->sqlImplode($data, $vars);
        $duplicate = is_array($existing) ? ' ON DUPLICATE KEY UPDATE ' . $this->sqlImplode($existing, $vars) : '';
        $this->query('INSERT ' . $ignore . ' INTO `' . $table . '` SET ' . $set . $duplicate, $vars);
        $this->timerEnd();
        return $this->result->rowCount() == 0 ? false : $this->connection->lastInsertId();
    }

    public function delete($table, $where) {
        $values = array();
        if (is_array($where)) {
            $where = $this->sqlImplode($where, $values);
        }
        $this->query('DELETE FROM `' . $table . '` WHERE ' . $where, $values);
    }

    /**
     * Universal select function.
     */
    protected function _select($table, $where = array(), $fields = array(), $limit = NULL, $final = '') {
        $fields = $this->implodeFields($fields);
        $values = array();
        $where = !empty($where) ? ' WHERE ' . $this->sqlImplode($where, $values, ' AND ') : '';
        $limit = is_array($limit) ? ' LIMIT ' . $limit[0] . ', ' . $limit[1] . ' '
            : !empty($limit) ? ' LIMIT ' . intval($limit) : '';
        $this->query('SELECT ' . $fields . ' FROM ' . $this->parseTable($table, $values) . $where . ' ' . $final . $limit, $values);
    }

    /**
     * Create a query-ready string for a table and it's joins.
     *
     * @param string|array $table
     *   The table name or table with join data.
     * @param array $values
     *   The PDO replacement variables.
     *
     * @return string
     *   The query-ready string for the table and it's joins.
     */
    protected function parseTable($table, &$values) {
        if (is_string($table)) {
            return '`' . $table . '`';
        }
        else {
            $output = $this->parseTable($table['from'], $values);
            if (!empty($table['join'])) {
                // If the first element of join is not an array, it's an actual join.
                if (!is_array($table['join'][0])) {
                    // Wrap it in an array so we can loop over it.
                    $table['join'] = array($table['join']);
                }
                // Foreach join.
                foreach ($table['join'] as $join) {
                    $output .= $this->implodeJoin($join[0], $join[1], !empty($join[2]) ? $join[2] : '', $values);
                    // Add any extra replacement variables.
                    if (isset($join[3])) {
                        $values = array_merge($values, $join[3]);
                    }
                }
            }
            // If this join is a subquery, wrap it.
            if (is_array($table) && isset($table['as'])) {
                if (!empty($table['fields'])) {
                    $output = $this->implodeFields($table['fields']) . ' FROM ' . $output;
                } else {
                    $output = ' * FROM ' . $output;
                }
                if (!empty($table['order'])) {
                    $output .= $this->implodeOrder($table['order'], $values);
                }
                $output = '( SELECT ' . $output . ') AS ' . $table['as'];
            }
            return $output;
        }
    }

    /**
     * Run a select query and return a result object.
     */
    public function select($table, $where = array(), $fields = array(), $final = ''){
        $this->_select($table, $where, $fields, null, $final);
        $this->timerEnd();
        return $this->result;
    }

    /**
     * Run a select query and return a result array.
     */
    public function selectAll($table, $where = array(), $fields = array(), $final = '') {
        $this->_select($table, $where, $fields, $final);
        $result = $this->result->fetchAll(PDO::FETCH_ASSOC);
        $this->timerEnd();
        return $result;
    }

    /**
     * Run a select query and return the rows indexed by a key.
     */
    public function selectIndexed($table, $key, $where = array(), $fields = array(), $final = '') {
        $this->_select($table, $where, $fields, NULL, $final);
        $results = array();
        // TODO: This is built in to PDO.
        while ($row = $this->result->fetch(PDO::FETCH_ASSOC)) {
            $results[$row[$key]] = $row;
        }
        $this->timerEnd();
        return $results;
    }

    /**
     * Select just a single row.
     */
    public function selectRow($table, $where = array(), $fields = array(), $final = ''){
        $this->_select($table, $where, $fields, 1, $final);
        $this->timerEnd();
        return $this->result->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Select a single column.
     *
     * @param string $table
     *   The main table to select from.
     * @param string $column
     *   The column to select.
     * @param array $where
     *   Conditions.
     * @param string $key
     *   A field to index the column.
     * @param string $final
     *   Additional query data.
     *
     * @return array
     */
    public function selectColumn($table, $column, $where = array(), $key = NULL, $final = '') {
        $fields = array($column);
        if ($key) {
            array_unshift($fields, $key);
        }
        $this->_select($table, $where, $fields, NULL, $final);
        if ($key) {
            $output = $this->result->fetchAll(PDO::FETCH_KEY_PAIR);
        } else {
            $output = $this->result->fetchAll(PDO::FETCH_COLUMN);
        }
        $this->timerEnd();
        return $output;
    }

    /**
     * Select a single column from the first row.
     */
    public function selectField($field, $table, $where = array(), $final = ''){
        $row = $this->selectRow($table, $where, array($field), $final);
        if (is_array($field)) {
            // This is an expression.
            reset($field);
            return $row[key($field)];
        } else {
            return $row[$field];
        }
    }

    /**
     * Gets the number of affected rows from the last query.
     *
     * @return int
     */
    public function affectedRows(){
        return $this->connection->affectedRows;
    }

    /**
     * Stars a db transaction.
     */
    public function startTransaction(){
        $this->query("BEGIN");
        $this->query("SET autocommit=0");
        $this->inTransaction = true;
    }

    /**
     * Ends a db transaction.
     */
    public function commitTransaction(){
        $this->query("COMMIT");
        $this->query("SET autocommit=1");
        $this->inTransaction = false;
    }

    /**
     * Terminates a transaction and rolls back to the previous state.
     */
    public function killTransaction(){
        $this->query("ROLLBACK");
        $this->query("SET autocommit=1");
        $this->inTransaction = false;
    }

    /**
     * Determine if the connection is currently in a transactional state.
     *
     * @return boolean
     */
    public function inTransaction(){
        return $this->inTransaction;
    }

    /**
     * Convert an order array into a query string.
     *
     * @param array $order
     *   A list of fields and their order.
     *
     * @return string
     *   SQL ready string.
     */
    protected function implodeOrder($order) {
        $output = ' ORDER BY ';
        foreach ($order as $field => $direction) {
            $output .= '`' . $field . '` ' . $direction;
        }
        return $output;
    }

    /**
     * Implode a join from the name, table, condition, etc.
     *
     * @param string $joinType
     *   LEFT JOIN, JOIN, RIGHT JOIN, INNER JOIN
     * @param string|array $table
     *   The table criteria
     * @param string $condition
     *   Including USING or ON
     * @param array $values
     *   The PDO replacement variables.
     *
     * @return string
     *   The SQL query segment.
     */
    protected function implodeJoin($joinType, $table, $condition, &$values) {
        return ' ' . $joinType . ' ' . $this->parseTable($table, $values) . ' ' . $condition;
    }

    /**
     * Convert a list of fields into a string.
     *
     * @param array $fields
     *   A list of fields and their aliases to retrieve.
     *
     * @return string
     *   The SQL query segment.
     */
    protected function implodeFields($fields) {
        foreach ($fields as &$field) {
            if (is_array($field)) {
                // This field is an expression.
                $field = current($field) . ' AS `' . key($field) . '`';
            } else {
                $field = '`' . $field . '`';
            }
        }
        return empty($fields) ? '*' : implode(', ', $fields);
    }

    /**
     * Build a list of values by imploding an array.
     *
     * @param $array
     *   The field => value pairs.
     * @param $values
     *   The current list of replacement values.
     * @param string $concatenator
     *   The string used to concatenate (usually , or AND or OR)
     *
     * @return string
     *   The query string segment.
     */
    public function sqlImplode($array, &$values, $concatenator=', '){
        $a2 = array();
        foreach ($array as $k=>$v) {
            // This might change from an and to an or.
            if ($k == '#operator') {
                $concatenator = $v;
                continue;
            }
            // If the value is an array.
            if (is_array($v)) {
                // Value is an expression.
                if (!empty($v['expression'])) {
                    $a2[] = "`{$k}` = {$v['value']}";
                    if (!empty($v['vars']) && is_array($v['vars'])) {
                        $values = array_merge($values, $v['vars']);
                    }
                }
                // IN operator.
                elseif (strtoupper($v[0]) == 'IN') {
                    $values = array_merge($values, array_values($v[1]));
                    $a2[] = "`{$k}` IN (" . implode(array_fill(0, count($v[1]), '?'), ",") . ")";
                }
                // Between operator.
                elseif (strtoupper($v[0]) == 'BETWEEN') {
                    $a2[] = "`{$k}` BETWEEN ? AND ? ";
                    $values[] = $v[1];
                    $values[] = $v[2];
                }
                // Single comparison operators.
                elseif (in_array($v[0], array('!=', '<', '<=', '>', '>=', 'LIKE'))) {
                    $values[] = $v[1];
                    $a2[] = " `{$k}` {$v[0]} ? ";
                }
            }
            else {
                // Standard key/value column = value.
                $values[] = $v;
                $a2[] = " `{$k}` = ? ";
            }
        }
        return implode($concatenator, $a2);
    }

    /**
     * Create a new table.
     *
     * @param string $table
     *   The table name.
     * @param array $columns
     *   The columns to add.
     * @param array $indexes
     *   The indexes to add.
     */
    public function createTable($table, $columns, $indexes) {
        $primary_added = false;

        // Find the primary column if there is only 1.
        $primary_column = null;
        if (empty($indexes['primary'])) {
            $primary_column = null;
        }
        if (is_string($indexes['primary'])) {
            $primary_column = $indexes['primary'];
        }
        elseif (!empty($indexes['primary']['columns'])) {
            if (count($indexes['primary']['columns']) == 1) {
                $primary_column = $indexes['primary']['columns'][0];
            }
        }

        foreach ($columns as $column => $settings) {
            $definitions[] = $this->getColumnDefinition($column, $settings, $primary_column == $column);
            if ($primary_column == $column) {
                $primary_added = true;
            }
        }

        foreach ($indexes as $index => $settings) {
            if ($primary_added && $index == 'primary') {
                // The primary key was already added with the column.
                continue;
            }
            $definitions[] = $this->getIndexDefinition($index, $settings);
        }

        $query = "CREATE TABLE {$table} (" . implode(',', $definitions) . ') ENGINE=InnoDB;';

        $this->query($query);
    }

    /**
     * Create a column definition for adding to a table.
     *
     * @param string $name
     *   The name of the column.
     * @param array $settings
     *   The definition of the column.
     * @param boolean $primary
     *   Whether this column should be the primary key.
     *
     * @return string
     *   The column definition.
     */
    protected function getColumnDefinition($name, $settings, $primary = false) {
        $definition = "`{$name}` ";

        $definition .= $settings['type'];
        if (!empty($settings['size'])) {
            $definition .= "({$settings['size']})";
        }

        if (!empty($settings['unsigned'])) {
            $definition .= ' UNSIGNED ';
        }

        if (empty($settings['null'])) {
            $definition .= ' NOT NULL ';
        } else {
            $definition .= ' NULL ';
        }

        if (!empty($settings['auto_increment']) || $primary) {
            $definition .= ' PRIMARY KEY ';

            if (!empty($settings['auto_increment'])) {
                $definition .= 'AUTO_INCREMENT';
            }
        }

        return $definition;
    }

    /**
     * Create an index definition to add to a table.
     *
     * @param string $name
     *   The index name.
     * @param array $settings
     *   The index definition.
     *
     * @return string
     *   The index definition.
     */
    protected function getIndexDefinition($name, $settings) {
        // Figure out the columns.
        if (is_array($settings['columns'])) {
            $columns = $settings['columns'];
        }
        elseif (is_string($settings['columns'])) {
            $columns = array($settings['columns']);
        }
        else {
            $columns = array($name);
        }

        $definition = empty($settings['unique']) ? 'INDEX ' : 'UNIQUE INDEX ';
        $definition .= '`' . $name . '` (`' . implode('`,`', $columns) . '`)' ;
        if (!empty($settings['size'])) {
            $definition .= ' KEY_BLOCK_SIZE = ' . intval($settings['size']);
        }
        return $definition;
    }

    /**
     * Check if a table exists.
     *
     * @param string $table
     *   The name of the table.
     *
     * @return boolean
     */
    public function tableExists($table) {
        return $this->query('SHOW TABLES LIKE ?', array($table))->rowCount() == 1;
    }
}
