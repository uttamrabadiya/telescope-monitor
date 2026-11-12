<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Collaboration\Assignments;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Collaboration\ViewDefinition;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Note;
use TelescopeMonitor\Models\SavedView;
use TelescopeMonitor\Models\ViewPreference;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\CollaborationRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\InvestigationRepository;
use TelescopeMonitor\Services\RedactionService;
use TelescopeMonitor\Storage\Database;

final class EloquentCollaborationRepository implements CollaborationRepository
{
    private ?bool $available = null;

    public function __construct(private InstallationRepository $installations, private InvestigationRepository $issues, private Identity $identity, private Capabilities $caps, private Assignments $assignments, private ViewDefinition $definitions, private CapturePolicy $policy, private RedactionService $redactor) {}

    public function ready(): bool
    {
        return $this->available ??= Database::schema()->hasTable('telescope_monitor_notes') && Database::schema()->hasTable('telescope_monitor_saved_views') && Database::schema()->hasTable('telescope_monitor_view_preferences');
    }

    private function installation(): int
    {
        if (! $this->ready()) {
            throw new Conflict('Publish and run the collaboration migration before using this feature.');
        }abort_unless($this->caps->allows('view'), 403);

        return $this->installations->current()->id;
    }

    private function accessible(int $installation, string $hash)
    {
        return SavedView::query()->where('installation_id', $installation)->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('owner_lookup_hash', $hash));
    }

    private function viewRow(int $installation, string $id, string $hash, bool $lock = false): SavedView
    {
        return $this->accessible($installation, $hash)->where('public_id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    private function viewResource(SavedView $v, string $hash, array $caps): array
    {
        $own = hash_equals($v->owner_lookup_hash, $hash);
        $definition = [
            'name' => $v->name,
            'visibility' => $v->visibility,
            'filters' => $v->filters,
            'sort' => $v->sort_key,
            'range' => $v->relative_range['key'] ?? '',
        ];
        $supported = $this->definitions->supported($v->filter_schema_version, $definition);

        return [
            'id' => $v->public_id,
            'name' => $v->name,
            'visibility' => $v->visibility,
            'owner' => $this->identity->label($v->owner_actor_key),
            'own' => $own,
            'version' => $v->row_version,
            'filter_schema_version' => $v->filter_schema_version,
            'definition' => $definition,
            'state' => $supported ? 'ready' : 'needs_repair',
            'reason' => $supported ? null : 'This definition uses unsupported filters or an unavailable feature. Replace its filters or restore deployment support.',
            'can_edit' => $own ? ($v->visibility === 'personal' || $caps['operate'] || $caps['manage']) : ($v->visibility === 'shared' && $caps['manage']),
        ];
    }

    public function views(int $page = 1): array
    {
        $i = $this->installation();
        $actor = $this->identity->current();
        $hash = $this->identity->hash($actor->key);
        $caps = $this->caps->current();
        $rows = $this->accessible($i, $hash)->orderBy('name')->orderBy('id')->paginate(50, ['*'], 'page', $page);

        return [
            'items' => $rows->getCollection()->map(fn ($v) => $this->viewResource($v, $hash, $caps))->all(),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => (string) $rows->total(),
            'builtins' => array_keys($this->definitions->builtins()),
        ];
    }

    public function view(string $id): array
    {
        $i = $this->installation();
        $hash = $this->identity->hash($this->identity->current()->key);

        return $this->viewResource($this->viewRow($i, $id, $hash), $hash, $this->caps->current());
    }

    private function references(int $i, string $hash, array $refs): array
    {
        $builtins = $this->definitions->builtins();
        $allowed = [];
        foreach ($refs as $ref) {
            if (isset($builtins[$ref])) {
                $allowed[$ref] = ucwords(str_replace('_', ' ', substr($ref, 8)));
            }
        }
        $ids = array_values(array_filter($refs, fn ($ref) => is_string($ref) && Str::isUuid($ref)));
        foreach ($this->accessible($i, $hash)->whereIn('public_id', $ids)->get() as $v) {
            if ($this->definitions->supported($v->filter_schema_version, [
                'name' => $v->name,
                'visibility' => $v->visibility,
                'filters' => $v->filters,
                'sort' => $v->sort_key,
                'range' => $v->relative_range['key'] ?? '',
            ])) {
                $allowed[$v->public_id] = $v->name;
            }
        }

        return $allowed;
    }

    public function preferences(): array
    {
        $i = $this->installation();
        $hash = $this->identity->hash($this->identity->current()->key);
        $row = ViewPreference::query()->where('installation_id', $i)->where('actor_lookup_hash', $hash)->first();
        $shortcuts = $row->shortcuts ?? [];
        $refs = array_column($shortcuts, 'ref');
        if ($row?->default_view_ref) {
            $refs[] = $row->default_view_ref;
        }$allowed = $this->references($i, $hash, $refs);

        return [
            'labels' => $allowed,
            'version' => $row->row_version ?? 0,
            'shortcuts' => array_values(array_filter($shortcuts, fn ($s) => isset($allowed[$s['ref']]))),
            'default_view_ref' => $row && isset($allowed[$row->default_view_ref ?? '']) ? $row->default_view_ref : null,
        ];
    }

    private function noteResource(Note $n, string $hash, array $caps): array
    {
        $body = null;
        $state = $n->deleted_at ? 'deleted' : 'available';
        if (! $n->deleted_at) {
            try {
                if ($n->body_version !== 1) {
                    throw new \RuntimeException;
                }$body = Crypt::decryptString($n->body_ciphertext ?? '');
            } catch (\Throwable) {
                $state = 'unreadable';
            }
        }
        $own = hash_equals($n->author_lookup_hash, $hash);

        return [
            'id' => $n->public_id,
            'author' => $this->identity->label($n->author_actor_key),
            'own' => $own,
            'body' => $body,
            'state' => $state,
            'version' => $n->row_version,
            'created_at' => $n->created_at->toISOString(),
            'edited_at' => $n->edited_at?->toISOString(),
            'deleted_at' => $n->deleted_at?->toISOString(),
            'can_edit' => $own && $caps['operate'] && ! $n->deleted_at,
            'can_delete' => ! $n->deleted_at && ($caps['manage'] || ($own && $caps['operate'])),
        ];
    }

    public function notes(string $id, int $page): array
    {
        $i = $this->installation();
        $issue = $this->issues->issue($i, $id);
        abort_unless($issue !== null, 404);
        $hash = $this->identity->hash($this->identity->current()->key);
        $caps = $this->caps->current();
        $rows = Note::query()->where('installation_id', $i)->where('issue_id', $issue->id)->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['*'], 'page', $page);

        return [
            'items' => $rows->getCollection()->map(fn ($n) => $this->noteResource($n, $hash, $caps))->all(),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => (string) $rows->total(),
        ];
    }

    private function version(int $current, int $expected): void
    {
        if ($current !== $expected) {
            throw new Conflict('This item changed. Your draft is preserved; reload the latest version before saving.', ['version' => $current]);
        }
    }

    private function noteText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = $this->redactor->text($text, $this->policy->read()['privacy']['redact_patterns']);
        if (trim($text) === '' || mb_strlen($text) > 10000) {
            throw ValidationException::withMessages(['telescope_monitor_private.body' => 'Enter 1–10,000 characters after privacy redaction.']);
        }

        return $text;
    }

    private function definition(array $values): array
    {
        foreach (['name'] as $key) {
            $values[$key] = $this->redactor->text($values[$key], $this->policy->read()['privacy']['redact_patterns']);
        }
        foreach (['search', 'class', 'tag'] as $key) {
            if (isset($values['filters'][$key])) {
                $values['filters'][$key] = $this->redactor->text($values['filters'][$key], $this->policy->read()['privacy']['redact_patterns']);
            }
        }

        return $this->definitions->validate($values);
    }

    private function audit(Issue $issue, string $type, string $action, string $actor, array $metadata, bool $notify = false): void
    {
        Activity::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $issue->installation_id,
            'issue_id' => $issue->id,
            'event_type' => $type,
            'actor_kind' => 'user',
            'actor_key' => $actor,
            'subject_type' => 'issue',
            'subject_public_id' => $issue->public_id,
            'action_public_id' => $action,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => $metadata,
            'expires_at' => CarbonImmutable::now('UTC')->addDays(app(RetentionPolicy::class)->effective()['activity_days']),
        ]);
        if ($notify && ! $issue->is_test) {
            $planner = app(AlertPlanning::class);
            if ($planner->ready()) {
                $planner->activity($issue, $type, $action, $actor, $metadata);
            } else {
                $json = json_encode($metadata, JSON_THROW_ON_ERROR);
                AlertEvent::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'installation_id' => $issue->installation_id,
                    'issue_id' => $issue->id,
                    'generation' => $this->installations->current()->generation,
                    'event_type' => $type,
                    'event_key' => hash('sha256', $action.':'.$type),
                    'action_public_id' => $action,
                    'issue_state_version' => $issue->state_version,
                    'alert_generation' => $issue->alert_generation,
                    'occurred_at' => CarbonImmutable::now('UTC'),
                    'recorded_at' => CarbonImmutable::now('UTC'),
                    'provenance' => 'manual',
                    'actor_key' => $actor,
                    'payload_ciphertext' => Crypt::encryptString($json),
                    'payload_digest' => hash('sha256', $json),
                    'planning_state' => 'disabled',
                    'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
                ]);

            }
        }
    }

    public function command(string $operation, ?string $id, string $clientKey, array $values): array
    {
        $installation = $this->installation();
        $actor = $this->identity->current();
        $hash = $this->identity->hash($actor->key);
        $values['version'] = isset($values['version']) ? (int) $values['version'] : 0;
        if ($operation === 'preferences') {
            foreach ($values['shortcuts'] as &$shortcut) {
                $shortcut['favorite'] = (bool) $shortcut['favorite'];
                $shortcut['position'] = (int) $shortcut['position'];
            }unset($shortcut);
        }
        if (str_starts_with($operation, 'note.')) {
            abort_unless($operation === 'note.delete' ? $this->caps->allows('operate') || $this->caps->allows('manage') : $this->caps->allows('operate'), 403);
            if (isset($values['body'])) {
                $values['body'] = str_replace(["\r\n", "\r"], "\n", $values['body']);
            }
        }
        if (in_array($operation, ['view.create', 'view.update'], true)) {
            $v = $this->definition($values);
            $values = array_merge($values, $v);
        }
        if ($operation === 'assignment') {
            abort_unless($this->caps->allows('operate'), 403);
            if (! $this->assignments->enabled()) {
                throw new Conflict('Assignments are disabled. Existing references were preserved.');
            }
        }
        $caps = $this->caps->current();
        if (in_array($operation, ['view.create', 'view.update'], true)) {
            $this->sharedPermission($values['visibility'], $caps);
        }
        $digest = $this->identity->digest(['operation' => $operation, 'id' => $id, 'values' => $values]);

        if ($operation === 'assignment' && $values['assignee'] !== null) {
            $acknowledged = ActionReceipt::query()->where('installation_id', $installation)->where('actor_lookup_hash', $hash)->where('operation', 'collaboration.assignment')->where('client_key', $clientKey)->where('keyed_request_digest', $digest)->where('generation', $this->installations->current()->generation)->where('state', 'completed')->exists();
            if (! $acknowledged) {
                $this->assignments->eligible($values['assignee']);
            }
            $caps = $this->caps->current();
            abort_unless($caps['operate'], 403);
        }

        return Database::connection()->transaction(function () use ($operation, $id, $clientKey, $values, $actor, $hash, $digest, $caps) {
            $i = $this->installations->fence();
            if ($i->state !== 'active') {
                throw new Conflict('Monitoring is undergoing maintenance. Reload before retrying.');
            }
            $receipt = ActionReceipt::query()->createOrFirst([
                'installation_id' => $i->id,
                'actor_lookup_hash' => $hash,
                'operation' => 'collaboration.'.$operation,
                'client_key' => $clientKey,
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
                throw new Conflict('This action key belongs to a different request. Use a new key after reloading.');
            }
            // Receipt replay checks current ACLs; it cannot reopen private resources.
            if (str_starts_with($operation, 'view.') && $operation !== 'view.create') {
                $view = $this->accessible($i->id, $hash)->where('public_id', $id)->first();
                if (! $view) {
                    $deleted = $operation === 'view.delete' && $receipt->state === 'completed' && ! SavedView::query()->where('installation_id', $i->id)->where('public_id', $id)->exists();
                    abort_unless($deleted, 404);
                } else {
                    $own = hash_equals($view->owner_lookup_hash, $hash);
                    abort_unless($own ? ($view->visibility === 'personal' || $caps['operate'] || $caps['manage']) : ($view->visibility === 'shared' && $caps['manage']), 403);
                }
            }
            if (str_starts_with($operation, 'note.') || $operation === 'assignment') {
                $issue = $this->issues->issue($i->id, $id, true);
                abort_unless($issue !== null, 404);
                if ($operation !== 'note.create' && $operation !== 'assignment') {
                    $note = Note::query()->where('installation_id', $i->id)->where('issue_id', $issue->id)->where('public_id', $values['note'])->firstOrFail();
                    $own = hash_equals($note->author_lookup_hash, $hash);
                    abort_unless($operation === 'note.delete' ? ($caps['manage'] || ($own && $caps['operate'])) : ($own && $caps['operate']), 403);
                }
                if ($receipt->state === 'completed') {
                    return ['id' => $receipt->result_public_id, 'version' => $receipt->resulting_row_version, 'replayed' => true];
                }
                $result = $operation === 'assignment' ? $this->assign($issue, $values, $actor->key, $receipt->public_id) : $this->writeNote($operation, $issue, $values, $hash, $actor->key, $caps, $receipt->public_id);
            } else {
                if ($receipt->state === 'completed') {
                    return ['id' => $receipt->result_public_id, 'version' => $receipt->resulting_row_version, 'replayed' => true];
                }
                $result = match (true) {
                    str_starts_with($operation, 'view.') => $this->writeView($operation, $i->id, $id, $values, $hash, $actor->key, $caps),$operation === 'preferences' => $this->writePreferences($i->id, $values, $hash, $actor->key),default => throw new \InvalidArgumentException('Unknown collaboration operation')
                };
            }
            $receipt->state = 'completed';
            $receipt->result_type = $result['type'];
            $receipt->result_public_id = $result['id'];
            $receipt->resulting_row_version = $result['version'];
            $receipt->completed_at = CarbonImmutable::now('UTC');
            $receipt->save();

            return $result + ['replayed' => false];
        }, 5);
    }

    private function writeNote(string $op, Issue $issue, array $v, string $hash, string $actor, array $caps, string $action): array
    {
        if ($op === 'note.create') {
            $n = Note::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $issue->installation_id,
                'issue_id' => $issue->id,
                'author_actor_key' => $actor,
                'author_lookup_hash' => $hash,
                'body_ciphertext' => Crypt::encryptString($this->noteText($v['body'])),
            ]);
            $type = 'note.added';
        } else {
            $n = Note::query()->where('installation_id', $issue->installation_id)->where('issue_id', $issue->id)->where('public_id', $v['note'])->lockForUpdate()->firstOrFail();
            $own = hash_equals($n->author_lookup_hash, $hash);
            abort_unless($op === 'note.delete' ? ($caps['manage'] || ($own && $caps['operate'])) : ($own && $caps['operate']), 403);
            $this->version($n->row_version, $v['version']);
            if ($op === 'note.delete') {
                if ($n->deleted_at) {
                    return ['type' => 'note', 'id' => $n->public_id, 'version' => $n->row_version];
                }$n->body_ciphertext = null;
                $n->deleted_at = CarbonImmutable::now('UTC');
                $n->deleted_by_actor_key = $actor;
                $type = 'note.deleted';
            } else {
                if ($n->deleted_at) {
                    throw new Conflict('This note was removed. Copy your draft or create a new note.');
                }$n->body_ciphertext = Crypt::encryptString($this->noteText($v['body']));
                $n->edited_at = CarbonImmutable::now('UTC');
                $type = 'note.edited';
            }
            $n->row_version++;
            $n->save();
        }
        $this->audit($issue, $type, $action, $actor, ['note_id' => $n->public_id], $type === 'note.added');

        return ['type' => 'note', 'id' => $n->public_id, 'version' => $n->row_version];
    }

    private function writeView(string $op, int $i, ?string $id, array $v, string $hash, string $actor, array $caps): array
    {
        if ($op === 'view.create') {
            $this->sharedPermission($v['visibility'], $caps);
            $row = SavedView::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i,
                'owner_actor_key' => $actor,
                'owner_lookup_hash' => $hash,
                'name' => $v['name'],
                'visibility' => $v['visibility'],
                'filters' => $v['filters'],
                'sort_key' => $v['sort'],
                'relative_range' => ['key' => $v['range']],
            ]);
        } else {
            $row = $this->viewRow($i, $id, $hash, true);
            $own = hash_equals($row->owner_lookup_hash, $hash);
            abort_unless($own ? ($row->visibility === 'personal' || $caps['operate'] || $caps['manage']) : ($row->visibility === 'shared' && $caps['manage']), 403);
            $this->version($row->row_version, $v['version']);
            if ($op === 'view.delete') {
                $public = $row->public_id;
                $version = $row->row_version;
                $row->delete();

                return ['type' => 'saved_view', 'id' => $public, 'version' => $version];
            }
            if ($op === 'view.rename') {
                $row->name = trim($this->redactor->text($v['name'], $this->policy->read()['privacy']['redact_patterns']));
                if ($row->name === '' || mb_strlen($row->name) > 80) {
                    throw ValidationException::withMessages(['name' => 'Enter 1–80 characters after privacy redaction.']);
                }
            } else {
                if (! $own && $v['visibility'] !== 'shared') {
                    abort(403);
                }$this->sharedPermission($v['visibility'], $caps);
                $row->name = $v['name'];
                $row->visibility = $v['visibility'];
                $row->filters = $v['filters'];
                $row->sort_key = $v['sort'];
                $row->relative_range = ['key' => $v['range']];
                $row->filter_schema_version = 1;
            }
            $row->row_version++;
            $row->save();
        }

        return ['type' => 'saved_view', 'id' => $row->public_id, 'version' => $row->row_version];
    }

    private function sharedPermission(string $visibility, array $caps): void
    {
        if ($visibility === 'shared') {
            abort_unless($caps['operate'] || $caps['manage'], 403);
        }
    }

    private function writePreferences(int $i, array $v, string $hash, string $actor): array
    {
        $shortcuts = $v['shortcuts'];
        $refs = array_column($shortcuts, 'ref');
        if ($v['default_view_ref'] !== null) {
            $refs[] = $v['default_view_ref'];
        }$allowed = $this->references($i, $hash, $refs);
        foreach ($refs as $ref) {
            abort_unless(isset($allowed[$ref]), 404);
        }
        $row = ViewPreference::query()->createOrFirst(['installation_id' => $i, 'actor_lookup_hash' => $hash], ['actor_key' => $actor, 'shortcuts' => [], 'row_version' => 0]);
        $row = ViewPreference::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
        $this->version($row->row_version, $v['version']);
        usort($shortcuts, fn ($a, $b) => $a['position'] <=> $b['position']);
        foreach ($shortcuts as $index => &$s) {
            $s['position'] = $index;
        }unset($s);
        $row->shortcuts = $shortcuts;
        $row->default_view_ref = $v['default_view_ref'];
        $row->row_version++;
        $row->save();

        return ['type' => 'view_preferences', 'id' => null, 'version' => $row->row_version];
    }

    private function assign(Issue $issue, array $v, string $actor, string $action): array
    {
        if (! $this->assignments->enabled()) {
            throw new Conflict('Assignments were disabled. Reload this page.');
        }$this->version($issue->state_version, $v['version']);
        $key = $v['assignee'];
        if ($key !== $issue->assignee_actor_key) {
            $before = $issue->assignee_actor_key;
            $issue->assignee_actor_key = $key;
            $issue->assignee_lookup_hash = $key === null ? null : $this->identity->hash($key);
            $issue->state_version++;
            $issue->save();
            $this->audit($issue, $key === null ? 'error.unassigned' : 'error.assigned', $action, $actor, ['previous_assignee' => $before, 'assignee' => $key], true);
        }

        return ['type' => 'issue', 'id' => $issue->public_id, 'version' => $issue->state_version];
    }
}
