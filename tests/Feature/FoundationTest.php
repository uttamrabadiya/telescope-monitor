<?php

namespace TelescopeMonitor\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Foundation\DashboardAssets;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Tests\TestCase;

final class FoundationTest extends TestCase
{
    public function test_access_is_default_deny_for_shell_assets_and_json(): void
    {
        $this->get('/exception-monitor')->assertForbidden();
        $this->getJson('/exception-monitor/api/v1/bootstrap')->assertForbidden();
        $this->get('/exception-monitor/assets/unknown/monitor.js')->assertForbidden();
    }

    public function test_viewer_can_read_bootstrap_but_cannot_inspect_or_mutate_settings(): void
    {
        $this->authorize(operate: false, manage: false);
        $this->getJson('/exception-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.capabilities.manage', false)->assertJsonPath('data.health', []);
        $this->getJson('/exception-monitor/api/v1/settings/general')->assertForbidden();
        $this->patchJson('/exception-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['display_name' => 'Unauthorized']])->assertForbidden();
        $this->postJson('/exception-monitor/api/v1/health/check')->assertForbidden();
    }

    public function test_administrator_preferences_persist_and_advance_revision(): void
    {
        $this->authorize();
        $this->getJson('/exception-monitor/api/v1/settings/general')->assertOk()->assertJsonPath('data.version', 0);
        $this->patchJson('/exception-monitor/api/v1/settings/general', [
            'version' => 0,
            'values' => [
                'display_name' => 'Payments application',
                'timezone' => 'Asia/Kolkata',
            ],
        ])->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.config_revision', 2);
        $this->getJson('/exception-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.application.display_name', 'Payments application')->assertJsonPath('data.application.timezone', 'Asia/Kolkata');
        $this->assertSame(1, DB::table('exception_monitor_installations')->count());
    }

    public function test_conflicting_edits_preserve_the_last_valid_configuration(): void
    {
        $this->authorize();
        $this->patchJson('/exception-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['display_name' => 'First edit']])->assertOk();
        $this->patchJson('/exception-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['display_name' => 'Stale edit']])->assertConflict()->assertJsonPath('error.code', 'version_conflict');
        $this->getJson('/exception-monitor/api/v1/settings/general')->assertJsonPath('data.values.display_name', 'First edit');
    }

    public function test_deployment_locks_and_unknown_fields_are_enforced_server_side(): void
    {
        $this->authorize();
        config(['exception-monitor.locked' => ['privacy.identify_users' => false]]);
        $this->getJson('/exception-monitor/api/v1/settings/privacy')->assertJsonPath('data.fields.identify_users.locked', true)->assertJsonPath('data.values.identify_users', false);
        $this->patchJson('/exception-monitor/api/v1/settings/privacy', ['version' => 0, 'values' => ['identify_users' => true]])->assertUnprocessable()->assertJsonValidationErrors('values.identify_users');
        $this->patchJson('/exception-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['dashboard_url' => 'https://evil.test']])->assertUnprocessable()->assertJsonValidationErrors('values.dashboard_url');
        $this->patchJson('/exception-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['secret' => 'hidden']])->assertUnprocessable();
    }

    public function test_validated_scalar_preferences_have_canonical_json_types(): void
    {
        $this->authorize();
        $this->patchJson('/exception-monitor/api/v1/settings/privacy', [
            'version' => 0,
            'values' => ['identify_users' => '1'],
        ])->assertOk()->assertJsonPath('data.values.identify_users', true);
        $this->patchJson('/exception-monitor/api/v1/settings/retention', [
            'version' => 0,
            'values' => ['occurrence_days' => '14'],
        ])->assertOk()->assertJsonPath('data.values.occurrence_days', 14);
    }

    public function test_settings_validation_rejects_invalid_patterns_timezones_and_retention(): void
    {
        $this->authorize();
        $this->patchJson('/exception-monitor/api/v1/settings/privacy', ['version' => 0, 'values' => ['redact_patterns' => ['not-a-regex']]])->assertUnprocessable();
        $this->patchJson('/exception-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['timezone' => 'Neverland']])->assertUnprocessable();
        $this->patchJson('/exception-monitor/api/v1/settings/retention', ['version' => 0, 'values' => ['occurrence_days' => 0]])->assertUnprocessable();
    }

    public function test_assets_are_manifest_allowlisted_and_deep_links_do_not_swallow_unknown_api(): void
    {
        $this->authorize();
        $assets = app(DashboardAssets::class);
        $this->get('/exception-monitor/settings/privacy')->assertOk()->assertSee('exception-monitor-app');
        $this->get('/exception-monitor/assets/'.$assets->build().'/'.$assets->entry()['file'])->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8');
        $this->get('/exception-monitor/assets/'.$assets->build().'/composer.json')->assertNotFound();
        $this->get('/exception-monitor/assets/old/'.$assets->entry()['file'])->assertNotFound();
        $this->getJson('/exception-monitor/api/v1/missing')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get('/exception-monitor/not-a-page')->assertNotFound();
    }

    public function test_health_does_not_claim_running_workers_or_captured_errors(): void
    {
        $this->authorize();
        $checks = $this->postJson('/exception-monitor/api/v1/health/check')->assertOk()->json('data');
        $indexed = collect($checks)->keyBy('key');
        $this->assertSame('unverified', $indexed['capture']['state']);
        $this->assertSame('unverified', $indexed['worker']['state']);
        $this->assertSame('configuration', $indexed['queue']['kind']);
        $this->assertSame('unverified', DB::table('exception_monitor_health_checks')->where('check_key', 'worker')->value('state'));
        $this->assertNull(DB::table('exception_monitor_health_checks')->where('check_key', 'worker')->value('last_success_at'));
        $this->getJson('/exception-monitor/api/v1/bootstrap')->assertJsonPath('data.features.capture', true)->assertJsonPath('data.features.integrations', true);
    }

    public function test_setup_position_is_resumable_without_claiming_completion(): void
    {
        $this->authorize();
        $this->patchJson('/exception-monitor/api/v1/setup', ['step' => 2])->assertOk();
        $this->getJson('/exception-monitor/api/v1/bootstrap')->assertJsonPath('data.installation.setup.current_step', 2)->assertJsonMissingPath('data.installation.setup.completed');
        $this->patchJson('/exception-monitor/api/v1/setup', ['step' => 8])->assertUnprocessable();
    }

    public function test_missing_tables_are_reported_without_attempting_preference_writes(): void
    {
        $this->authorize();
        Schema::drop('exception_monitor_settings');
        $this->getJson('/exception-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.storage', 'missing_migrations');
        $this->patchJson('/exception-monitor/api/v1/settings/general', [
            'version' => 0,
            'values' => ['display_name' => 'Not saved'],
        ])->assertConflict()->assertJsonPath('error.code', 'missing_migrations');
    }

    public function test_legacy_schema_is_detected_without_adopting_or_deleting_data(): void
    {
        (require __DIR__.'/../../database/migrations/2026_11_11_000500_create_exception_monitor_operations.php')->down();
        (require __DIR__.'/../../database/migrations/2026_11_11_000400_create_exception_monitor_integrations.php')->down();
        (require __DIR__.'/../../database/migrations/2026_11_11_000300_create_exception_monitor_alerting.php')->down();
        (require __DIR__.'/../../database/migrations/2026_11_11_000200_create_exception_monitor_collaboration.php')->down();
        (require __DIR__.'/../../database/migrations/2026_11_11_000100_create_exception_monitor_capture.php')->down();
        Schema::drop('exception_monitor_action_receipts');
        Schema::drop('exception_monitor_health_checks');
        Schema::drop('exception_monitor_settings');
        Schema::drop('exception_monitor_installations');
        Schema::create('exception_monitor_issues', fn ($table) => $table->id());
        DB::table('exception_monitor_issues')->insert(['id' => 42]);
        $this->assertSame('prototype_detected', app(InstallationRepository::class)->storageState());
        $this->artisan('exception-monitor:install')->assertFailed();
        $this->assertSame(42, DB::table('exception_monitor_issues')->value('id'));
        Schema::drop('exception_monitor_issues');
    }
}
