<?php

namespace TelescopeMonitor\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;

final class StorageProbeProvider extends ServiceProvider
{
    public static array $observations = [];

    public function register(): void
    {
        Telescope::afterStoring(function (array $entries, string $batch): bool {
            foreach ($entries as $entry) {
                self::$observations[] = [
                    'type' => $entry->type,
                    'uuid' => $entry->uuid,
                    'batch' => $batch,
                    'microseconds' => $entry->recordedAt->format('u'),
                    'content_type' => get_debug_type($entry->content),
                ];
            }

            return true;
        });
    }
}
