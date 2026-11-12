<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Str;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Capture\Sanitizer;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;

final class EmailContext
{
    public function __construct(private CapturePolicy $policy, private Sanitizer $sanitizer, private EvidenceReader $evidence) {}

    public function build(?Issue $issue, ?Occurrence $occurrence, array $general, bool $includeEvidence): array
    {
        $prefix = strtoupper(substr(Str::slug($general['display_name']), 0, 32)) ?: 'APP';
        $context = [
            'version' => 1,
            'reference' => $prefix.($issue ? '-'.strtoupper(base_convert((string) $issue->id, 10, 36)) : '-TEST'),
            'timezone' => $general['timezone'] ?? 'UTC',
            'occurrence_id' => $occurrence?->public_id,
            'status' => $issue?->status,
            'first_seen_at' => $issue?->first_seen_at->toISOString(),
            'last_seen_at' => $issue?->last_seen_at->toISOString(),
            'snapshot_state' => 'unavailable',
            'execution' => [],
            'frames' => [],
            'omitted_frames' => 0,
            'tags' => [],
        ];
        if ($includeEvidence && $occurrence) {
            try {
                $retained = $this->evidence->read($occurrence->public_id);
                $context['snapshot_state'] = $retained['evidence_state'] ?? 'unavailable';
                $data = $retained['evidence'] ?? null;
                if (is_array($data)) {
                    $context['execution'] = array_intersect_key($data['execution'], array_flip(['method', 'path_template', 'route_name', 'job_class', 'command']));
                    $context['release'] = $data['source']['release'] ?? null;
                    $trace = $data['exception']['trace'];
                    $application = array_values(array_filter($trace, fn ($frame) => $frame['file'] !== '[internal]' && ! str_starts_with($frame['file'], 'vendor/') && ! str_contains($frame['file'], '/vendor/')));
                    $limit = max(0, min(12, (int) config('telescope-monitor.alerts.email.max_frames', 8)));
                    $context['frames'] = array_slice($application, 0, $limit);
                    $context['omitted_frames'] = count($trace) - count($context['frames']);
                    $context['tags'] = $data['tags'];
                }
            } catch (\Throwable) {
                // Additional evidence must not prevent an otherwise valid summary alert.
                $context['snapshot_state'] = 'unavailable';
            }
        }

        return $this->sanitize($context);
    }

    public function sanitize(array $context): array
    {
        $policy = $this->policy->read();
        $text = fn ($value, $max) => $this->sanitizer->text($value, $policy, $max);
        $out = array_intersect_key($context, array_flip([
            'version',
            'reference',
            'timezone',
            'occurrence_id',
            'status',
            'first_seen_at',
            'last_seen_at',
            'snapshot_state',
            'release',
            'execution',
            'frames',
            'omitted_frames',
            'tags',
        ]));
        foreach ([
            'reference' => 64,
            'timezone' => 64,
            'occurrence_id' => 64,
            'status' => 32,
            'first_seen_at' => 64,
            'last_seen_at' => 64,
            'release' => 191,
        ] as $key => $max) {
            if (isset($out[$key])) {
                $out[$key] = $text($out[$key], $max);
            }
        }
        $out['execution'] = [];
        $out['frames'] = [];
        $out['tags'] = [];
        $out['omitted_frames'] = max(0, (int) ($context['omitted_frames'] ?? 0));
        if (! config('telescope-monitor.alerts.email.include_context', true)) {
            $out['snapshot_state'] = 'excluded';
            $out['omitted_frames'] = 0;
            unset($out['release']);
            return $out;
        }
        if ($policy['privacy']['retain_execution_context']) {
            foreach (array_intersect_key($context['execution'] ?? [], array_flip(['method', 'path_template', 'route_name', 'job_class', 'command'])) as $key => $value) {
                $out['execution'][$key] = $text($value, $key === 'path_template' ? 1024 : 256);
            }
        }
        if ($policy['privacy']['retain_stack_trace'] && config('telescope-monitor.alerts.email.include_stack_trace', true)) {
            foreach (array_slice($context['frames'] ?? [], 0, max(0, min(12, (int) config('telescope-monitor.alerts.email.max_frames', 8)))) as $frame) {
                $out['frames'][] = [
                    'file' => $text($frame['file'] ?? '', 512),
                    'line' => max(0, (int) ($frame['line'] ?? 0)),
                    'class' => $text($frame['class'] ?? '', 256),
                    'function' => $text($frame['function'] ?? '', 191),
                ];
            }
            $out['omitted_frames'] += max(0, count($context['frames'] ?? []) - count($out['frames']));
        } else {
            $out['omitted_frames'] = 0;
        }
        $allowed = array_intersect(config('telescope-monitor.alerts.email.allowed_tags', []), config('telescope-monitor.capture.allowed_tags', []));
        foreach (array_slice(array_intersect_key($context['tags'] ?? [], array_flip($allowed)), 0, 8, true) as $key => $value) {
            $out['tags'][$text($key, 64)] = $text($value, 64);
        }

        return $out;
    }
}
