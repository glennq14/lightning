<?php

namespace Lightning\CLI;

use DateTime;
use Lightning\Tools\Configuration;
use Lightning\Tools\Logger;

// This is required for the signal handler.
declare(ticks = 1);

class Daemon extends CLI {

    /**
     * The maximum number of child threads.
     *
     * @var integer
     */
    protected $maxThreads = 5;

    /**
     * Whether to keep the daemon running.
     *
     * @var boolean
     */
    protected $keepAlive = true;

    /**
     * A list of jobs to run.
     *
     * @var array
     */
    protected $jobs = array();

    /**
     * A list of current running threads.
     *
     * @var array
     */
    protected $threads = array();

    /**
     * A queue for items that have died but not been tracked yet.
     *
     * @var array
     */
    protected $signalQueue = array();

    /**
     * The last time we checked for jobs.
     *
     * @var array
     */
    protected $lastCheck;

    /**
     * The timezone offset in seconds.
     *
     * @var integer
     */
    protected $timezoneOffset;

    /**
     * Initial start command from the terminal.
     */
    public function executeStart() {
        Logger::setLog(Configuration::get('daemon.log'));
        Logger::message('Starting Daemon');

        if ($this->getMyPid() != posix_getpid()) {
            $this->out('Already running.');
            return;
        }
        $this->out('Starting Daemon');

        // Get the timezone offset.
        $date = new DateTime();
        $this->timezoneOffset = $date->getOffset();

        $this->maxThreads = Configuration::get('daemon.max_threads');
        $this->jobs = Configuration::get('jobs');

        // Create initial fork.
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->out('Could not fork.');
            return;
        } else if ($pid) {
            // This is the parent thread.
            $status = null;
            pcntl_waitpid($pid, $status, WNOHANG);
            return;
        }

        // This is the child thread.
        pcntl_signal(SIGCHLD, array($this, 'handlerSIGCHLD'));
        pcntl_signal(SIGTERM, array($this, 'handlerSIGTERM'));
        $this->lastCheck = time();
        do {
            $this->checkForJobs();
            sleep(10);

            // TODO: add sigint and memory check.
        } while ($this->keepAlive);
    }

    /**
     * Command to send SIGTERM to the running daemon.
     */
    public function executeStop() {
        if ($pid = $this->getMyPid()) {
            $this->out('Stopping process: ' . $pid);
            posix_kill($pid, SIGTERM);
            do {
                sleep(1);
            } while ($this->getMyPid());
            $this->out('Stopped');
        } else {
            $this->out('Not running.');
        }
    }

    /**
     * Get the PID of the current running daemon process.
     *
     * @return integer
     *   The PID.
     */
    protected function getMyPid() {
        exec('ps -ef | grep ' . realpath(HOME_PATH . '/index.php'), $output);
        foreach ($output as $command) {
            if (preg_match('/daemon start/', $command)) {
                preg_match('/[0-9]+/', $command, $matches);
                return $matches[0];
            }
        }
        return null;
    }

    /**
     * Check to see if there are any jobs to fork.
     */
    protected function checkForJobs() {
        if (!$this->hasFreeThreads()) {
            // There are too many threads running already.
            return;
        }

        foreach ($this->jobs as &$job) {
            if (
                // If this was skipped last time.
                !empty($job['skipped'])
                // Or it's time to run again.
                || (
                    time() - $job['offset'] + $this->timezoneOffset) % $job['interval']
                    < (time() - $this->lastCheck
                )
            ) {
                $this->startJob($job);
            }
        }
        $this->lastCheck = time();
    }

    /**
     * Attempt to start child processes for a job.
     *
     * @param array $job
     *   The job that is timed to start.
     */
    protected function startJob(&$job) {
        // Make sure there are free threads.
        $max_threads = !empty($job['max_threads']) ? $job['max_threads'] : 1;

        // Make sure 'threads' is an array.
        if (empty($job['threads']) || !is_array($job['threads'])) {
            $job['threads'] = array();
        }

        $remainingThreads = $max_threads - count($job['threads']);
        $remainingThreads = min($remainingThreads, $this->maxThreads - count($this->threads));

        // If this job was skipped, we can start it up again next time regardless of
        // interval.
        if ($remainingThreads < 1) {
            $job['skipped'] = true;
            return;
        } else {
            $job['skipped'] = false;
        }

        // For each remaining thread we have, start one.
        for ($i = 0; $i < $remainingThreads; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->out('Could not fork.');
                return;
            } else if ($pid) {
                // This is the parent thread.
                $job['threads'][$pid] = $pid;
                $this->threads[$pid] = $pid;
                if (!empty($this->signalQueue[$pid])) {
                    // This will happen if the item died instantly.
                    $this->handlerSIGCHLD(SIGCHLD, $pid, $this->signalQueue[$pid]);
                    unset($this->signalQueue[$pid]);
                }
                // Continue looping.
            } else {
                // Execute the job.
                $object = new $job['class']();
                $object->execute($job);
                // Stop the daemon.
                exit;
            }
        }
    }

    /**
     * Check if the daemon has free child threads.
     *
     * @return boolean
     *   Whether the daemon has child threads available.
     */
    protected function hasFreeThreads() {
        foreach ($this->threads as $thread) {
            if (!file_exists('/proc/' . $thread)) {
                // Remove threads that are no longer running.
                unset($this->threads[$thread]);
            }
        }

        return count($this->threads) < $this->maxThreads;
    }

    /**
     * The handler for when a child process dies.
     *
     * @param $signo
     * @param null $pid
     * @param null $status
     * @return bool
     */
    protected function handlerSIGCHLD($signo, $pid=null, $status=null) {
        // If no pid is provided, Let's wait to figure out which child process ended
        if(!$pid){
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        // Get all exited children
        while($pid > 0){
            if($pid && isset($this->threads[$pid])){
                unset($this->threads[$pid]);
            }
            else if($pid){
                // Job finished before the parent process could record it as launched.
                // Store it to handle when the parent process is ready
                $this->signalQueue[$pid] = $status;
            }
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
        return true;
    }

    /**
     * The handler for receiving a SIGTERM signal.
     */
    protected function handlerSIGTERM() {
        $this->keepAlive = false;
    }
}
