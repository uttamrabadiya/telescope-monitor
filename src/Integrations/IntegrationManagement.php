<?php

namespace TelescopeMonitor\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Alerts\EventCatalog;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Models\LinkedIssue;
use TelescopeMonitor\Models\Secret;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class IntegrationManagement
{
    public function __construct(private InstallationRepository $installations, private Capabilities $caps, private Identity $identity, private DestinationPolicy $policy, private Credentials $credentials, private GitHubIssues $github, private ProviderRouter $provider) {}

    private function access(string $cap = 'view'): void
    {
        abort_unless($this->caps->allows($cap), 403);
        if (! $this->github->ready()) {
            throw new Conflict('Publish and run integration migrations first.');
        }
    }

    private function current(string $id): Connection
    {
        return Connection::query()->where('installation_id', $this->installations->current()->id)->where('public_id', $id)->whereIn('provider', ['slack', 'webhook', 'github'])->firstOrFail();
    }

    public function summary(Connection $c): array
    {
        $secrets = Secret::query()->where('connection_id', $c->id)->whereIn('state', ['active', 'staged', 'retiring'])->get();
        $unreadable = false;
        foreach ($secrets as $secret) {
            if ($secret->state === 'active') {
                try {
                    $this->credentials->value($secret);
                } catch (\Throwable) {
                    $unreadable = true;
                }
            }
        }

        return [
            'id' => $c->public_id,
            'provider' => $c->provider,
            'name' => $c->display_name,
            'display' => $c->target_public['display'] ?? ($c->target_public['owner'] ?? '').'/'.($c->target_public['repo'] ?? ''),
            'target' => $c->target_public,
            'enabled' => $c->enabled,
            'disconnected' => $c->disconnected_at !== null,
            'version' => $c->row_version,
            'state' => $unreadable ? 'unreadable' : 'configured',
            'options' => $c->options,
            'last_test_at' => $c->last_test_at?->toISOString(),
            'last_test_outcome' => $c->last_test_outcome,
            'last_accepted_at' => $c->options['last_accepted_at'] ?? null,
            'failed_count' => (string) Delivery::query()->where('connection_id', $c->id)->whereIn('status', ['failed', 'needs_review'])->count(),
            'keys' => $secrets->map(fn ($s) => [
                'id' => $s->key_id,
                'purpose' => $s->secret_name,
                'revision' => $s->revision,
                'state' => $s->state,
                'source' => $s->source,
                'retire_after' => $s->retire_after?->toISOString(),
            ])->all(),
        ];
    }

    public function configuration(): array
    {
        $this->access();
        $admin = $this->caps->allows('manage');
        $items = Connection::query()->where('installation_id', $this->installations->current()->id)->whereIn('provider', ['slack', 'webhook', 'github'])->orderByDesc('enabled')->orderBy('id')->limit(100)->get();

        return [
            'connections' => $items->map(function ($c) use ($admin) {
                $s = $this->summary($c);
                if (! $admin) {
                    unset($s['keys']);
                    unset($s['options']['environments']);
                }

                return $s;
            })->all(),
            'events' => EventCatalog::LABELS,
            'allowed_environments' => config('exception-monitor.alerts.allowed_environments', ['production']),
            'credential_references' => $admin ? array_keys(config('exception-monitor.integrations.credentials', [])) : [],
        ];
    }

    private function validated(string $op, array $v): array
    {
        $rules = match ($op) {
            'connection.create' => [
                'provider' => ['required', Rule::in(['slack', 'webhook', 'github'])],
                'name' => ['required', 'string', 'max:120'],
                'enabled' => ['required', 'boolean'],
                'endpoint' => ['nullable', 'string', 'max:2048'],
                'owner' => ['nullable', 'string', 'max:39'],
                'repo' => ['nullable', 'string', 'max:100'],
                'credential' => ['nullable', 'string', 'max:4096'],
                'credential_reference' => ['nullable', 'string', 'max:100'],
                'events' => ['sometimes', 'array', 'min:1', 'max:12'],
                'events.*' => [Rule::in(array_keys(EventCatalog::LABELS)), 'distinct'],
                'automatic' => ['sometimes', 'boolean'],
                'environments' => ['sometimes', 'array', 'max:20'],
                'environments.*' => ['string', Rule::in(config('exception-monitor.alerts.allowed_environments', ['production']))],
                'replaces' => ['nullable', 'uuid'],
                'replaces_version' => ['required_with:replaces', 'integer', 'min:1'],
            ],
            'connection.update' => [
                'version' => ['required', 'integer', 'min:1'],
                'name' => ['required', 'string', 'max:120'],
                'enabled' => ['required', 'boolean'],
                'events' => ['sometimes', 'array', 'min:1', 'max:12'],
                'events.*' => [Rule::in(array_keys(EventCatalog::LABELS)), 'distinct'],
                'automatic' => ['sometimes', 'boolean'],
                'environments' => ['sometimes', 'array', 'max:20'],
                'environments.*' => ['string', Rule::in(config('exception-monitor.alerts.allowed_environments', ['production']))],
                'labels' => ['sometimes', 'array', 'max:25'],
                'labels.*' => ['string', 'max:100', 'distinct'],
            ],
            'connection.disconnect' => ['version' => ['required', 'integer', 'min:1']],
            'secret.replace' => [
                'version' => ['required', 'integer', 'min:1'],
                'credential' => ['nullable', 'string', 'max:4096'],
                'credential_reference' => ['nullable', 'string', 'max:100'],
            ],
            'key.stage' => ['version' => ['required', 'integer', 'min:1']],
            'key.activate' => ['version' => ['required', 'integer', 'min:1'], 'key_id' => ['required', 'uuid']],
            'test' => ['signing_key_id' => ['nullable', 'uuid']],
            'github.create','github.recreate' => [
                'connection' => ['required', 'uuid'],
                'preview_key' => ['required', 'uuid'],
                'title' => ['nullable', 'string', 'max:256'],
                'labels' => ['sometimes', 'array', 'max:25'],
                'labels.*' => ['string', 'max:100', 'distinct'],
                'preview_digest' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
                'link_version' => ['required', 'integer', 'min:0'],
                'confirm_no_ticket' => ['sometimes', 'boolean'],
            ],
            'github.link' => [
                'connection' => ['required', 'uuid'],
                'version' => ['required', 'integer', 'min:0'],
                'number' => ['required', 'string', 'max:512'],
            ],
            'github.unlink','github.refresh','github.inspect' => ['link' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']],
            default => throw ValidationException::withMessages(['operation' => 'Unknown integration operation.'])
        };
        $allowed = array_unique(array_map(fn ($k) => explode('.', $k)[0], array_keys($rules)));
        if (array_diff(array_keys($v), $allowed)) {
            throw ValidationException::withMessages(['request' => 'Unsupported integration fields.']);
        }

        return Validator::make($v, $rules)->validate();
    }

    private function version(Connection|LinkedIssue $row, int $version): void
    {
        if ($row->row_version !== $version) {
            throw new Conflict('This record changed. Reload before replacing your draft.');
        }
    }

    private function credential(array $v, string $purpose): array
    {
        $ref = $v['credential_reference'] ?? null;
        $value = $v['credential'] ?? null;
        if ($ref) {
            $registry = config('exception-monitor.integrations.credentials', []);
            $entry = $registry[$ref] ?? null;
            if (! $entry || ($entry['purpose'] ?? null) !== $purpose) {
                throw ValidationException::withMessages(['credential_reference' => 'Choose a deployment-approved credential reference for this purpose.']);
            }$value = $entry['value'] ?? null;
        }
        if (! is_string($value) || $value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw ValidationException::withMessages(['credential' => 'Provide a valid credential or approved deployment reference.']);
        }

        return [$value, $ref];
    }

    private function cancel(int $id, bool $automaticOnly = false): void
    {
        $q = Delivery::query()->where('connection_id', $id)->whereIn('status', ['pending', 'retrying', 'failed']);
        if ($automaticOnly) {
            $q->where('action', 'github_auto');
        }
        $q->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'destination_or_policy_changed',
            'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        LinkedIssue::query()->where('connection_id', $id)->whereIn('state', ['reserved'])->whereIn('create_delivery_id', Delivery::query()->where('connection_id', $id)->where('status', 'cancelled')->select('id'))->update(['state' => 'unavailable', 'row_version' => Database::connection()->raw('row_version + 1')]);
    }

    private function write(string $op, ?string $id, array $v, string $actor): array
    {
        $i = $this->installations->current();
        $once = [];
        if ($op === 'connection.create') {
            if (trim($v['name']) === '') {
                throw ValidationException::withMessages(['name' => 'Enter a connection name.']);
            }
            $provider = $v['provider'];
            if ($provider === 'github' && ($v['automatic'] ?? false) && empty($v['environments'])) {
                throw ValidationException::withMessages(['environments' => 'Select explicit automatic-creation environments.']);
            }
            $ref = null;
            if ($provider === 'github') {
                $public = $this->policy->repository($v['owner'] ?? '', $v['repo'] ?? '');
                $fp = $this->identity->digest(['provider' => 'github'] + $public);
                [$secret,$ref] = $this->credential($v, 'github_token');
                $purpose = 'github_token';
            } else {
                if ($provider === 'slack' && ($v['credential_reference'] ?? null)) {
                    [$endpoint,$ref] = $this->credential($v, 'endpoint');
                } else {
                    $endpoint = $v['endpoint'] ?? '';
                }$target = $this->policy->url($endpoint, $provider);
                $secret = $target['url'];
                $fp = $this->identity->digest(['provider' => $provider, 'url' => $secret]);
                $public = ['display' => $target['display']];
                $purpose = 'endpoint';
            }
            $c = Connection::query()->createOrFirst(['installation_id' => $i->id, 'provider' => $provider, 'target_fingerprint' => $fp], [
                'public_id' => (string) Str::uuid(),
                'display_name' => trim($v['name']),
                'target_public' => $public,
                'enabled' => $v['enabled'],
                'options' => [
                    'automatic' => (bool) ($v['automatic'] ?? false),
                    'environments' => $v['environments'] ?? [],
                    'labels' => [],
                    'known_labels' => [],
                    'events' => $v['events'] ?? array_keys(EventCatalog::LABELS),
                ],
            ]);
            if ($c->wasRecentlyCreated) {
                $this->credentials->store($c, $purpose, $secret, $actor, 'active', $ref);
                if ($provider === 'webhook') {
                    $generated = Str::random(64);
                    $key = $this->credentials->store($c, 'webhook_signing', $generated, $actor);
                    $once = ['secret' => $generated, 'key_id' => $key->key_id, 'state' => 'active'];
                }
            }
            if ($v['replaces'] ?? null) {
                $old = $this->current($v['replaces']);
                $this->version($old, (int) $v['replaces_version']);
                if ($old->id === $c->id || $old->provider !== $c->provider) {
                    throw new Conflict('Replacement must be a different target of the same provider.');
                }$old->enabled = false;
                $old->disconnected_at = CarbonImmutable::now('UTC');
                $old->row_version++;
                $old->save();
                $this->cancel($old->id);
                if ($c->wasRecentlyCreated) {
                    $c->replaces_connection_id = $old->id;
                    $c->save();
                }
            }
        } else {
            $c = $this->current($id);
            $this->version($c, (int) $v['version']);
            if ($op === 'connection.update') {
                if (trim($v['name']) === '') {
                    throw ValidationException::withMessages(['name' => 'Enter a connection name.']);
                }
                $c->display_name = trim($v['name']);
                $c->enabled = (bool) $v['enabled'];
                $opts = $c->options;
                if ($c->provider === 'webhook') {
                    $opts['events'] = $v['events'] ?? ($opts['events'] ?? array_keys(EventCatalog::LABELS));
                }
                if ($c->provider === 'github') {
                    if (array_diff($v['labels'] ?? [], $opts['known_labels'] ?? [])) {
                        throw ValidationException::withMessages(['labels' => 'Select labels observed by the repository check.']);
                    }$opts['labels'] = $v['labels'] ?? [];
                    $opts['automatic'] = (bool) ($v['automatic'] ?? false);
                    $opts['environments'] = $v['environments'] ?? [];
                    if ($opts['automatic'] && ! $opts['environments']) {
                        throw ValidationException::withMessages(['environments' => 'Select explicit automatic-creation environments.']);
                    }
                    if (! $opts['automatic']) {
                        $this->cancel($c->id, true);
                    }
                }
                $c->options = $opts;
                if (! $c->enabled) {
                    $this->cancel($c->id);
                }
            } elseif ($op === 'connection.disconnect') {
                $c->enabled = false;
                $c->disconnected_at = CarbonImmutable::now('UTC');
                $this->cancel($c->id);
            } elseif ($op === 'secret.replace') {
                if ($c->provider !== 'github') {
                    throw new Conflict('Changing an endpoint requires a replacement connection.');
                }[$value,$ref] = $this->credential($v, 'github_token');
                Secret::query()->where('connection_id', $c->id)->where('secret_name', 'github_token')->where('state', 'active')->update(['state' => 'retiring', 'retire_after' => CarbonImmutable::now('UTC')->addMinutes(2)->format('Y-m-d H:i:s.u')]);
                $this->credentials->store($c, 'github_token', $value, $actor, 'active', $ref);
            } else {
                if ($c->provider !== 'webhook') {
                    throw new Conflict('Signing-key rotation applies to webhooks.');
                }
                if ($op === 'key.stage') {
                    Secret::query()->where('connection_id', $c->id)->where('secret_name', 'webhook_signing')->where('state', 'staged')->update(['state' => 'retired', 'ciphertext' => null]);
                    $value = Str::random(64);
                    $key = $this->credentials->store($c, 'webhook_signing', $value, $actor, 'staged');
                    $once = ['secret' => $value, 'key_id' => $key->key_id, 'state' => 'staged'];
                } else {
                    $key = Secret::query()->where('connection_id', $c->id)->where('key_id', $v['key_id'])->where('secret_name', 'webhook_signing')->where('state', 'staged')->lockForUpdate()->firstOrFail();
                    Secret::query()->where('connection_id', $c->id)->where('secret_name', 'webhook_signing')->where('state', 'active')->update(['state' => 'retiring', 'retire_after' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d H:i:s.u')]);
                    $key->state = 'active';
                    $key->activated_at = CarbonImmutable::now('UTC');
                    $key->save();
                }
            }
            $c->row_version++;
            $c->save();
        }
        $this->installations->advanceConfiguration($i);

        return ['id' => $c->public_id, 'version' => $c->row_version, 'once' => $once];
    }

    private function link(string $issueId, array $v, string $actor, ?array $ticket, string $op): array
    {
        $issue = $this->github->issue($issueId, true);
        $i = $this->installations->current();
        if ($op === 'github.link') {
            $c = $this->github->connection($v['connection']);
            $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('repository_fingerprint', $c->target_fingerprint)->lockForUpdate()->first();
            if (($link->row_version ?? 0) !== (int) $v['version']) {
                throw new Conflict('The ticket association changed. Reload before linking.');
            }
            if ($link && $link->state === 'creating') {
                throw new Conflict('Creation is in flight. Wait for an outcome before linking.');
            }
            $link ??= new LinkedIssue([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'issue_id' => $issue->id,
                'connection_id' => $c->id,
                'repository_fingerprint' => $c->target_fingerprint,
                'repository_owner' => $c->target_public['owner'],
                'repository_name' => $c->target_public['repo'],
                'operation_public_id' => (string) Str::uuid(),
            ]);
            if ($link->create_delivery_id) {
                Delivery::query()->whereKey($link->create_delivery_id)->whereIn('status', ['pending', 'retrying', 'failed', 'needs_review'])->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => 'linked_existing',
                    'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
            }
            $link->state = 'linked';
            $link->remote_number = $ticket['number'];
            $link->remote_url = $ticket['url'];
            $link->last_known_remote_state = $ticket['state'];
            $link->checked_at = CarbonImmutable::now('UTC');
            $link->linked_by_actor_key = $actor;
        } else {
            $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('public_id', $v['link'])->lockForUpdate()->firstOrFail();
            $this->version($link, (int) $v['version']);
            if ($op === 'github.unlink') {
                if (in_array($link->state, ['reserved', 'creating', 'needs_review'], true)) {
                    throw new Conflict('Resolve the pending or uncertain operation before unlinking.');
                }$link->state = 'unlinked';
                $link->remote_number = null;
                $link->remote_url = null;
            } elseif ($op === 'github.refresh') {
                $link->state = $ticket ? 'linked' : 'unavailable';
                $link->checked_at = CarbonImmutable::now('UTC');
                if ($ticket) {
                    $link->last_known_remote_state = $ticket['state'];
                }
            }
        }
        if ($link->exists) {
            $link->row_version++;
        }$link->save();

        return ['id' => $link->public_id, 'version' => $link->row_version, 'state' => $link->state];
    }

    private function readTicket(string $issueId, string $op, array $v): ?array
    {
        $issue = $this->github->issue($issueId);
        $link = null;
        if ($op === 'github.link') {
            $c = $this->github->connection($v['connection']);
            $number = $v['number'];
            if (str_starts_with($number, 'https://')) {
                $pattern = '~^https://github\\.com/'.preg_quote($c->target_public['owner'], '~').'/'.preg_quote($c->target_public['repo'], '~').'/issues/([1-9][0-9]{0,9})$~iD';
                if (! preg_match($pattern, $number, $match)) {
                    throw ValidationException::withMessages(['number' => 'Use an issue number or canonical issue URL in this repository.']);
                }$number = $match[1];
            }if (! preg_match('/^[1-9][0-9]{0,9}$/D', $number)) {
                throw ValidationException::withMessages(['number' => 'Enter a positive repository issue number.']);
            }
        } else {
            $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('public_id', $v['link'])->firstOrFail();
            $c = $this->current(Connection::query()->findOrFail($link->connection_id)->public_id);
            $number = (string) $link->remote_number;
        }
        $target = $this->credentials->target($c);
        $r = $this->provider->github('GET', $target, '/issues/'.$number);
        $ticket = $this->provider->ticket($target, $r);
        if (! $ticket && ($op === 'github.link' || $r->status !== 404)) {
            throw new Conflict('The repository issue could not be validated. Pull requests, unrelated repositories and unavailable responses cannot be linked.');
        }

        return $ticket;
    }

    public function command(string $op, ?string $id, string $key, array $input): array
    {
        $this->access(str_starts_with($op, 'github.') ? 'operate' : 'manage');
        $v = $this->validated($op, $input);
        $actor = $this->identity->current();
        $digest = $this->identity->digest(['op' => $op, 'id' => $id, 'values' => $v]);
        $ticket = null;
        // Read-only remote validation is deliberately outside the write transaction.
        if (in_array($op, ['github.link', 'github.refresh'], true)) {
            $ticket = $this->readTicket($id, $op, $v);
        }
        if ($op === 'github.inspect') {
            return $this->inspect($id, $v);
        }

        return Database::connection()->transaction(function () use ($op, $id, $key, $v, $actor, $digest, $ticket) {
            $i = $this->installations->lock();
            if ($i->state !== 'active') {
                throw new Conflict('Monitoring is undergoing maintenance.');
            }$this->access(str_starts_with($op, 'github.') ? 'operate' : 'manage');
            $receipt = ActionReceipt::query()->createOrFirst([
                'installation_id' => $i->id,
                'actor_lookup_hash' => $this->identity->hash($actor->key),
                'operation' => 'integration.'.$op,
                'client_key' => $key,
            ], [
                'public_id' => (string) Str::uuid(),
                'generation' => $i->generation,
                'actor_key' => $actor->key,
                'keyed_request_digest' => $digest,
                'state' => 'pending',
                'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
            ]);
            $receipt = ActionReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->generation !== $i->generation || ! hash_equals($receipt->keyed_request_digest, $digest)) {
                throw new Conflict('This action key belongs to a different request.');
            }if ($receipt->state === 'completed') {
                return ['id' => $receipt->result_public_id, 'version' => $receipt->resulting_row_version, 'replayed' => true];
            }
            if (in_array($op, ['github.create', 'github.recreate'], true)) {
                $result = $this->github->confirmed($id, $v, $op === 'github.recreate', $actor->key);
            } elseif (str_starts_with($op, 'github.')) {
                $result = $this->link($id, $v, $actor->key, $ticket, $op);
            } elseif ($op === 'test') {
                $c = $this->current($id);
                if (isset($v['signing_key_id'])) {
                    $this->credentials->active($c, 'webhook_signing', $v['signing_key_id']);
                    if ($c->provider !== 'webhook') {
                        throw new Conflict('Only webhooks have signing keys.');
                    }
                }$result = app(AlertManagement::class)->command('test', null, $key, ['connections' => [$id]] + array_intersect_key($v, ['signing_key_id' => true]));
            } else {
                $result = $this->write($op, $id, $v, $actor->key);
            }
            $once = $result['once'] ?? [];
            unset($result['once']);
            $receipt->state = 'completed';
            $receipt->result_type = 'integration';
            $receipt->result_public_id = $result['id'];
            $receipt->resulting_row_version = $result['version'] ?? null;
            $receipt->completed_at = CarbonImmutable::now('UTC');
            $receipt->save();
            Activity::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'issue_id' => str_starts_with($op, 'github.') ? $this->github->issue($id)->id : null,
                'event_type' => 'integration.'.$op,
                'actor_kind' => 'user',
                'actor_key' => $actor->key,
                'subject_type' => 'integration',
                'subject_public_id' => $result['id'] ?? $id,
                'action_public_id' => $receipt->public_id,
                'occurred_at' => CarbonImmutable::now('UTC'),
                'metadata' => ['reviewed_no_ticket' => $op === 'github.recreate'],
                'expires_at' => CarbonImmutable::now('UTC')->addDays(app(RetentionPolicy::class)->effective()['activity_days']),
            ]);

            return $result + ['replayed' => false] + ($once ? ['once' => $once] : []);
        }, 5);
    }

    private function inspect(string $issueId, array $v): array
    {
        $issue = $this->github->issue($issueId);
        $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('public_id', $v['link'])->firstOrFail();
        $this->version($link, (int) $v['version']);
        $c = Connection::query()->findOrFail($link->connection_id);
        $target = $this->credentials->target($c);
        $marker = 'telescope-monitor:'.$this->installations->current()->public_id.':'.$issue->public_id.':'.$link->operation_public_id;
        // Bounded read-only inspection; absence never releases a reservation.
        $r = $this->provider->github('GET', $target, '/issues?state=all&per_page=100');
        if ($r->status !== 200) {
            throw new Conflict('Repository inspection is unavailable. The reservation remains Needs review.');
        }$matches = [];
        foreach ($r->json() as $item) {
            if (is_array($item) && str_contains($item['body'] ?? '', $marker)) {
                $ticket = $this->provider->ticket($target, new HttpResult(200, json_encode($item, JSON_THROW_ON_ERROR)));
                if ($ticket) {
                    $matches[] = $ticket;
                }
            }
        }

        return [
            'matches' => array_slice($matches, 0, 10),
            'complete' => false,
            'message' => 'Only a bounded repository page was inspected. Missing results never prove that no ticket exists. No creation was queued.',
        ];
    }
}
