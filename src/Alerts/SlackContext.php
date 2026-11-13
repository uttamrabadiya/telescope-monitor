<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Str;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Capture\Sanitizer;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;

final class SlackContext
{
    public function __construct(private CapturePolicy $policy, private Sanitizer $sanitizer, private EvidenceReader $evidence) {}

    public function build(?Issue $issue, ?Occurrence $occurrence, array $general, bool $includeEvidence): array
    {
        $prefix = strtoupper(substr(Str::slug($general['display_name']), 0, 32)) ?: 'APP';
        $context = [
            'reference' => $prefix.($issue ? '-'.strtoupper(base_convert((string) $issue->id, 10, 36)) : '-TEST'),
            'timezone' => $general['timezone'] ?? 'UTC',
            'first_seen_at' => $issue?->first_seen_at->toISOString(),
            'last_seen_at' => $issue?->last_seen_at->toISOString(),
            'execution' => [],
        ];
        if ($includeEvidence && $occurrence) {
            try {
                $retained = $this->evidence->read($occurrence->public_id);
                $data = $retained['evidence'] ?? null;
                if (is_array($data)) {
                    $context['execution'] = $data['execution'] ?? [];
                    $context['release'] = $data['source']['release'] ?? null;
                }
            } catch (\Throwable) {
                // Missing or expired evidence must not prevent a summary alert.
            }
        }

        return $this->sanitize($context);
    }

    public function sanitize(array $context): array
    {
        $policy = $this->policy->read();
        $out = [];
        foreach (['reference' => 64, 'timezone' => 64, 'first_seen_at' => 64, 'last_seen_at' => 64, 'release' => 191] as $key => $max) {
            if (isset($context[$key])) {
                $out[$key] = $this->sanitizer->text($context[$key], $policy, $max);
            }
        }
        $out['execution'] = [];
        if ($policy['privacy']['retain_execution_context']) {
            foreach (array_intersect_key($context['execution'] ?? [], array_flip(['method', 'path_template', 'route_name', 'job_class', 'command'])) as $key => $value) {
                $out['execution'][$key] = $this->sanitizer->text($value, $policy, $key === 'path_template' ? 1024 : 256);
            }
        }

        return $out;
    }
}
