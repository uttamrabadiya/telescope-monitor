<?php

namespace TelescopeMonitor\Investigation;

use Carbon\CarbonImmutable;

final class Window
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public int $step;

    public string $resolution;

    public function __construct(public string $range = '24h')
    {
        [$seconds,$buckets] = $this->rangeSpec($range);
        $this->step = $seconds;
        $this->resolution = $range === '1h' ? '5m' : '1h';
        $this->end = CarbonImmutable::createFromTimestampUTC((int) (floor(CarbonImmutable::now('UTC')->timestamp / $seconds) * $seconds))->addSeconds($seconds);
        $this->start = $this->end->subSeconds($seconds * $buckets);
    }

    private function rangeSpec(string $range): array
    {
        return match ($range) {
            '1h' => [300, 12],'7d' => [21600, 28],'30d' => [86400, 30],default => [3600, 24]
        };
    }

    public function json(): array
    {
        return ['range' => $this->range, 'start' => $this->start->toISOString(), 'end' => $this->end->toISOString(), 'bucket_seconds' => $this->step, 'as_of' => CarbonImmutable::now('UTC')->toISOString(), 'coverage' => 'Captured events only. The current bucket is partial; aggregates follow configured retention.'];
    }

    public function sql(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
