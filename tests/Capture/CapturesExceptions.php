<?php

namespace TelescopeMonitor\Tests\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use RuntimeException;
use TelescopeMonitor\Capture\CaptureOperations;

trait CapturesExceptions
{
    protected function setUp(): void
    {
        parent::setUp();
        Telescope::startRecording(false);
    }

    protected function capture(string $message = 'token=private-value', ?string $uuid = null, ?CarbonImmutable $at = null, string $class = RuntimeException::class, bool $import = true): string
    {
        $exception = new RuntimeException($message);
        $entry = IncomingExceptionEntry::make($exception, [
            'class' => $class,
            'message' => $message,
            'file' => base_path('app/Services/Payment.php'),
            'line' => 42,
            'trace' => [['file' => base_path('app/Caller.php'), 'line' => 8, 'args' => ['password' => 'never-copy']]],
            'context' => ['password' => 'never-copy-context'],
            'user' => ['id' => 'opaque-123', 'email' => 'never-copy@example.test'],
        ]);
        $entry->uuid = $uuid ?? (string) Str::uuid();
        if ($at) {
            $entry->recordedAt = $at;
        }
        Telescope::recordException($entry);
        Telescope::store(app(EntriesRepository::class));
        if ($import) {
            // Explicitly simulate a separate importer pass after host execution.
            Telescope::withoutRecording(fn () => app(\TelescopeMonitor\Telescope\StoredExceptionAdapter::class)->replayRows(app(\TelescopeMonitor\Telescope\StoredEntries::class)->exceptionByUuid($entry->uuid), 'live'));
        }

        return $entry->uuid;
    }

    protected function importSource(int $limit = 100): array
    {
        return app(CaptureOperations::class)->import($limit);
    }

    protected function process(): array
    {
        return app(CaptureOperations::class)->process(100);
    }
}
