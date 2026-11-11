<?php

namespace TelescopeMonitor\Services;

use Illuminate\Support\Facades\Artisan;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class InstallationService
{
    public function __construct(private InstallationRepository $installations) {}

    public function publish(): bool
    {
        if ($this->installations->storageState() === 'prototype_detected') {
            return false;
        }
        Artisan::call('vendor:publish', [
            '--tag' => 'exception-monitor-config',
        ]);
        $directory = database_path('migrations').(Database::connectionName() === config('database.default') ? '' : '/exception-monitor');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $source) {
            $suffix = substr(basename($source), 18);
            if ($this->published($suffix)) {
                continue;
            }
            $destination = $directory.'/'.basename($source);
            $handle = @fopen($destination, 'x');
            if ($handle) {
                fwrite($handle, file_get_contents($source));
                fclose($handle);
            }
        }

        return true;
    }

    /** @return list<string> */
    public function migrationPaths(): array
    {
        $paths = [];
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $source) {
            $published = $this->published(substr(basename($source), 18));
            if (count($published) !== 1) {
                throw new \RuntimeException('Publish exactly one copy of each monitoring migration using exception-monitor:install.');
            }
            $paths[] = $published[0];
        }

        return $paths;
    }

    /** @return list<string> */
    private function published(string $suffix): array
    {
        return array_merge(glob(database_path('migrations').'/*'.$suffix), glob(database_path('migrations/exception-monitor').'/*'.$suffix));
    }
}
