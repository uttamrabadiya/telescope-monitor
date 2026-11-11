<?php

namespace TelescopeMonitor\Capture;

final class Fingerprint
{
    public function file(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        foreach (array_unique([base_path(), realpath(base_path()) ?: base_path()]) as $base) {
            $base = rtrim(str_replace('\\', '/', $base), '/').'/';
            if (str_starts_with($file, $base)) {
                return substr($file, strlen($base));
            }
        }

        return $file;
    }

    public function hash(string $environment, array $content): string
    {
        $input = [
            'environment' => $environment,
            'class' => (string) ($content['class'] ?? 'UnknownException'),
            'file' => $this->file((string) ($content['file'] ?? 'unknown')),
            'line' => max(0, (int) ($content['line'] ?? 0)),
        ];
        $callback = config('exception-monitor.capture.grouping_callback');
        $value = is_callable($callback) ? $callback($input) : json_encode($input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($value) || strlen($value) > 8192) {
            throw new CaptureFailure('grouping_value_invalid');
        }

        return hash('sha256', $value);
    }
}
