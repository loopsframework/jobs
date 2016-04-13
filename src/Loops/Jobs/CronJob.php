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

use Cron\CronExpression;
use Loops\Exception;

/**
 * A job with the execution time beeing configured by using cron syntax
 *
 * Just set the public static property $cron to the 5 parameter cron syntax (string or array). During debugging, public
 * static property $disabled can be set to TRUE to stop running the job by the scheduler.
 * It is also possible to use the aliases '@yearly','@annually','@monthly','@weekly','@daily','@hourly'.
 * 
 * <code>
 *     namespace Jobs;
 * 
 *     use Loops\Jobs\CronJob;
 * 
 *     class ExampleJob extends CronJob {
 *         public static $cron = "0,15,30,45 * * * *";
 *     
 *         public function execute($args) {
 *             echo "Print this every 15 minutes."
 *         }
 *     }
 * </code>
 */
abstract class CronJob extends RecurringJob {
    /**
     * @var bool Specifies if this Job should be disabled
     */
    protected static $disabled = FALSE;
    
    /**
     * @var string Can be set to override the timezone used for calculation (defaults to the default timezone)
     */
    protected static $timezone;
    
    /**
     * @var string The 5 parameter cron expression or an alias (see class doc)
     */
    protected static $cron;
    
    public function nextExecutionTime() {
        if(static::$cron === NULL) {
            throw new Exception("CronJob: Please declare static property \$cron in class '".get_class($this)."'.");
        }
        
        if(static::$disabled) {
            return FALSE;
        }
        
        if(static::$timezone) {
            $tz = date_default_timezone_get();
            date_default_timezone_set(static::$timezone);
        }
        
        $result = CronExpression::factory(implode(" ", (array)static::$cron))->getNextRunDate();

        if(static::$timezone) {
            date_default_timezone_set($tz);
        }
        
        return $result;
    }
}