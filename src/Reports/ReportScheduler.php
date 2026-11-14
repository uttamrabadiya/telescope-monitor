<?php

namespace TelescopeMonitor\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\DeliveryDispatcher;
use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Storage\Database;

final class ReportScheduler
{
    public function __construct(private InstallationRepository $installations, private SettingsService $settings, private ReportBuilder $builder, private DeliveryDispatcher $dispatcher, private ActorResolver $actors) {}

    public function queue(string $kind, ?string $previewKey = null, ?int $version = null): array
    {
        return Telescope::withoutRecording(fn () => Database::connection()->transaction(function () use ($kind, $previewKey, $version) {
            // The same exclusive fence as settings changes serializes concurrent ticks
            // and prevents configuration changes halfway through report preparation.
            $i = $this->installations->lock();
            if ($previewKey === null && (! config('telescope-monitor.enabled') || ! config('telescope-monitor.alerts.enabled', true))) {
                return ['deliveries' => [], 'skipped' => true];
            }
            if ($i->state !== 'active' || ! config('telescope-monitor.enabled') || ! config('telescope-monitor.alerts.enabled', true)) {
                throw new ConfigurationConflict('Report delivery is unavailable while monitoring is disabled or in maintenance.');
            }
            $group = $this->settings->group('reports');
            if ($version !== null && $version !== $group['version']) {
                throw new ConfigurationConflict('Report settings changed. Reload before sending a preview.');
            }
            $definition = $group['values'][$kind];
            $now = CarbonImmutable::now('UTC');
            $local = $now->setTimezone($definition['timezone']);
            if ($previewKey === null && (! $definition['enabled'] || $local->format('H:i') < $definition['time'] || ($kind === 'weekly' && $local->dayOfWeekIso !== $definition['weekday']))) {
                return ['deliveries' => [], 'skipped' => true];
            }
            if (! $definition['connections'] || ! $definition['environments']) {
                if ($previewKey === null) {
                    return ['deliveries' => [], 'skipped' => true];
                }
                throw ValidationException::withMessages(['report' => 'Save at least one recipient and environment before sending a preview.']);
            }
            $allowed = config('telescope-monitor.alerts.allowed_environments', ['production']);
            if (array_diff($definition['environments'], $allowed)) {
                throw new ConfigurationConflict('A selected report environment is no longer permitted by deployment policy.');
            }
            [, $end] = $this->builder->period($kind, $definition['timezone'], $now);
            $key = hash('sha256', 'report:'.$kind.':'.($previewKey ?? $end->format('Y-m-d')));
            $existing = AlertEvent::query()->where('installation_id', $i->id)->where('generation', $i->generation)->where('event_key', $key)->first();
            if ($existing) {
                return $this->result($existing);
            }
            $connections = Connection::query()->where('installation_id', $i->id)->where('provider', 'email')->where('enabled', true)->whereNull('disconnected_at')->whereIn('public_id', $definition['connections'])->orderBy('id')->get();
            if ($connections->isEmpty() || ($previewKey !== null && $connections->count() !== count($definition['connections']))) {
                throw ValidationException::withMessages(['report' => 'The report recipients are unavailable. Update and save the recipient selection.']);
            }
            $report = $this->builder->build($kind, $definition, $now);
            $skip = $previewKey === null && $definition['skip_empty'] && ! $report['has_activity'] && $report['complete'] && $report['activity_complete'];
            $general = $this->settings->group('general')['values'];
            $snapshot = app(Envelope::class)->sanitize([
                'report' => $report,
                'report_definition_hash' => ReportDefinition::hash($definition),
                'application' => $general['display_name'],
                'environment' => implode(', ', $definition['environments']),
                'event' => 'report.'.$kind,
                'label' => ucfirst($kind).' report',
                'test' => $previewKey !== null,
                'class' => '',
                'message' => '',
                'file' => '',
                'line' => 0,
                'count' => (string) $report['occurrences'],
                'occurred_at' => $now->toISOString(),
                'url' => $report['url'],
            ]);
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $actor = $previewKey !== null ? $this->actors->current()?->key : null;
            if ($previewKey !== null && $actor === null) {
                abort(403);
            }
            $event = AlertEvent::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'generation' => $i->generation,
                'event_type' => 'report.'.$kind,
                'event_key' => $key,
                'action_public_id' => $previewKey,
                'issue_state_version' => 0,
                'alert_generation' => 0,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'provenance' => $previewKey !== null ? 'report_preview' : 'scheduled_report',
                'actor_key' => $actor,
                'payload_ciphertext' => Crypt::encryptString($json),
                'payload_digest' => hash('sha256', $json),
                'planning_state' => $skip ? 'skipped' : 'planned',
                'expires_at' => $now->addDays(30),
            ]);
            if (! $skip) {
                foreach ($connections as $c) {
                    $d = Delivery::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'installation_id' => $i->id,
                        'generation' => $i->generation,
                        'event_id' => $event->id,
                        'connection_id' => $c->id,
                        'action' => ($previewKey !== null ? 'report_preview_' : 'report_').$kind,
                        'dedupe_key' => hash('sha256', $key.':'.$c->target_fingerprint),
                        'target_fingerprint' => $c->target_fingerprint,
                        'actor_required' => $previewKey !== null,
                        'actor_key' => $actor,
                        'event_family' => 'report.'.$kind,
                        'status' => 'pending',
                        'eligible_at' => $now,
                        'expires_at' => $now->addDay(),
                    ]);
                    Database::connection()->afterCommit(fn () => $this->dispatcher->dispatch($d->id, $d->generation));
                }
            }

            return $this->result($event);
        }, 5));
    }

    private function result(AlertEvent $event): array
    {
        return [
            'skipped' => $event->planning_state === 'skipped',
            'queue_asynchronous' => $this->dispatcher->asynchronous(),
            'deliveries' => Delivery::query()->where('event_id', $event->id)->orderBy('id')->get()->map(fn ($d) => ['id' => $d->public_id, 'status' => $d->status])->all(),
        ];
    }

    /** Pending deliveries must still have the same explicit report subscription. */
    public function supports(Delivery $d): bool
    {
        if (! config('telescope-monitor.enabled')) {
            return false;
        }
        $kind = str_ends_with($d->action, '_weekly') ? 'weekly' : 'daily';
        $definition = $this->settings->group('reports')['values'][$kind];
        $c = Connection::query()->where('installation_id', $d->installation_id)->find($d->connection_id);
        if (! $c || $c->provider !== 'email' || ! in_array($c->public_id, $definition['connections'], true) || array_diff($definition['environments'], config('telescope-monitor.alerts.allowed_environments', ['production']))) {
            return false;
        }
        try {
            $event = AlertEvent::query()->where('installation_id', $d->installation_id)->findOrFail($d->event_id);
            $body = Crypt::decryptString($event->payload_ciphertext);
            if (! hash_equals($event->payload_digest, hash('sha256', $body))) {
                return false;
            }
            $snapshot = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return ($definition['enabled'] || str_starts_with($d->action, 'report_preview_')) && hash_equals($snapshot['report_definition_hash'] ?? '', ReportDefinition::hash($definition));
    }
}
