<?php

namespace TelescopeMonitor\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

trait UsesUtcTimestamps
{
    public function freshTimestamp()
    {
        return Carbon::now('UTC');
    }

    protected function asDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d\d-\d\d(?: \d\d:\d\d:\d\d(?:\.\d{1,6})?)?$/D', $value)) {
            return Carbon::parse($value, 'UTC');
        }

        return parent::asDateTime($value)->utc();
    }

    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->format($this->getDateFormat());
    }
}
