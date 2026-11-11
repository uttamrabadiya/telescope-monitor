<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Illuminate\Support\Str;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class EloquentInstallationRepository implements InstallationRepository
{
    public function storageState(): string
    {
        if (! Database::schema()->hasTable('exception_monitor_installations')) {
            return Database::schema()->hasTable('exception_monitor_issues') ? 'prototype_detected' : 'missing_migrations';
        }
        foreach (['settings', 'health_checks', 'action_receipts'] as $suffix) {
            if (! Database::schema()->hasTable('exception_monitor_'.$suffix)) {
                return 'missing_migrations';
            }
        }

        return 'ready';
    }

    private function schemaVersion(): int
    {
        foreach (['purge_runs' => 6, 'linked_issues' => 5, 'deliveries' => 4, 'notes' => 3, 'capture_inbox' => 2] as $table => $version) {
            if (Database::schema()->hasTable('exception_monitor_'.$table)) {
                return $version;
            }
        }

        return 1;
    }

    public function current(): Installation
    {
        $existing = Installation::query()->where('singleton_key', 'default')->first();
        if ($existing) {
            return $existing;
        }

        return Installation::query()->createOrFirst(['singleton_key' => 'default'], ['public_id' => (string) Str::uuid(), 'source_epoch' => (string) Str::uuid(), 'schema_version' => $this->schemaVersion(), 'setup_state' => []]);
    }

    public function lock(): Installation
    {
        $this->current();

        return Installation::query()->where('singleton_key', 'default')->lockForUpdate()->firstOrFail();
    }

    public function fence(): Installation
    {
        return Installation::query()->where('singleton_key', 'default')->sharedLock()->firstOrFail();
    }

    public function verifySource(string $signature): void
    {
        $installation = $this->current();
        if ($installation->source_signature === null) {
            Database::connection()->transaction(function () use ($signature) {
                $installation = $this->lock();
                if ($installation->source_signature === null) {
                    $installation->source_signature = $signature;
                    $installation->save();
                } elseif (! hash_equals($installation->source_signature, $signature)) {
                    throw new CaptureFailure('source_epoch_review_required');
                }
            }, 5);
        } elseif (! hash_equals($installation->source_signature, $signature)) {
            throw new CaptureFailure('source_epoch_review_required');
        }
    }

    public function checkpoint(Installation $installation, int $sequence): void
    {
        $installation->source_sequence_checkpoint = max((int) $installation->source_sequence_checkpoint, $sequence);
        $installation->save();
    }

    public function advanceConfiguration(Installation $installation): void
    {
        $installation->config_revision++;
        $installation->save();
    }

    public function saveSetup(Installation $installation, array $state): void
    {
        $installation->setup_state = $state;
        $installation->save();
    }
}
