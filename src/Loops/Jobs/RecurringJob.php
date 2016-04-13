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

namespace Loops\Jobs;

use Loops\Object;
use Loops\Exception;

abstract class RecurringJob extends Job implements RecurringJobInterface {
    protected $resque_queue = "default";
    
    public function getQueue() {
        return $this->resque_queue;
    }
    
    public function perform() {
        try {
            parent::perform();
        }
        catch(Exception $e) {
            throw $e;
        }
        finally {
            if($next = $this->nextExecutionTime()) {
                $queue = $this->getQueue();
                $scheduler = $this->getLoops()->getService("resque_scheduler");
                
                //remove the job if it was already registered
                $scheduler->removeDelayedJobFromTimestamp($next, $queue, get_class($this), []);
                
                //register job
                $scheduler->enqueueAt($next, $queue, get_class($this), []);
            }
        }
    }
}