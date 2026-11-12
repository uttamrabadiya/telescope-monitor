<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\MetricBucket;
use TelescopeMonitor\Repositories\Contracts\MetricBucketRepository;

final class EloquentMetricBucketRepository implements MetricBucketRepository
{
    public function record(Issue $issue, CarbonImmutable $time, bool $first, bool $regression, ?CarbonImmutable $previousFirst, int $days): void
    {
        foreach (['5m' => [300, now('UTC')->subHours(48)], '1h' => [3600, now('UTC')->subDays($days)]] as $resolution => [$seconds,$floor]) {
            $start = $this->bucket($time, $seconds);
            if ($start >= $floor) {
                $row = $this->row($issue, $resolution, $start, $resolution === '5m' ? $start->addHours(48) : $start->addDays($days));
                $row->occurrence_count++;
                if ($first) {
                    $row->new_group_count++;
                }
                if ($regression) {
                    $row->regression_count++;
                }
                $row->save();
            }
            if (! $first && $previousFirst && $issue->first_seen_at < $previousFirst) {
                $old = $this->bucket($previousFirst, $seconds);
                $new = $this->bucket($issue->first_seen_at, $seconds);
                if ($old->equalTo($new)) {
                    continue;
                }
                $existing = MetricBucket::query()->where('issue_id', $issue->id)->where('resolution', $resolution)->where('bucket_start_at', $old->format('Y-m-d H:i:s.u'))->lockForUpdate()->first();
                if ($existing && $existing->new_group_count > 0) {
                    $existing->new_group_count--;
                    $existing->save();
                }
                if ($new >= $floor) {
                    $newRow = $this->row($issue, $resolution, $new, $resolution === '5m' ? $new->addHours(48) : $new->addDays($days));
                    $newRow->new_group_count++;
                    $newRow->save();
                }
            }
        }
    }

    private function bucket(CarbonImmutable $time, int $seconds): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC((int) (floor($time->getTimestamp() / $seconds) * $seconds));
    }

    private function row(Issue $issue, string $resolution, CarbonImmutable $start, CarbonImmutable $expires): MetricBucket
    {
        // The issue lock serializes writers, but policy reads may already have
        // opened an older repeatable-read snapshot. Read the current bucket.
        return MetricBucket::query()->lockForUpdate()->firstOrCreate([
            'issue_id' => $issue->id,
            'resolution' => $resolution,
            'bucket_start_at' => $start->format('Y-m-d H:i:s.u'),
        ], [
            'installation_id' => $issue->installation_id,
            'expires_at' => $expires,
        ]);
    }

    public function prune(int $limit): int
    {
        $ids = MetricBucket::query()->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('id');

        return MetricBucket::query()->whereKey($ids)->delete();
    }
}
