<?php
/**
 * This file is part of the Loops framework.
 *
 * @author Lukas <lukas@loopsframework.com>
 * @license https://raw.githubusercontent.com/loopsframework/base/master/LICENSE
 * @link https://github.com/loopsframework/base
 * @link https://loopsframework.com/
 * @package jobs
 * @version 0.1
 */

namespace Loops\Application\LoopsAdmin;

use DateTime;
use Loops\Object;
use Loops\Misc;
use Loops\Exception;
use Loops\Application;
use Loops\Jobs\RecurringJobInterface;
use Loops\Annotations\Admin\Help;
use Loops\Annotations\Admin\Action;
use Loops\ArrayObject;
use Loops\Service\Redis as RedisService;
use Loops\Service\Resque as ResqueService;
use ReflectionClass;
use Resque;
use Resque_Worker;
use ResqueScheduler_Worker;
use UnexpectedValueException;

/**
 * @Help("Action and service scripts for the Loops jobs system. Loops jobs are powered by PHP Resque.")
 */
class Jobs extends Object {
    /**
     * @Action("Shows jobs that are defined in the application.")
     */
    public function show() {
        $jobs = $this->getJobs();

        $max = 0;
        $print = [];
        
        foreach($jobs as $name => $job) {
            $max = max(strlen($name), $max);
            if($job instanceof RecurringJobInterface) {
                $print[] = [ $name,  $job->nextExecutionTime() ?: "Disabled" ];
            }
            else {
                $print[] = [ $name,  "Non recurring job." ];
            }
        }
        
        $name = str_pad("Name", $max);
        $date = str_pad("Next Execution Time", 31);
        $eta  = "ETA";
        echo "$name  $date  $eta\n";
        
        foreach($print as list($name, $date)) {
            if($date instanceof DateTime) {
                $eta = (new DateTime)->diff($date)->format("%ad %hh %im %ss");
                $date = $date->format("r");
            }
            else {
                $eta = "-";
            }
            $name = str_pad($name, $max);
            $date = str_pad($date, 31);
            echo "$name  $date  $eta\n";
        }
        
        return 0;
    }
    
    /**
     * @Action("tba")
     */
    public function populate() {
        $jobs = array_filter($this->getJobs(), function($job) {
            return $job instanceof RecurringJobInterface;
        });
        
        $enqueued = 0;
        
        foreach($jobs as $job) {
            if(!$next = $job->nextExecutionTime()) {
                continue;
            }
            
            $queue = $job->getQueue();
            
            $scheduler = $this->getLoops()->getService("resque_scheduler");
            
            $class = get_class($job);
            
            //remove the job if it was already registered
            $scheduler->removeDelayedJobFromTimestamp($next, $queue, $class, []);
            
            error_log("Enqueing job '$class' in queue '$queue' for execution at '".$next->format("r")."'.");
            
            //register job
            $scheduler->enqueueAt($next, $queue, $class, []);
            
            $enqueued++;
        }
        
        error_log("A total number of $enqueued jobs were enqueued.");
        
        return 0;
    }

    public function init_enqueueFlags($flags) {
        $flags->string("queue", "default", "The queue where the job should be enqueued. [Default: default]");
        $flags->string("args", "[]", "JSON string that specifies the arguments that are going to be passed to the job. [Default: []]");
    }
    
    /**
     * @Action("tba",arguments="<job>",init_flags="init_enqueueFlags")
     */
    public function enqueue($queue, $args, $__arguments) {
        if(!$__arguments) {
            throw new Exception("Job name argument is missing.");
        }
        
        if(count($__arguments) > 1) {
            throw new Exception("Too many arguments.");
        }
        
        $job = array_shift($__arguments);
            
        if(substr($job, 0, 5) != "Jobs\\" && !class_exists($job)) {
            $job = "Jobs\\$job";
        }
        
        if(!class_exists($job)) {
            throw new Exception("Invalid job.");
        }
        
        if(is_string($args)) {
            $args = json_decode($args);
        }
        
        if(!is_array($args)) {
            throw new Exception("Arguments must be a valid JSON string describing an array.");
        }
        
        $this->resque->enqueue($queue, $job, $args);
        
        error_log("Enqueing job '$job' in queue '$queue'.");
        
        return 0;
    }
    
    private function getJobs() {
        $loops = $this->getLoops();
        
        $application = $loops->getService("application");
        
        $classnames = array_filter($application->definedClasses(), function($classname) {
            if(!class_exists($classname)) {
                return FALSE;
            }
            $reflection = new ReflectionClass($classname);
            if($reflection->isAbstract()) return FALSE;
            if(!$reflection->implementsInterface("Loops\Jobs\JobInterface")) return FALSE;
            return TRUE;
        });
        
        $jobs = [];
        
        foreach($classnames as $classname) {
            if(substr($classname, 0, 5) == "Jobs\\") {
                $name = substr($classname, 5);
            }
            $job = Misc::reflectionInstance($classname, [ "loops" => $loops ]);
            $jobs[$name] = $job;
        }
        
        return $jobs;
    }
    
    private $logging;
    private $verbose;
    
    public function init_resqueWorkerFlags($flags) {
        // Get config from Loops redis service
        $config = $this->getLoops()->getService("config");
        $redis_config = RedisService::getConfig($this->getLoops());
        $resque_config = ResqueService::getConfig($this->getLoops());
        $config = $config->offsetExists("resque_worker") ? $config->offsetGet("resque_worker") : new ArrayObject;
        
        // Set default values
        $redis_backend  = $config->offsetExists("redis_backend") ? $config->offsetGet("redis_backend") : (getenv("REDIS_BACKEND") ?: "{$redis_config->host}:{$redis_config->port}");
        $redis_database = $config->offsetExists("redis_database") ? $config->offsetGet("redis_database") : ($resque_config->offsetGet("database") ?: getenv("REDIS_DATABASE"));
        $count          = $config->offsetExists("count") ? $config->offsetGet("count") : (getenv("COUNT") ?: 1);
        $queue          = $config->offsetExists("queue") ? $config->offsetGet("queue") : (getenv("QUEUE") ?: "default");
        $interval       = $config->offsetExists("interval") ? $config->offsetGet("interval") : (getenv("INTERVAL") ?: 5);
        $logging        = $config->offsetExists("logging") ? $config->offsetGet("logging") : ((bool)(getenv("LOGGING") || getenv("VERBOSE") || getenv("VVERBOSE")));
        $verbose        = $config->offsetExists("verbose") ? $config->offsetGet("verbose") : ((bool)(getenv("VVERBOSE")));
        
        // Define options
        $flags->string("queue", $queue, "The queue(s) that should be processed. You can define multiple queues separated by comma. [Default: $queue]");
        $flags->string("redis-backend", $redis_backend, "Connection string for the redis backend. [Default: $redis_backend]");
        $flags->int("redis-database", $redis_database, "The Redis database to connect to. [Default: $redis_database]");
        $flags->int("interval", $interval, "The polling interval of the workers in seconds. [Default: $interval]");
        $flags->int("count", $count, "The number of workers that should be spawned. [Default: $count]");
        $flags->short("l", "Enable logging of workers.".($logging?" [Enabled by config]":""));
        $flags->short("v", "Use verbose logging of workers.".($verbose?" [Enabled by config]":""));
        
        //for the resqueWorker action
        $this->logging = $logging;
        $this->verbose = $verbose;
    }

    /**
     * @Action("This command will spawn Resque worker(s) that start to progress a job queue.

Note: Default values were set according to values from the Loops configuration and environment variables. You can override these values by passing the flags manually.",init_flags="init_resqueWorkerFlags")
     */
    public function resqueWorker($redis_backend, $redis_database, $count, $queue, $interval, $l, $v) {
        // no time limit for this script
        set_time_limit(0);
        
        // initialize Resque backend
        Resque::setBackend($redis_backend, $redis_database);
        
        // get logging
        $log_level = ($l || $this->logging) ? (($v || $this->verbose) ? Resque_Worker::LOG_VERBOSE : Resque_Worker::LOG_NORMAL) : 0;
        
        //start (other) workers
        for($i=1;$i<$count;$i++) {
            $this->startResqueWorker(explode(',', $queue), $interval, $log_level, $i-1);
        }
        
        //start worker in this process
        $this->startResqueWorker(explode(',', $queue), $interval, $log_level, $i-1, FALSE);
    }
    
    private function startResqueWorker($queues, $interval, $log_level, $name, $fork = TRUE) {
        if($fork) {
            if(!function_exists("pcntl_fork")) {
                throw new Exception("Your PHP does not support 'pcntl_fork'. Please set the worker count to 1.\n");
            }

            $pid = pcntl_fork();
            
            if($pid == -1) {
                throw new Exception("Could not fork worker $name.\n");
            }
            
            if($pid) {
                return;
            }
        }
        
        $worker = new Resque_Worker($queues);
        $worker->logLevel = $log_level;
        if($log_level) {
            fwrite(STDOUT, "*** Starting worker $name - processing queue(s) '".implode("', '", $queues)."' with an interval of {$interval}s.\n");
        }
        
        $worker->work($interval);
    }
    
    public function init_resqueSchedulerWorkerFlags($flags) {
        // Get config from Loops redis service
        $config = $this->getLoops()->getService("config");
        $redis_config = RedisService::getConfig($this->getLoops());
        $resque_config = ResqueService::getConfig($this->getLoops());
        $config = $config->offsetExists("resque_scheduler_worker") ? $config->offsetGet("resque_scheduler_worker") : new ArrayObject;
        
        // Set default values
        $redis_backend  = $config->offsetExists("redis_backend") ? $config->offsetGet("redis_backend") : (getenv("REDIS_BACKEND") ?: "{$redis_config->host}:{$redis_config->port}");
        $redis_database = $config->offsetExists("redis_database") ? $config->offsetGet("redis_database") : ($resque_config->offsetGet("database") ?: getenv("REDIS_DATABASE"));
        $interval       = $config->offsetExists("interval") ? $config->offsetGet("interval") : (getenv("INTERVAL") ?: 5);
        $logging        = $config->offsetExists("logging") ? $config->offsetGet("logging") : ((bool)(getenv("LOGGING") || getenv("VERBOSE") || getenv("VVERBOSE")));
        $verbose        = $config->offsetExists("verbose") ? $config->offsetGet("verbose") : ((bool)(getenv("VVERBOSE")));

        // Define options
        $flags->string("redis-backend", $redis_backend, "Connection string for the redis backend. [Default: $redis_backend]");
        $flags->int("redis-database", $redis_database, "The Redis database to connect to. [Default: $redis_database]");
        $flags->string("interval", $interval, "The polling interval of the workers in seconds. [Default: $interval]");
        $flags->short("l", "Enable logging of workers.".($logging?" [Enabled by config]":""));
        $flags->short("v", "Use verbose logging of workers.".($verbose?" [Enabled by config]":""));
        
        //for the resqueSchedulerWorker action
        $this->logging = $logging;
        $this->verbose = $verbose;
    }
    
    /**
     * @Action("This command will check for delayed jobs and queue them at their scheduled time. They will then be processed by the resque worker(s).

Note: Default values were set according to values from the Loops configuration and environment variables. You can override these values by passing flags manually.",init_flags="init_resqueSchedulerWorkerFlags")
     */
    public function resqueSchedulerWorker($redis_backend, $redis_database, $interval, $l, $v) {
        // no time limit for this script
        set_time_limit(0);
        
        // initialize Resque backend
        Resque::setBackend($redis_backend, $redis_database);
        
        // (re-)register recurring jobs
        $this->populate();
        
        // get logging
        $log_level = ($l || $this->logging) ? (($v || $this->verbose) ? ResqueScheduler_Worker::LOG_VERBOSE : ResqueScheduler_Worker::LOG_NORMAL) : 0;

        // start worker
        $worker = new ResqueScheduler_Worker($queues);
        $worker->logLevel = $log_level;
        if($log_level) {
            fwrite(STDOUT, "*** Starting scheduler worker with an interval of {$interval}s.\n");
        }
        
        $worker->work($interval);
    }
}