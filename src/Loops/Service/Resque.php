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

namespace Loops\Service;

use Loops;
use Loops\Service;

class Resque extends Service {
    public static $default_config = [ "database" => 2 ];
    
    public function __construct($database, Loops $loops = NULL) {
        parent::__construct($loops);
        
        // Get config from Loops redis service
        $config = Redis::getConfig($this->getLoops());
        
        // Set Backend information
        \Resque::setBackend("{$config->host}:{$config->port}", (int)$database);
    }
    
    public function __call($name, $arguments) {
        if($name == "enqueue" && !empty($arguments[1])) {
            $arguments[1] = static::adjustJobClass($arguments[1]);
        }
        
        return call_user_func_array([ 'Resque', $name ], $arguments);
    }
    
    private static function adjustJobClass($classname) {
        if(class_exists($classname)) {
            return $classname;
        }
        
        $jobs_classname = "Jobs\\".$classname;
        
        return class_exists($jobs_classname) ? $jobs_classname : $classname;
    }
}