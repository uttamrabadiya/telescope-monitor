<?php

namespace TelescopeMonitor\Tests\Fixtures;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

final class StrictCsrf extends VerifyCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
