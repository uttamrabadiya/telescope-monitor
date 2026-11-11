<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
if (! defined('TESTBENCH_WORKING_PATH')) {
    define('TESTBENCH_WORKING_PATH', __DIR__);
}
$app = require __DIR__.'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
