<?php

namespace TelescopeMonitor\Tests\Fixtures;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class StrictRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
