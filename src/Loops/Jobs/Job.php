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

/**
 * A job for Loops\Jobs\Scheduler from which all other Jobs have to inherit
 * 
 * This class is aware of phalcons dependency injector which is set on default by the scheduler.
 * Easy access to phalcons services via magic __get is implemented.
 *
 * <code>
 * ...
 *     public function execute() {
 *         echo $this->config->paths->apppath;
 *         echo $this->url->getBaseURI();
 *     }
 * ...
 * </code>
 */
abstract class Job extends Object implements JobInterface {
    public $queue;
    
    public $args = [];
    
    /**
     * This method contains the code to be executed.
     */
    abstract public function execute($args);
    
    public function setUp() {
    }
    
    public function perform() {
        $handler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
            throw new Exception("PHP Error: $errstr in $errfile on line $errline");
        }, E_USER_ERROR | E_RECOVERABLE_ERROR );
                
        try {
            $result = $this->execute($this->args);
        }
        catch(Exception $e) {
            if(!$this->exception($e)) {
                throw $e;
            }
        }
        finally {
            set_error_handler($handler);
        }

        return $result;
    }
    
    public function tearDown() {
    }
    
    /**
     * Will be called in case the execute method failed with an exception.
     *
     * This method makes exception handling easier by eliminating the need to wrap the code in execute
     * in a try catch block. Override for use.
     *
     * @param Exception The thrown exception from the execute method.
     * @return bool TRUE if the exception has been handled. FALSE if resque should handle the exception
     */
    public function exception(Exception $e) {
        return FALSE;
    }
}