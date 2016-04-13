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

class ResqueScheduler extends Service {
    public function __construct(Loops $loops = NULL) {
        parent::__construct($loops);
        
        // Initialize resque config
        $this->getLoops()->getService("resque");
    }
    
    public function __call($name, $arguments) {
        return call_user_func_array([ 'ResqueScheduler', $name ], $arguments);
    }
}