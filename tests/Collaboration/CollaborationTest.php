<?php

namespace TelescopeMonitor\Tests\Collaboration;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Collaboration\AssignmentDirectory;
use TelescopeMonitor\Data\Actor;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class FakeDirectory implements AssignmentDirectory
{
    public static bool $down = false;

    public static bool $eligible = true;

    public function search(string $query, int $limit): array
    {
        if (self::$down) {
            throw new \RuntimeException('credential=do-not-disclose');
        }

        return self::$eligible ? [new Actor('testing:2', 'Second operator')] : [];
    }

    public function find(string $key): ?Actor
    {
        if (self::$down) {
            throw new \RuntimeException('credential=do-not-disclose');
        }

        return self::$eligible && in_array($key, ['testing:2', 'me'], true) ? new Actor($key, 'Second operator') : null;
    }
}
final class CollaborationTest extends TestCase
{
    use CapturesExceptions;

    private string $issue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        FakeDirectory::$down = false;
        FakeDirectory::$eligible = true;
        $this->capture();
        $this->process();
        $this->issue = DB::table('telescope_monitor_issues')->value('public_id');
    }

    private function actor(string $key): void
    {
        $this->app->bind(ActorResolver::class, fn () => new class ($key) implements ActorResolver
        {
            public function __construct(private string $key) {}

            public function current(): ?Actor
            {
                return new Actor($this->key, 'Actor '.$this->key);
            }

            public function resolve(Actor $actor): ?Authenticatable
            {
                return new GenericUser(['id' => $actor->key, 'name' => 'Name '.$actor->key]);
            }
        });
        $this->app->forgetScopedInstances();
    }

    private function note(string $body = 'Investigation note', ?string $key = null)
    {
        return $this->postJson('/telescope-monitor/api/v1/issues/'.$this->issue.'/notes', ['client_key' => $key ?? (string) Str::uuid(), 'telescope_monitor_private' => ['body' => $body]]);
    }

    private function createView(array $extra = [], ?string $key = null)
    {
        return $this->postJson('/telescope-monitor/api/v1/views', array_merge([
            'client_key' => $key ?? (string) Str::uuid(),
            'name' => 'My triage',
            'visibility' => 'personal',
            'filters' => ['status' => 'unresolved'],
            'sort' => 'last_seen',
            'range' => '24h',
        ], $extra));
    }

    private function notesUrl(): string
    {
        return '/telescope-monitor/api/v1/issues/'.$this->issue.'/notes';
    }

    public function test_note_body_is_sanitized_encrypted_and_never_copied_to_activity_receipt_or_event(): void
    {
        $body = "First line\npassword=private-value\n<script>alert('x')</script> https://example.test/runbook";
        $r = $this->note($body)->assertOk();
        $this->assertSame(1, $r->json('telescope_monitor_private.version'));
        $cipher = DB::table('telescope_monitor_notes')->value('body_ciphertext');
        $this->assertStringNotContainsString('First line', $cipher);
        $plain = Crypt::decryptString($cipher);
        $this->assertStringContainsString("First line\npassword=[REDACTED]", $plain);
        $this->assertStringNotContainsString('private-value', $plain);
        foreach (['telescope_monitor_activity', 'telescope_monitor_action_receipts', 'telescope_monitor_alert_events'] as $table) {
            $all = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('First line', $all);
            $this->assertStringNotContainsString('private-value', $all);
        }
        $events = DB::table('telescope_monitor_alert_events')->where('event_type', 'note.added')->get();
        $this->assertCount(1, $events);
        $snapshot = json_decode(Crypt::decryptString($events[0]->payload_ciphertext), true);
        $this->assertSame($r->json('telescope_monitor_private.id'), $snapshot['note_id']);
        $this->assertStringNotContainsString('First line', json_encode($snapshot));
        $this->getJson($this->notesUrl())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('telescope_monitor_private.items.0.state', 'available');
    }

    public function test_note_idempotency_versions_author_rules_and_delete_tombstone(): void
    {
        $key = (string) Str::uuid();
        $a = $this->note('Original', $key)->assertOk()->json('telescope_monitor_private.id');
        $this->note('Original', $key)->assertOk()->assertJsonPath('telescope_monitor_private.replayed', true);
        $this->note('Different', $key)->assertConflict();
        $url = $this->notesUrl().'/'.$a;
        $edit = ['client_key' => (string) Str::uuid(), 'version' => 1, 'telescope_monitor_private' => ['body' => 'Edited']];
        $this->patchJson($url, $edit)->assertOk();
        $this->patchJson($url, array_replace($edit, ['client_key' => (string) Str::uuid()]))->assertConflict();
        $this->actor('testing:2');
        $this->patchJson($url, ['client_key' => (string) Str::uuid(), 'version' => 2, 'telescope_monitor_private' => ['body' => 'Forbidden']])->assertForbidden();
        $this->authorize(manage: false);
        $this->deleteJson($url, ['client_key' => (string) Str::uuid(), 'version' => 2])->assertForbidden();
        $this->authorize();
        $delete = ['client_key' => (string) Str::uuid(), 'version' => 2];
        $this->deleteJson($url, $delete)->assertOk();
        $this->deleteJson($url, $delete)->assertOk();
        $this->assertNull(DB::table('telescope_monitor_notes')->value('body_ciphertext'));
        $this->assertSame(1, DB::table('telescope_monitor_activity')->where('event_type', 'note.deleted')->count());
        $this->getJson($this->notesUrl())->assertJsonPath('telescope_monitor_private.items.0.state', 'deleted')->assertJsonPath('telescope_monitor_private.items.0.body', null);
    }

    public function test_note_validation_viewer_read_and_parent_scope(): void
    {
        $this->note('   ')->assertUnprocessable();
        $this->note(str_repeat('x', 10001))->assertUnprocessable();
        $a = $this->note()->assertOk()->json('telescope_monitor_private.id');
        $this->authorize(operate: false, manage: false);
        $this->getJson($this->notesUrl())->assertOk();
        $this->note()->assertForbidden();
        $this->authorize();
        $this->capture(class: 'OtherIssue');
        $this->process();
        $other = DB::table('telescope_monitor_issues')->where('public_id', '!=', $this->issue)->value('public_id');
        $this->deleteJson('/telescope-monitor/api/v1/issues/'.$other.'/notes/'.$a, ['client_key' => (string) Str::uuid(), 'version' => 1])->assertNotFound();
    }

    public function test_personal_views_are_private_even_to_administrators_and_shared_edits_are_scoped(): void
    {
        $personal = $this->createView()->assertOk()->json('telescope_monitor_private.id');
        $shared = $this->createView(['name' => 'Shared', 'visibility' => 'shared'])->assertOk()->json('telescope_monitor_private.id');
        $this->actor('testing:2');
        $this->getJson('/telescope-monitor/api/v1/views/'.$personal)->assertNotFound();
        $this->getJson('/telescope-monitor/api/v1/views')->assertJsonPath('telescope_monitor_private.total', '1');
        $this->getJson('/telescope-monitor/api/v1/issues?view='.$personal)->assertNotFound();
        $this->authorize(operate: true, manage: false);
        $this->patchJson('/telescope-monitor/api/v1/views/'.$shared.'/name', ['client_key' => (string) Str::uuid(), 'version' => 1, 'name' => 'Stolen'])->assertForbidden();
        $this->authorize();
        $this->patchJson('/telescope-monitor/api/v1/views/'.$shared.'/name', ['client_key' => (string) Str::uuid(), 'version' => 1, 'name' => 'Moderated'])->assertOk();
        $this->createView(['name' => 'Copy', 'visibility' => 'personal'])->assertOk();
        $this->getJson('/telescope-monitor/api/v1/views')->assertJsonPath('telescope_monitor_private.total', '2');
    }

    public function test_viewers_manage_personal_views_but_cannot_publish_shared_views(): void
    {
        $this->authorize(operate: false, manage: false);
        $r = $this->createView()->assertOk();
        $id = $r->json('telescope_monitor_private.id');
        $this->createView(['visibility' => 'shared'])->assertForbidden();
        $this->patchJson('/telescope-monitor/api/v1/views/'.$id.'/name', ['client_key' => (string) Str::uuid(), 'version' => 1, 'name' => 'Renamed'])->assertOk();
        $body = ['client_key' => (string) Str::uuid(), 'version' => 2];
        $this->deleteJson('/telescope-monitor/api/v1/views/'.$id, $body)->assertOk();
        $this->deleteJson('/telescope-monitor/api/v1/views/'.$id, $body)->assertOk();
    }

    public function test_saved_definition_applies_relative_range_and_modified_filters_do_not_overwrite_it(): void
    {
        $id = $this->createView(['filters' => ['status' => 'resolved'], 'range' => '7d'])->assertOk()->json('telescope_monitor_private.id');
        $this->getJson('/telescope-monitor/api/v1/issues?view='.$id)->assertOk()->assertJsonPath('telescope_monitor_private.total', '0')->assertJsonPath('telescope_monitor_private.window.range', '7d');
        $this->getJson('/telescope-monitor/api/v1/issues?view='.$id.'&modified=1&status=unresolved&range=24h')->assertJsonPath('telescope_monitor_private.total', '1');
        $this->getJson('/telescope-monitor/api/v1/views/'.$id)->assertJsonPath('telescope_monitor_private.definition.filters.status', 'resolved');
        $this->createView(['filters' => ['sql' => 'select *']])->assertUnprocessable();
        $this->createView(['filters' => ['note_body' => 'never-store']])->assertUnprocessable();
    }

    public function test_preferences_recheck_access_and_trim_deleted_views_without_granting_access(): void
    {
        $personal = $this->createView()->assertOk()->json('telescope_monitor_private.id');
        $this->actor('testing:2');
        $prefs = [
            'client_key' => (string) Str::uuid(),
            'version' => 0,
            'shortcuts' => [['ref' => $personal, 'favorite' => true, 'position' => 0]],
            'default_view_ref' => $personal,
        ];
        $this->putJson('/telescope-monitor/api/v1/view-preferences', $prefs)->assertNotFound();
        $this->actor('testing:1');
        $this->putJson('/telescope-monitor/api/v1/view-preferences', $prefs)->assertOk();
        $this->getJson('/telescope-monitor/api/v1/view-preferences')->assertJsonPath('telescope_monitor_private.default_view_ref', $personal);
        $this->deleteJson('/telescope-monitor/api/v1/views/'.$personal, ['client_key' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->getJson('/telescope-monitor/api/v1/view-preferences')->assertJsonPath('telescope_monitor_private.default_view_ref', null)->assertJsonPath('telescope_monitor_private.shortcuts', []);
    }

    public function test_unsupported_view_has_repair_state_and_does_not_execute_filters(): void
    {
        $id = $this->createView()->assertOk()->json('telescope_monitor_private.id');
        DB::table('telescope_monitor_saved_views')->where('public_id', $id)->update(['filter_schema_version' => 99]);
        $this->getJson('/telescope-monitor/api/v1/views/'.$id)->assertJsonPath('telescope_monitor_private.state', 'needs_repair');
        $this->getJson('/telescope-monitor/api/v1/issues?view='.$id)->assertConflict();
        $this->patchJson('/telescope-monitor/api/v1/views/'.$id, [
            'client_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Repaired',
            'visibility' => 'personal',
            'filters' => [],
            'sort' => 'first_seen',
            'range' => '1h',
        ])->assertOk();
        $this->getJson('/telescope-monitor/api/v1/views/'.$id)->assertJsonPath('telescope_monitor_private.state', 'ready');
    }

    public function test_optional_assignment_failure_removal_clear_and_disable_preserve_reference(): void
    {
        $url = '/telescope-monitor/api/v1/issues/'.$this->issue.'/assignment';
        $payload = ['client_key' => (string) Str::uuid(), 'version' => 1, 'assignee' => 'testing:2'];
        $this->postJson($url, $payload)->assertConflict();
        config(['telescope-monitor.assignments' => ['enabled' => true, 'directory' => FakeDirectory::class]]);
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->getJson('/telescope-monitor/api/v1/issues?assignee=testing:2')->assertJsonPath('telescope_monitor_private.total', '1');
        FakeDirectory::$eligible = false;
        $this->getJson('/telescope-monitor/api/v1/issues/'.$this->issue)->assertJsonPath('telescope_monitor_private.issue.assignee.state', 'ineligible');
        FakeDirectory::$down = true;
        $this->getJson('/telescope-monitor/api/v1/assignment-directory')->assertStatus(503)->assertDontSee('do-not-disclose');
        $this->postJson($url, $payload)->assertOk();
        config(['telescope-monitor.assignments.enabled' => false]);
        $this->getJson('/telescope-monitor/api/v1/issues/'.$this->issue)->assertJsonPath('telescope_monitor_private.issue.assignee', null);
        $this->assertSame('testing:2', DB::table('telescope_monitor_issues')->value('assignee_actor_key'));
        config(['telescope-monitor.assignments.enabled' => true]);
        $this->postJson($url, ['client_key' => (string) Str::uuid(), 'version' => 2, 'assignee' => null])->assertOk();
        $this->assertNull(DB::table('telescope_monitor_issues')->value('assignee_actor_key'));
    }

    public function test_default_and_shortcuts_are_versioned_ordered_and_actor_specific(): void
    {
        $payload = [
            'client_key' => (string) Str::uuid(),
            'version' => 0,
            'shortcuts' => [
                ['ref' => 'builtin:resolved', 'favorite' => '0', 'position' => 8],
                ['ref' => 'builtin:unresolved', 'favorite' => true, 'position' => 2],
            ],
            'default_view_ref' => 'builtin:resolved',
        ];
        $this->putJson('/telescope-monitor/api/v1/view-preferences', $payload)->assertOk();
        $this->putJson('/telescope-monitor/api/v1/view-preferences', $payload)->assertOk();
        $this->getJson('/telescope-monitor/api/v1/view-preferences')->assertJsonPath('telescope_monitor_private.shortcuts.0.ref', 'builtin:unresolved')->assertJsonPath('telescope_monitor_private.shortcuts.1.favorite', false)->assertJsonPath('telescope_monitor_private.shortcuts.0.position', 0);
        $this->getJson('/telescope-monitor/api/v1/issues')->assertJsonPath('telescope_monitor_private.total', '0');
        $this->getJson('/telescope-monitor/api/v1/issues?status=unresolved')->assertJsonPath('telescope_monitor_private.total', '1');
        $payload['client_key'] = (string) Str::uuid();
        $this->putJson('/telescope-monitor/api/v1/view-preferences', $payload)->assertConflict();
        $this->actor('testing:2');
        $this->getJson('/telescope-monitor/api/v1/view-preferences')->assertJsonPath('telescope_monitor_private.version', 0)->assertJsonPath('telescope_monitor_private.default_view_ref', null);
    }

    public function test_shared_to_personal_revokes_shortcuts_and_default_without_exposing_private_definition(): void
    {
        $id = $this->createView(['visibility' => 'shared'])->assertOk()->json('telescope_monitor_private.id');
        $this->actor('testing:2');
        $this->putJson('/telescope-monitor/api/v1/view-preferences', [
            'client_key' => (string) Str::uuid(),
            'version' => 0,
            'shortcuts' => [['ref' => $id, 'favorite' => true, 'position' => 0]],
            'default_view_ref' => $id,
        ])->assertOk();
        $this->actor('testing:1');
        $this->patchJson('/telescope-monitor/api/v1/views/'.$id, [
            'client_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Now personal',
            'visibility' => 'personal',
            'filters' => ['status' => 'resolved'],
            'sort' => 'last_seen',
            'range' => '7d',
        ])->assertOk();
        $this->actor('testing:2');
        $this->getJson('/telescope-monitor/api/v1/view-preferences')->assertJsonPath('telescope_monitor_private.shortcuts', [])->assertJsonPath('telescope_monitor_private.default_view_ref', null);
        $this->getJson('/telescope-monitor/api/v1/views/'.$id)->assertNotFound();
        $this->getJson('/telescope-monitor/api/v1/issues?view='.$id)->assertNotFound();
    }

    public function test_view_conflict_preserves_definition_and_rename_does_not_replace_filters(): void
    {
        $id = $this->createView(['filters' => ['class' => 'RuntimeException'], 'range' => '7d'])->assertOk()->json('telescope_monitor_private.id');
        $this->patchJson('/telescope-monitor/api/v1/views/'.$id.'/name', ['client_key' => (string) Str::uuid(), 'version' => 1, 'name' => 'Renamed'])->assertOk();
        $this->patchJson('/telescope-monitor/api/v1/views/'.$id, [
            'client_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Stale',
            'visibility' => 'personal',
            'filters' => [],
            'sort' => 'first_seen',
            'range' => '1h',
        ])->assertConflict();
        $this->getJson('/telescope-monitor/api/v1/views/'.$id)->assertJsonPath('telescope_monitor_private.name', 'Renamed')->assertJsonPath('telescope_monitor_private.definition.filters.class', 'RuntimeException')->assertJsonPath('telescope_monitor_private.definition.range', '7d');
    }

    public function test_note_rollback_and_source_request_protection(): void
    {
        $sentinel = 'PRIVATE-NOTE-SENTINEL';
        $this->note($sentinel)->assertOk();
        Telescope::store(app(EntriesRepository::class));
        $this->assertStringNotContainsString($sentinel, json_encode(DB::table('telescope_entries')->get()));
        DB::table('telescope_monitor_notes')->delete();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'telescope_monitor_activity')) {
                throw new \RuntimeException('Write failed with secret');
            }
        });
        $this->note('Roll back this draft')->assertStatus(503)->assertDontSee('Write failed with secret');
        $this->assertDatabaseCount('telescope_monitor_notes', 0);
        $this->assertSame(1, DB::table('telescope_monitor_action_receipts')->where('operation', 'collaboration.note.create')->count());
    }

    public function test_notes_survive_evidence_expiry_and_unreadable_bodies_have_explicit_state(): void
    {
        $this->note('Durable note')->assertOk();
        $this->travel(100)->days();
        app(CaptureOperations::class)->prune(100);
        $this->getJson($this->notesUrl())->assertJsonPath('telescope_monitor_private.items.0.body', 'Durable note');
        DB::table('telescope_monitor_notes')->update(['body_ciphertext' => 'invalid']);
        $this->getJson($this->notesUrl())->assertJsonPath('telescope_monitor_private.items.0.state', 'unreadable')->assertJsonPath('telescope_monitor_private.items.0.body', null);
    }

    public function test_opaque_actor_keys_are_case_sensitive_despite_database_collation(): void
    {
        $this->actor('Operators:Casey');
        $view = $this->createView()->assertOk()->json('telescope_monitor_private.id');
        $note = $this->note()->assertOk()->json('telescope_monitor_private.id');
        $this->actor('operators:casey');
        $this->getJson('/telescope-monitor/api/v1/views/'.$view)->assertNotFound();
        $this->patchJson($this->notesUrl().'/'.$note, ['client_key' => (string) Str::uuid(), 'version' => 1, 'telescope_monitor_private' => ['body' => 'Must not edit']])->assertForbidden();
    }

    public function test_generation_and_installation_state_fence_stale_operations(): void
    {
        $key = (string) Str::uuid();
        $this->note('Once', $key)->assertOk();
        DB::table('telescope_monitor_installations')->increment('generation');
        $this->note('Once', $key)->assertConflict();
        DB::table('telescope_monitor_installations')->update(['state' => 'draining']);
        $this->createView()->assertConflict();
        $this->assertDatabaseCount('telescope_monitor_notes', 1);
    }

    public function test_explicit_actor_filter_does_not_collide_with_assigned_to_me_alias(): void
    {
        config(['telescope-monitor.assignments' => ['enabled' => true, 'directory' => FakeDirectory::class]]);
        $this->postJson('/telescope-monitor/api/v1/issues/'.$this->issue.'/assignment', ['client_key' => (string) Str::uuid(), 'version' => 1, 'assignee' => 'me'])->assertOk();
        $this->getJson('/telescope-monitor/api/v1/issues?assignee=actor:me')->assertJsonPath('telescope_monitor_private.total', '1');
        $this->getJson('/telescope-monitor/api/v1/issues?assignee=me')->assertJsonPath('telescope_monitor_private.total', '0');
    }
}
