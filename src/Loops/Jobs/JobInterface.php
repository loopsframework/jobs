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

interface JobInterface {
    public function setUp();
    public function perform();
    public function tearDown();
}