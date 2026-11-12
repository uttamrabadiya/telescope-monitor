<?php

namespace TelescopeMonitor\Capture;

use TelescopeMonitor\Services\RedactionService;

final class Sanitizer
{
    public function __construct(private RedactionService $redactor, private Fingerprint $fingerprint, private CaptureKeys $keys) {}

    public function text(mixed $value, array $policy, int $bytes = 16384): string
    {
        if (! is_scalar($value) && $value !== null) {
            return '[unavailable]';
        }
        $text = mb_convert_encoding(substr((string) $value, 0, $bytes + 4), 'UTF-8', 'UTF-8');
        $text = mb_strcut($text, 0, $bytes, 'UTF-8');
        $text = preg_replace('~\s*\((?:Connection:.*?,\s*)?SQL:.*$~s', ' [SQL omitted]', $text) ?? '[REDACTED]';

        return mb_strcut($this->redactor->text($text, $policy['privacy']['redact_patterns'] ?? []), 0, $bytes, 'UTF-8');
    }

    public function batch(array $entries, array $policy): array
    {
        $out = ['origin' => 'unknown', 'execution' => [], 'logs' => [], 'queries' => [], 'timeline' => [], 'coverage' => []];
        $count = 0;
        $bytes = 0;
        foreach ($entries as $entry) {
            if ($entry['page_has_more'] ?? false) {
                $out['coverage']['timeline'] = 'truncated';
            }
            $type = $entry['type'] ?? '';
            $c = $entry['content'] ?? [];
            if (! is_array($c) || ! in_array($type, ['request', 'job', 'command', 'log', 'query'], true)) {
                continue;
            }
            if ($count++ >= 100) {
                $out['coverage']['timeline'] = 'truncated';
                break;
            }
            if ($type === 'request') {
                $out['origin'] = 'web';
            } elseif ($out['origin'] === 'unknown' && in_array($type, ['job', 'command'], true)) {
                $out['origin'] = $type;
            }
            if ($type === 'request' && $policy['privacy']['retain_execution_context']) {
                $out['origin'] = 'web';
                $uri = is_string($c['uri'] ?? null) ? $c['uri'] : '';
                $out['execution'] = [
                    'method' => $this->text($c['method'] ?? '', $policy, 16),
                    'path' => $this->text(parse_url($uri, PHP_URL_PATH) ?: '', $policy, 2048),
                    'controller' => $this->text($c['controller_action'] ?? '', $policy, 191),
                    'status' => max(0, min(599, (int) ($c['response_status'] ?? 0))),
                ];
            } elseif ($type === 'job' && $out['origin'] === 'job' && ! $out['execution'] && $policy['privacy']['retain_execution_context']) {
                $out['origin'] = 'job';
                $out['execution'] = ['job_class' => $this->jobName($c['name'] ?? null)];
            } elseif ($type === 'command' && $out['origin'] === 'command' && ! $out['execution'] && $policy['privacy']['retain_execution_context']) {
                $out['origin'] = 'command';
                $out['execution'] = ['command' => $this->commandName($c['command'] ?? null)];
            }
            $event = null;
            if ($type === 'log' && $policy['privacy']['retain_related_logs'] && count($out['logs']) < 20) {
                $event = [
                    'type' => 'log',
                    'level' => $this->text($c['level'] ?? '', $policy, 16),
                    'message' => $this->text($c['message'] ?? '', $policy, 2048),
                ];
                $out['logs'][] = $event;
            } elseif ($type === 'query' && $policy['privacy']['retain_execution_context']) {
                $duration = is_numeric($c['time'] ?? null) && is_finite((float) $c['time']) ? max(0, (float) $c['time']) : null;
                $sql = ($policy['privacy']['retain_sql_templates'] ?? false)
                    ? app(SqlTemplate::class)->capture($c['sql'] ?? null, $c['driver'] ?? null, (int) config('telescope-monitor.capture.sql_max_bytes', 2048))
                    : ['sql_state' => 'disabled'];
                $event = ['type' => 'query', 'connection' => $this->text($c['connection'] ?? '', $policy, 64), 'duration_ms' => $duration] + $sql;
                if ($duration === null) {
                    unset($event['duration_ms']);
                }
                $event = $this->restrictQuery($event, $policy);
                $out['queries'][] = $event;
            } elseif (in_array($type, ['request', 'job', 'command'], true) && $policy['privacy']['retain_execution_context']) {
                $event = ['type' => $type] + match ($type) {
                    'request' => array_intersect_key($out['execution'], array_flip(['method', 'path', 'status'])),
                    'job' => ['job_class' => $this->jobName($c['name'] ?? null)],
                    'command' => ['command' => $this->commandName($c['command'] ?? null)],
                };
            }
            if ($event) {
                $size = strlen(json_encode($event, JSON_THROW_ON_ERROR));
                if ($bytes + $size <= 65536) {
                    $out['timeline'][] = $event;
                    $bytes += $size;
                } else {
                    $out['coverage']['timeline'] = 'truncated';
                }
            }
        }

        while (strlen(json_encode($out, JSON_THROW_ON_ERROR)) > 65536) {
            if ($out['timeline']) {
                array_pop($out['timeline']);
            } elseif ($out['logs']) {
                array_pop($out['logs']);
            } elseif ($out['queries']) {
                array_pop($out['queries']);
                $out['coverage']['queries'] = 'truncated';
            } else {
                break;
            }
            $out['coverage']['timeline'] = 'truncated';
        }

        return $out;
    }

    public function envelope(array $content, array $marker, array $batch, array $tags, ?array $trace = null): array
    {
        $policy = $marker['policy'];
        unset($marker['policy']);
        $origin = $content['telescope_monitor_origin'] ?? [];
        $kind = ($batch['origin'] ?? 'unknown') !== 'unknown' ? $batch['origin'] : (in_array($origin['kind'] ?? null, ['job', 'command', 'web'], true) ? $origin['kind'] : 'unknown');
        $coverage = [
            'trace' => $policy['privacy']['retain_stack_trace'] ? 'available' : 'excluded',
            'message' => strlen((string) ($content['message'] ?? '')) > 16384 ? 'truncated' : 'available',
            'execution' => $policy['privacy']['retain_execution_context'] ? 'unavailable' : 'excluded',
            'logs' => $policy['privacy']['retain_related_logs'] ? 'unavailable' : 'excluded',
            'user' => $policy['privacy']['identify_users'] ? 'unavailable' : 'excluded',
        ];
        $frames = [];
        $source = $trace ?? ($content['trace'] ?? []);
        if ($policy['privacy']['retain_stack_trace']) {
            if (! is_array($source)) {
                $source = [];
            }
            if (count($source) > 100) {
                $coverage['trace'] = 'truncated';
            }
            foreach (array_slice($source, 0, 100) as $frame) {
                if (is_array($frame)) {
                    $frames[] = [
                        'file' => $this->text($this->fingerprint->file((string) ($frame['file'] ?? '[internal]')), $policy, 2048),
                        'line' => max(0, (int) ($frame['line'] ?? 0)),
                        'class' => $this->text($frame['class'] ?? '', $policy, 512),
                        'function' => $this->text($frame['function'] ?? '', $policy, 191),
                    ];
                }
            }
        }
        $execution = $policy['privacy']['retain_execution_context'] ? ($batch['execution'] ?? []) : [];
        if ($execution) {
            $coverage['execution'] = 'available';
        }
        if ($policy['privacy']['retain_execution_context'] && $kind === 'job' && isset($origin['job_class'])) {
            $execution = ['job_class' => $this->jobName($origin['job_class'])];
            $coverage['execution'] = 'available';
        }
        if ($policy['privacy']['retain_execution_context'] && $kind === 'command' && isset($origin['command'])) {
            $execution = ['command' => $this->commandName($origin['command'])];
            $coverage['execution'] = 'available';
        }
        if ($policy['privacy']['retain_execution_context'] && is_array($content['telescope_monitor_route'] ?? null)) {
            foreach (['name' => 'route_name', 'template' => 'path_template'] as $source => $target) {
                if (is_string($content['telescope_monitor_route'][$source] ?? null)) {
                    $execution[$target] = $this->text($content['telescope_monitor_route'][$source], $policy, $target === 'route_name' ? 191 : 2048);
                }
            }
        }
        if ($policy['privacy']['retain_execution_context'] && isset($execution['path_template'])) {
            foreach ($batch['timeline'] as &$event) {
                if (($event['type'] ?? '') === 'request') {
                    $event['path'] = $execution['path_template'];
                }
            }
            unset($event);
        }
        $allowed = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) || str_starts_with($tag, 'tm2') || str_starts_with($tag, 'tm3|')) {
                continue;
            }
            [$key,$value] = array_pad(explode(':', $tag, 2), 2, '');
            if (in_array($key, config('telescope-monitor.capture.allowed_tags', []), true) && count($allowed) < 20) {
                $allowed[$this->text($key, $policy, 64)] = $this->text($value, $policy, 64);
            }
        }
        $impact = null;
        if ($policy['privacy']['identify_users'] && is_scalar($content['user']['id'] ?? null)) {
            $key = $this->keys->key('user_impact');
            $impact = ['id' => hash_hmac('sha256', (string) $content['user']['id'], $key['value']), 'key_id' => $key['id']];
            $coverage['user'] = 'pseudonymous';
        }
        $logs = $policy['privacy']['retain_related_logs'] ? ($batch['logs'] ?? []) : [];
        if ($logs) {
            $coverage['logs'] = 'available';
        }

        $envelope = [
            'version' => 1,
            'marker' => $marker,
            'origin' => $kind,
            'exception' => [
                'class' => $this->text($content['class'] ?? 'UnknownException', $policy, 512),
                'message' => $this->text($content['message'] ?? '', $policy),
                'file' => $this->text($this->fingerprint->file((string) ($content['file'] ?? 'unknown')), $policy, 2048),
                'line' => max(0, (int) ($content['line'] ?? 0)),
                'trace' => $frames,
            ],
            'execution' => $execution,
            'logs' => $logs,
            'queries' => $policy['privacy']['retain_execution_context'] ? ($batch['queries'] ?? []) : [],
            'timeline' => $batch['timeline'] ?? [],
            'tags' => $allowed,
            'impact' => $impact,
            'source' => is_array($content['telescope_monitor_source'] ?? null) ? $content['telescope_monitor_source'] : null,
            'coverage' => array_replace($coverage, $batch['coverage'] ?? []),
        ];

        return $this->fit($envelope);
    }

    public function restrict(array $envelope, array $policy): array
    {
        $e = $envelope['exception'];
        $e['class'] = $this->text($e['class'], $policy, 512);
        $e['message'] = $this->text($e['message'], $policy);
        $e['file'] = $this->text($e['file'], $policy, 2048);
        if (! $policy['privacy']['retain_stack_trace']) {
            $e['trace'] = [];
            $envelope['coverage']['trace'] = 'excluded';
        } else {
            foreach ($e['trace'] as &$frame) {
                foreach (['file', 'class', 'function'] as $key) {
                    $frame[$key] = $this->text($frame[$key] ?? '', $policy, $key === 'file' ? 2048 : 512);
                }
            }
        }
        unset($frame);
        $envelope['exception'] = $e;
        if (! $policy['privacy']['retain_execution_context']) {
            $envelope['execution'] = [];
            $envelope['queries'] = [];
            $envelope['coverage']['execution'] = 'excluded';
        } else {
            foreach ($envelope['execution'] as $key => $value) {
                if (is_string($value)) {
                    $envelope['execution'][$key] = match ($key) {
                        'command' => $this->commandName($value), 'job_class' => $this->jobName($value), default => $this->text($value, $policy, 2048)
                    };
                }
            }
        }
        if (! $policy['privacy']['retain_related_logs']) {
            $envelope['logs'] = [];
            $envelope['coverage']['logs'] = 'excluded';
        } else {
            foreach ($envelope['logs'] as &$log) {
                $log['message'] = $this->text($log['message'] ?? '', $policy, 2048);
            }
        }
        unset($log);
        if (! $policy['privacy']['identify_users']) {
            $envelope['impact'] = null;
            $envelope['coverage']['user'] = 'excluded';
        }
        $envelope['timeline'] = array_values(array_filter($envelope['timeline'], fn ($event) => ($event['type'] ?? '') === 'log' ? $policy['privacy']['retain_related_logs'] : $policy['privacy']['retain_execution_context']));
        foreach ($envelope['timeline'] as &$event) {
            if (($event['type'] ?? '') === 'query') {
                $event = $this->restrictQuery($event, $policy);
            }
            foreach ($event as $key => $value) {
                if (is_string($value) && $key !== 'sql_template') {
                    $event[$key] = match ($key) {
                        'command' => $this->commandName($value), 'job_class' => $this->jobName($value), default => $this->text($value, $policy, 2048)
                    };
                }
            }
        }
        unset($event);
        $envelope['tags'] = array_intersect_key($envelope['tags'], array_flip(config('telescope-monitor.capture.allowed_tags', [])));
        foreach ($envelope['queries'] as &$query) {
            $query = $this->restrictQuery($query, $policy);
            if (isset($query['connection'])) {
                $query['connection'] = $this->text($query['connection'], $policy, 64);
            }
        }
        unset($query);
        foreach ($envelope['tags'] as $key => $value) {
            $envelope['tags'][$key] = $this->text($value, $policy, 64);
        }

        return $this->fit($envelope);
    }

    private function jobName(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 512 && preg_match('/^\/?[A-Za-z_][A-Za-z0-9_]*(?:\/[A-Za-z_][A-Za-z0-9_]*)*$/D', str_replace(chr(92), '/', $value)) ? $value : '[unavailable]';
    }

    private function commandName(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,190}$/D', $value) ? $value : '[unavailable]';
    }

    private function restrictQuery(array $query, array $policy): array
    {
        if (! ($policy['privacy']['retain_sql_templates'] ?? false) || ! $policy['privacy']['retain_execution_context']) {
            $known = array_key_exists('sql_template', $query) || array_key_exists('sql_state', $query);
            unset($query['sql_template']);
            if ($known) {
                $query['sql_state'] = 'disabled';
            }
        } elseif (isset($query['sql_template'])) {
            $normalized = app(SqlTemplate::class)->capture($query['sql_template'], 'mysql', (int) config('telescope-monitor.capture.sql_max_bytes', 2048));
            unset($query['sql_template']);
            $query = array_replace($query, $normalized);
            if (isset($query['sql_template'])) {
                $redacted = $this->text($query['sql_template'], $policy, 4096);
                if ($redacted !== $query['sql_template']) {
                    unset($query['sql_template']);
                    $query['sql_state'] = 'unsupported';
                }
            }
        }

        return $query;
    }

    private function fit(array $envelope): array
    {
        while (strlen(json_encode($envelope, JSON_THROW_ON_ERROR)) > 131072) {
            if ($envelope['timeline']) {
                array_pop($envelope['timeline']);
                $envelope['coverage']['timeline'] = 'truncated';
            } elseif ($envelope['logs']) {
                array_pop($envelope['logs']);
                $envelope['coverage']['logs'] = 'truncated';
            } elseif ($envelope['queries']) {
                array_pop($envelope['queries']);
                $envelope['coverage']['queries'] = 'truncated';
            } elseif ($envelope['exception']['trace']) {
                array_pop($envelope['exception']['trace']);
                $envelope['coverage']['trace'] = 'truncated';
            } else {
                throw new CaptureFailure('envelope_too_large');
            }
        }

        return $envelope;
    }
}
