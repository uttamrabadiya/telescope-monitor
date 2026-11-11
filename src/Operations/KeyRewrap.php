<?php

namespace TelescopeMonitor\Operations;

use Illuminate\Support\Facades\Crypt;
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class KeyRewrap
{
    public const TARGETS = ['secrets' => ['secrets', 'ciphertext'], 'inbox' => ['capture_inbox', 'payload_ciphertext'], 'evidence' => ['occurrence_evidence', 'payload_ciphertext'], 'events' => ['alert_events', 'payload_ciphertext'], 'deliveries' => ['deliveries', 'payload_ciphertext'], 'notes' => ['notes', 'body_ciphertext']];

    public function __construct(private InstallationRepository $installations, private OperationJournal $journal) {}

    public function run(string $installation, string $confirm, string $target, int $limit = 100, int $after = 0): array
    {
        $i = $this->installations->current();
        if (! hash_equals($i->public_id, $installation) || ! hash_equals($installation, $confirm)) {
            throw new MaintenanceFailure('installation_confirmation_required');
        }if (! isset(self::TARGETS[$target]) || $limit < 1 || $limit > 1000 || $after < 0) {
            throw new MaintenanceFailure('invalid_rewrap_target_or_limit');
        }

        return $this->journal->run('key_rewrap', function () use ($i, $target, $limit, $after) {
            [$suffix,$field] = self::TARGETS[$target];
            $q = fn () => Database::connection()->table('exception_monitor_'.$suffix)->where('installation_id', $i->id)->whereNotNull($field);
            $ids = $q()->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id');
            $rewrapped = 0;
            $failed = 0;
            foreach ($ids as $id) {
                $ok = Database::connection()->transaction(function () use ($i, $suffix, $field, $id) {
                    $current = $this->installations->fence();
                    if ($current->state !== 'active' || $current->generation !== $i->generation) {
                        throw new MaintenanceFailure('maintenance_in_progress');
                    }$row = Database::connection()->table('exception_monitor_'.$suffix)->where('installation_id', $i->id)->where('id', $id)->lockForUpdate()->first(['id', $field]);
                    if (! $row || $row->{$field} === null) {
                        return true;
                    }try {
                        $plain = Crypt::decryptString($row->{$field});
                        $encrypted = Crypt::encryptString($plain);
                    } catch (\Throwable) {
                        return false;
                    }Database::connection()->table('exception_monitor_'.$suffix)->where('id', $id)->update([$field => $encrypted]);

                    return true;
                }, 5);
                if ($ok) {
                    $rewrapped++;
                } else {
                    $failed++;
                }
            }
            $cursor = $ids->last() ?? $after;
            $more = $q()->where('id', '>', $cursor)->exists();
            if ($failed) {
                app(CaptureObservation::class)->record('key_rewrap', 'warning', 'Some values could not be decrypted; their ciphertext was preserved. Restore the required current/previous host keys and retry from cursor zero.', ['generation' => $i->generation, 'attempt_failed' => true]);
            }

            return ['scanned' => $ids->count(), 'rewrapped' => $rewrapped, 'failed' => $failed, 'next_cursor' => $cursor, 'has_more' => $more, 'complete' => ! $more && $failed === 0];
        });
    }
}
