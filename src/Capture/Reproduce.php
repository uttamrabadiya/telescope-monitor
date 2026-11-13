<?php

namespace TelescopeMonitor\Capture;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Telescope\Watchers\RequestWatcher;
use Laravel\Telescope\Watchers\CommandWatcher;
use Symfony\Component\Console\Input\InputInterface;
use TelescopeMonitor\Services\RedactionService;

/** Bounded, deployment-only replay policy. Host capture must never access storage. */
final class Reproduce
{
    public const REDACTED = '[REDACTED]';

    private int $items = 0;

    private bool $truncated = false;

    public function __construct(private RedactionService $redactor) {}

    public function capture(Request $request): string
    {
        if (! config('telescope-monitor.reproduce.enabled', true) || ! $this->watcher(RequestWatcher::class)) {
            return $this->seal(['version' => 1, 'state' => 'excluded']);
        }
        $route = $request->route();
        if (! $route instanceof Route) {
            return $this->seal(['version' => 1, 'state' => 'unavailable']);
        }
        $selector = $route->getName() ?: '/'.ltrim($route->uri(), '/');
        $policy = $this->policy('http', $selector);
        $this->reset();
        $data = [
            'version' => 1,
            'state' => 'ready',
            'kind' => 'http',
            'selector' => $selector,
            'mode' => $policy['mode'],
            'method' => $request->method(),
            'origin' => $request->getSchemeAndHttpHost(),
            'route' => $request->getBaseUrl().'/'.ltrim($route->uri(), '/'),
            'parameters' => $this->values($route->originalParameters(), 'parameters', $policy),
            'query' => $this->values($request->query->all(), 'query', $policy),
            'headers' => [],
            'body' => [],
            'encoding' => 'none',
            'objects' => [],
            'omissions' => ['credentials'],
        ];
        foreach ($policy['headers'] as $name) {
            if (! is_string($name) || ! $this->headerAllowed($name) || ! preg_match('/^[a-z0-9-]{1,64}$/iD', $name)) {
                continue;
            }
            $value = $request->headers->get($name);
            if (is_string($value) && strlen($value) <= 512 && ! preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $name = strtolower($name);
                $data['headers'][$name] = in_array($name, ['accept', 'content-type'], true) ? $this->clean($value) : ($this->values([$name => $value], 'headers', $policy)[$name] ?? self::REDACTED);
            }
        }
        $type = strtolower(explode(';', $request->headers->get('content-type', ''))[0]);
        if ($request->isJson()) {
            $raw = $request->getContent();
            if (strlen($raw) <= $this->limit() * 4) {
                $body = json_decode($raw, true, 16);
                if (is_array($body)) {
                    $data['body'] = $this->values($body, 'body', $policy);
                    $data['encoding'] = 'json';
                    $data['objects'] = $this->objects(json_decode($raw));
                } elseif ($raw !== '') {
                    $data['omissions'][] = 'unsupported_body';
                }
            } else {
                $data['omissions'][] = 'body_limit';
            }
        } elseif ($type === 'application/x-www-form-urlencoded') {
            $data['body'] = $this->values($request->request->all(), 'body', $policy);
            $data['encoding'] = 'form';
        } elseif ($type !== '' && ($request->headers->get('content-length', '0') !== '0' || $type === 'multipart/form-data')) {
            $data['omissions'][] = 'unsupported_body';
        }
        return $this->seal($this->finish($data));
    }

    public function command(string $name, ?InputInterface $input): string
    {
        if (! config('telescope-monitor.reproduce.enabled', true) || ! $this->watcher(CommandWatcher::class)) {
            return $this->seal(['version' => 1, 'state' => 'excluded']);
        }
        if ($input === null) {
            return $this->seal(['version' => 1, 'state' => 'unavailable']);
        }
        $policy = $this->policy('command', $name);
        $this->reset();
        $arguments = $input->getArguments();
        unset($arguments['command']);
        $options = [];
        foreach ($input->getOptions() as $key => $value) {
            if ($input->hasParameterOption('--no-'.$key, true)) {
                $options['no-'.$key] = true;
            } elseif ($value !== false && $value !== null && $value !== []) {
                $options[$key] = $value;
            }
        }
        $safeOptions = $this->values($options, 'options', $policy);
        foreach ($options as $key => $value) {
            if ($value === true && array_key_exists($key, $safeOptions) && ! $this->sensitive((string) $key)) {
                $safeOptions[$key] = true;
            }
        }
        return $this->seal($this->finish([
            'version' => 1,
            'state' => 'ready',
            'kind' => 'command',
            'selector' => $name,
            'mode' => $policy['mode'],
            'arguments' => $this->values($arguments, 'arguments', $policy),
            'options' => $safeOptions,
            'omissions' => ['credentials'],
        ]));
    }

    public function unsupported(): string
    {
        return $this->seal(['version' => 1, 'state' => 'unsupported']);
    }

    public function import(mixed $ciphertext, array $privacy): array
    {
        try {
            if (! is_string($ciphertext) || strlen($ciphertext) > 131072) {
                return ['version' => 1, 'state' => 'unavailable'];
            }
            $data = json_decode(Crypt::decryptString($ciphertext), true, 24, JSON_THROW_ON_ERROR);
            if (! $this->valid($data)) {
                return ['version' => 1, 'state' => 'unavailable'];
            }
            return $this->restrict($data, $privacy);
        } catch (\Throwable) {
            return ['version' => 1, 'state' => 'unavailable'];
        }
    }

    public function restrict(array $data, array $privacy): array
    {
        if (! config('telescope-monitor.reproduce.enabled', true) || ! ($privacy['retain_execution_context'] ?? true)) {
            return ['version' => 1, 'state' => 'excluded'];
        }
        if (! $this->valid($data)) {
            return ['version' => 1, 'state' => 'unavailable'];
        }
        if ($data['state'] !== 'ready') {
            return $data;
        }
        $policy = $this->policy($data['kind'], $data['selector']);
        // Never promote a snapshot beyond its capture-time mode.
        $rank = ['redacted' => 0, 'selected-values' => 1, 'full-payload' => 2];
        if ($rank[$data['mode']] < $rank[$policy['mode']]) {
            $policy['mode'] = $data['mode'];
        }
        $data['mode'] = $policy['mode'];
        $this->reset();
        foreach (['parameters', 'query', 'body', 'arguments', 'options'] as $section) {
            if (isset($data[$section])) {
                $values = $data[$section];
                $data[$section] = $this->values($values, $section, $policy, $privacy['redact_patterns'] ?? []);
                if ($section === 'options') {
                    foreach ($values as $key => $value) {
                        if ($value === true && array_key_exists($key, $data[$section]) && ! $this->sensitive((string) $key)) {
                            $data[$section][$key] = true;
                        }
                    }
                }
            }
        }
        if (isset($data['headers'])) {
            $data['headers'] = array_filter($data['headers'], fn ($value, $key) => $this->headerAllowed($key) && in_array($key, $policy['headers'], true), ARRAY_FILTER_USE_BOTH);
            foreach ($data['headers'] as $key => &$value) {
                $value = in_array($key, ['accept', 'content-type'], true) ? $this->clean($value, $privacy['redact_patterns'] ?? []) : ($this->values([$key => $value], 'headers', $policy, $privacy['redact_patterns'] ?? [])[$key] ?? self::REDACTED);
            }
            unset($value);
        }
        foreach (['route'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->clean($data[$field], $privacy['redact_patterns'] ?? []);
            }
        }
        $data = $this->finish($data);
        return $this->valid($data) ? $data : ['version' => 1, 'state' => 'unavailable'];
    }

    public function valid(mixed $data): bool
    {
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! in_array($data['state'] ?? null, ['ready', 'excluded', 'unavailable', 'unsupported', 'too_large', 'restricted'], true) || strlen(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)) > 32768) {
            return false;
        }
        if ($data['state'] !== 'ready') {
            return ! array_diff(array_keys($data), ['version', 'state']);
        }
        if (! in_array($data['kind'] ?? null, ['http', 'command'], true) || ! is_string($data['selector'] ?? null) || strlen($data['selector']) > 2048 || ! in_array($data['mode'] ?? null, ['redacted', 'selected-values', 'full-payload'], true) || ! is_array($data['omissions'] ?? null) || array_diff($data['omissions'], ['credentials', 'unsupported_body', 'body_limit', 'field_limit'])) {
            return false;
        }
        $fields = $data['kind'] === 'http' ? ['origin', 'route', 'method', 'parameters', 'query', 'body', 'headers', 'encoding', 'objects'] : ['arguments', 'options'];
        if (array_diff(array_keys($data), array_merge(['version', 'state', 'kind', 'selector', 'mode', 'omissions'], $fields))) {
            return false;
        }
        foreach ($data['kind'] === 'http' ? ['parameters', 'query', 'body', 'headers'] : ['arguments', 'options'] as $section) {
            if (! is_array($data[$section] ?? null)) {
                return false;
            }
        }
        if ($data['kind'] === 'http') {
            if (isset($data['objects']) && (! is_array($data['objects']) || count($data['objects']) > 200 || collect($data['objects'])->contains(fn ($path) => ! is_string($path) || strlen($path) > 2048))) {
                return false;
            }
            $url = is_string($data['origin'] ?? null) ? parse_url($data['origin']) : false;
            if (! $url || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['path']) || ! is_string($data['route'] ?? null) || ! str_starts_with($data['route'], '/') || strlen($data['route']) > 2048 || ! is_string($data['method'] ?? null) || ! preg_match('/^[A-Z]{1,16}$/D', $data['method']) || ! in_array($data['encoding'] ?? null, ['none', 'json', 'form'], true)) {
                return false;
            }
            foreach ($data['headers'] as $key => $value) {
                if (! is_string($key) || ! is_string($value) || strlen($key) > 64 || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $key.$value)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function watcher(string $class): bool
    {
        $watcher = config('telescope.watchers.'.$class, false);
        return is_array($watcher) ? (bool) ($watcher['enabled'] ?? true) : (bool) $watcher;
    }

    private function policy(string $kind, string $selector): array
    {
        $all = config('telescope-monitor.reproduce', []);
        $entry = ($all[$kind === 'http' ? 'routes' : 'commands'] ?? [])[$selector] ?? [];
        $mode = is_array($entry) ? ($entry['mode'] ?? 'redacted') : 'redacted';
        return [
            'mode' => in_array($mode, ['redacted', 'selected-values', 'full-payload'], true) ? $mode : 'redacted',
            'fields' => is_array($entry['fields'] ?? null) ? $entry['fields'] : [],
            'headers' => array_values(array_filter(is_array($entry['headers'] ?? null) ? array_map('strtolower', array_values(array_filter($entry['headers'], 'is_string'))) : ['accept', 'content-type'], 'is_string')),
        ];
    }

    private function values(array $data, string $path, array $policy, array $patterns = [], int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (++$this->items > 200 || $depth > 6 || strlen((string) $key) > 128) {
                $this->truncated = true;
                break;
            }
            $field = $path.'.'.$key;
            $secret = $this->sensitive((string) $key) || $this->sensitive($field);
            if ($secret) {
                $out[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $out[$key] = $this->values($value, $field, $policy, $patterns, $depth + 1);
            } elseif (is_int($value) && abs($value) > 9007199254740991) {
                $out[$key] = self::REDACTED;
                $this->truncated = true;
            } elseif (is_scalar($value) || $value === null) {
                $keep = $policy['mode'] === 'full-payload' || ($policy['mode'] === 'selected-values' && collect($policy['fields'])->contains(fn ($pattern) => is_string($pattern) && Str::is($pattern, $field)));
                if (! $keep || (is_string($value) && strlen($value) > 2048)) {
                    $out[$key] = self::REDACTED;
                    if (is_string($value) && strlen($value) > 2048) {
                        $this->truncated = true;
                    }
                } else {
                    $out[$key] = is_string($value) ? $this->clean($value, $patterns) : $value;
                }
            } else {
                $out[$key] = '[OMITTED]';
                $this->truncated = true;
            }
        }
        return $out;
    }

    private function headerAllowed(string $name): bool
    {
        return ! $this->sensitive($name) && ! in_array(strtolower($name), [
            'host',
            'content-length',
            'transfer-encoding',
            'connection',
            'proxy-connection',
            'accept-encoding',
            'content-encoding',
        ], true);
    }

    private function sensitive(string $key): bool
    {
        $original = $key;
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));
        if (preg_match('/^(key|auth|pwd|pass|pin|jwt|bearer)$|auth$|authentication|password|passwd|passphrase|secret|token|authorization|cookie|session|apikey|credential|privatekey|accesskey|signature|csrf|xsrf|cvv|cvc|cardnumber|creditcard/', $key)) {
            return true;
        }
        foreach (config('telescope-monitor.reproduce.sensitive_fields', []) as $pattern) {
            if (is_string($pattern) && (Str::is($pattern, $original) || Str::is($pattern, $key))) {
                return true;
            }
        }
        return false;
    }

    private function clean(string $value, array $patterns = []): string
    {
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value) ?? self::REDACTED;
        $value = preg_replace('/\bBasic\s+[A-Za-z0-9+\/=]+/i', 'Basic [REDACTED]', $value) ?? self::REDACTED;
        return $this->redactor->text($value, $patterns);
    }

    private function objects(mixed $value, string $path = '', int $depth = 0): array
    {
        if ($depth > 6 || (! is_object($value) && ! is_array($value))) {
            return [];
        }
        $paths = is_object($value) ? [$path] : [];
        foreach ($value as $key => $child) {
            $pointer = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            $paths = array_merge($paths, $this->objects($child, $pointer, $depth + 1));
            if (count($paths) >= 200) {
                return array_slice($paths, 0, 200);
            }
        }
        return $paths;
    }

    private function reset(): void
    {
        $this->items = 0;
        $this->truncated = false;
    }

    private function finish(array $data): array
    {
        if ($this->truncated) {
            $data['omissions'][] = 'field_limit';
        }
        $data['omissions'] = array_values(array_unique($data['omissions']));
        return strlen(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)) > $this->limit() ? ['version' => 1, 'state' => 'too_large'] : $data;
    }

    private function limit(): int
    {
        return max(1024, min(32768, (int) config('telescope-monitor.reproduce.max_bytes', 8192)));
    }

    private function seal(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
