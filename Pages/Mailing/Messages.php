<?php
/**
 * @file
 * Lightning\Pages\Mailing\Messages
 */

namespace Lightning\Pages\Mailing;

use Lightning\Pages\Table;
use Lightning\Tools\ClientUser;
use Lightning\Tools\Database;
use Lightning\Tools\Output;
use Lightning\Tools\Request;
use Lightning\View\JS;

/**
 * A page handler for editing bulk mailer messages.
 *
 * @package Lightning\Pages\Mailing
 */
class Messages extends Table {
    /**
     * Require admin privileges.
     */
    public function __construct() {
        ClientUser::requireAdmin();
        parent::__construct();

        $action = Request::get('action');
        if ($action == 'edit' || $action == 'new') {
            JS::startup('
                lightning.admin.messageEditor.checkVars();
                $("#add_message_criteria_button").click(lightning.admin.messageEditor.checkVars);
            ');
        }

        $this->post_actions['after_post'] = function() {
            $db = Database::getInstance();
            // Find all the criteria added to this message
            $criteria_list = $db->select('message_message_criteria', array('message_id' => $this->id));
            foreach($criteria_list as $c){
                // Find the required fields
                $f = $db->selectRow('message_criteria', array('message_criteria_id' => $c['message_criteria_id']));
                // If the criteria requires variables.
                if($f['variables'] != ''){
                    // See what variables are required.
                    $vars = explode(',', $f['variables']);
                    $var_data = array();
                    foreach($vars as $v) {
                        $var_data[$v] = Request::post('var_' . $c['message_criteria_id'] . '_' . $v);
                    }
                    $db->update(
                        'message_message_criteria',
                        array('field_values' => json_encode($var_data)),
                        array(
                            'message_id' => Request::post('id', 'int'),
                            'message_criteria_id' => $c['message_criteria_id'],
                        )
                    );
                }
            }
        };
    }

    protected $table = 'message';
    protected $preset = array(
        'never_resend' => array(
            'type' => 'checkbox',
        ),
        'template_id' => array(
            'type' => 'lookup',
            'item_display_name' => 'title',
            'lookuptable'=>'message_template',
            'display_column'=>'title',
            'edit_only'=>true
        ),
        'send_date' => array(
            'type' => 'datetime',
            'allow_blank' => true,
        ),
        'list_status' => array(
            'type' => 'select',
            'edit_only' => true,
            'options' => array(
                0 => 'Nobody',
                1 => 'Mailing List: Non Members',
                2 => 'Mailing List: All',
                3 => 'Mailing List: Members Only',
                4 => 'All Registered (incl unsubscribed)',
                5 => 'Everybody (incl unsubscribed DANGEROUS)',
                6 => 'Temp List (experimental)',
            )
        ),
        'body' => array(
            'type' => 'html',
            'editor' => 'full',
            'upload' => true,
        )
    );

    protected $links = array(
        'message_criteria' => array(
            'list' => true,
            'index' => 'message_message_criteria',
            'key' => 'message_criteria_id',
            'option_name' => 'criteria_name',
            'display_name' => 'Criteria',
            'display_column' => 'criteria_name',
            'function' => 'update_field_values'
        ),
        'message_list' => array(
            'list' => true,
            'index' => 'message_message_list',
            'key' => 'message_list_id',
            'option_name' => 'name',
            'display_name' => 'Lists',
            'display_column' => 'name',
        ),
    );

    protected $action_fields = array(
        'send' => array(
            'type' => 'link',
            'url' => '/admin/mailing/send?id=',
            'display_value' => '<img src="/images/main/new_message.png" border="0">',
        ),
    );

    protected $sort = 'message_id DESC';
    protected $maxPerPage = 100;


    public function getFields() {
        // TODO: REQUIRE ADMIN
        $cl = Request::get('criteria_list', 'explode', 'int');
        $output = array();
        if (!empty($cl)) {
            $fields = Database::getInstance()->select('message_criteria', array('message_criteria_id' => array('IN', $cl)));
            foreach($fields as $f){
                if(!empty($f['variables'])){
                    $values = Database::getInstance()->selectRow(
                        'message_message_criteria',
                        array(
                            'message_id' => Request::get('message_id', 'int'),
                            'message_criteria_id' => $f['message_criteria_id'],
                        )
                    );
                    $output[] = array(
                        'criteria_id' => $f['message_criteria_id'],
                        'variables' => explode(',',$f['variables']),
                        'values' => json_decode($values['field_values']),
                    );
                }
            }
        }

        Output::json(array('criteria' => $output));
    }
}
