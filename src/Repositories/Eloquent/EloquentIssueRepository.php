<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Illuminate\Support\Str;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Repositories\Contracts\IssueRepository;

final class EloquentIssueRepository implements IssueRepository
{
    public function forCapture(array $data): Issue
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s.u');
            }
        }
        Issue::query()->insertOrIgnore([array_merge($data, [
            'public_id' => (string) Str::uuid(),
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ])]);

        return Issue::query()->where('installation_id', $data['installation_id'])->where('environment', $data['environment'])->where('is_test', $data['is_test'])->where('grouping_version', $data['grouping_version'])->where('fingerprint_hash', $data['fingerprint_hash'])->lockForUpdate()->firstOrFail();
    }

    public function save(Issue $issue): void
    {
        $issue->save();
    }
}
