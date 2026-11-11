<?php

namespace TelescopeMonitor\Foundation;

use RuntimeException;

final class DashboardAssets
{
    public function manifest(): array
    {
        $path = __DIR__.'/../../dist/manifest.json';
        if (! is_file($path)) {
            throw new RuntimeException('Dashboard assets are not built. Use a release containing dist assets.');
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function build(): string
    {
        return substr(hash('sha256', json_encode($this->manifest(), JSON_THROW_ON_ERROR)), 0, 16);
    }

    public function entry(): array
    {
        return $this->manifest()['resources/js/main.ts'];
    }

    public function allowed(): array
    {
        $files = [];
        foreach ($this->manifest() as $entry) {
            $files[] = $entry['file'];
            array_push($files, ...($entry['css'] ?? []), ...($entry['assets'] ?? []));
        }

        return array_unique($files);
    }

    public function resolve(string $build, string $file): ?string
    {
        if ($build !== $this->build() || ! in_array($file, $this->allowed(), true)) {
            return null;
        }
        $base = realpath(__DIR__.'/../../dist');
        $path = realpath(__DIR__.'/../../dist/'.$file);

        return $path && $base && str_starts_with($path, $base.DIRECTORY_SEPARATOR) ? $path : null;
    }
}
