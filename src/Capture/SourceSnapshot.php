<?php

namespace TelescopeMonitor\Capture;

final class SourceSnapshot
{
    public function capture(): ?array
    {
        $release = config('exception-monitor.capture.release');
        $template = config('exception-monitor.capture.source_link_template');
        if (! is_string($release) || $release === '' || strlen($release) > 191) {
            return null;
        }
        $snapshot = ['version' => 1, 'release' => $release, 'template' => null];
        if (is_string($template) && strlen($template) <= 2048 && str_contains($template, '{release}') && str_contains($template, '{file}') && ! str_contains((string) parse_url($template, PHP_URL_HOST), '{') && $this->safe(str_replace(['{release}', '{file}', '{line}'], ['commit', 'file.php', '1'], $template))) {
            $snapshot['template'] = $template;
        }

        return $snapshot;
    }

    private function safe(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']);
    }

    public function link(?array $snapshot, string $file, int $line): ?string
    {
        if (! $snapshot || ! is_string($snapshot['template'] ?? null) || ! is_string($snapshot['release'] ?? null) || ($file === '' || $file === '[internal]' || str_starts_with($file, 'vendor/') || str_starts_with($file, '/')) || preg_match('~(^|/)\.\.(/|$)|^[A-Za-z]:|\\\\~', $file)) {
            return null;
        }
        $path = implode('/', array_map('rawurlencode', explode('/', $file)));
        $url = str_replace(['{release}', '{file}', '{line}'], [rawurlencode($snapshot['release']), $path, (string) max(0, $line)], $snapshot['template']);

        return $this->safe($url) ? $url : null;
    }
}
