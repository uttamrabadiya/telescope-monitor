<?php

namespace TelescopeMonitor\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Auth\HostActorResolver;
use TelescopeMonitor\Auth\TelescopeAccess;
use TelescopeMonitor\Capture\CaptureProbes;
use TelescopeMonitor\Data\Actor;
use TelescopeMonitor\Foundation\DashboardAssets;
use TelescopeMonitor\Http\Middleware\Authorize;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\Fixtures\StrictRequestForgery;
use TelescopeMonitor\Tests\TestCase;

final class TelescopeAuthorizationTest extends TestCase
{
    use CapturesExceptions;

    private mixed $previousAuth;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('telescope-monitor.authorization.driver', 'telescope');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations');
    }

    protected function setUp(): void
    {
        $this->previousAuth = Telescope::$authUsing;
        parent::setUp();
        $this->app->bind(ActorResolver::class, HostActorResolver::class);
        Gate::define('viewTelescope', fn ($user) => $user->email === 'operator@example.test');
        Telescope::auth(fn ($request) => app()->environment('local') || Gate::check('viewTelescope', [$request->user()]));
        Telescope::startRecording(false);
    }

    protected function tearDown(): void
    {
        Telescope::$authUsing = $this->previousAuth;
        parent::tearDown();
    }

    private function user(string $email = 'operator@example.test'): Authenticatable
    {
        $class = config('auth.providers.users.model');

        return $class::forceCreate(['name' => 'Operator', 'email' => $email, 'password' => bcrypt('test-only-password')]);
    }

    private function local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withSession(['_token' => 'csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'csrf-fixture');
    }

    public function test_local_guest_has_telescope_access_without_login_or_host_user_records(): void
    {
        $this->local();
        $this->get('/telescope-monitor/settings/general')->assertOk();
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.actor.label', TelescopeAccess::SHARED_LABEL)->assertJsonPath('data.capabilities.manage', true);
        $assets = app(DashboardAssets::class);
        $this->get('/telescope-monitor/assets/'.$assets->build().'/'.$assets->entry()['file'])->assertOk();
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(Auth::user());
        $this->assertNotContains('auth', config('telescope-monitor.middleware'));
    }

    public function test_nonlocal_access_uses_the_native_view_telescope_gate(): void
    {
        $this->get('/telescope-monitor')->assertForbidden();
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertForbidden();
        $this->actingAs($this->user());
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.actor.label', 'Operator')->assertJsonPath('data.capabilities.operate', true);
        $this->actingAs($this->user('denied@example.test'));
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertForbidden();
    }

    public function test_custom_telescope_callback_is_honored_for_shell_api_and_assets(): void
    {
        $this->local();
        Telescope::auth(fn ($request) => $request->header('X-Telescope-Test') === 'allow');
        $this->get('/telescope-monitor')->assertForbidden();
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertForbidden();
        $assets = app(DashboardAssets::class);
        $this->get('/telescope-monitor/assets/'.$assets->build().'/'.$assets->entry()['file'])->assertForbidden();
        $this->withHeader('X-Telescope-Test', 'allow')->getJson('/telescope-monitor/api/v1/bootstrap')->assertOk();
    }

    public function test_local_shared_identity_can_write_notes_and_settings_with_csrf_preserved(): void
    {
        $this->local();
        $this->capture();
        $this->process();
        $id = DB::table('telescope_monitor_issues')->value('public_id');
        $this->postJson('/telescope-monitor/api/v1/issues/'.$id.'/notes', ['client_key' => (string) Str::uuid(), 'telescope_monitor_private' => ['body' => 'Local developer investigation']])->assertOk();
        $this->assertSame(TelescopeAccess::SHARED_KEY, DB::table('telescope_monitor_notes')->value('author_actor_key'));
        $this->app->bind(PreventRequestForgery::class, StrictRequestForgery::class);
        $this->flushHeaders();
        $this->patchJson('/telescope-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['display_name' => 'Rejected']])->assertStatus(419);
        $this->withSession(['_token' => 'csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'csrf-fixture')->patchJson('/telescope-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['display_name' => 'Accepted']])->assertOk();
        $this->assertSame(TelescopeAccess::SHARED_KEY, DB::table('telescope_monitor_settings')->value('updated_by_actor_key'));
    }

    public function test_local_queued_probe_and_delivery_work_without_a_session_and_revocation_is_rechecked(): void
    {
        $this->local();
        config(['queue.default' => 'database']);
        $probe = app(CaptureProbes::class)->request((string) Str::uuid());
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'telescope-monitor-capture',
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);
        $this->assertSame('completed', app(CaptureProbes::class)->status($probe['id'])['state']);
        $this->withSession(['_token' => 'csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'csrf-fixture');
        $connection = $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Array destination', 'email' => 'test@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
        $this->postJson('/telescope-monitor/api/v1/alerting/tests', ['client_key' => (string) Str::uuid(), 'connections' => [$connection]])->assertStatus(202);
        $delivery = DB::table('telescope_monitor_deliveries')->first();
        $this->assertTrue(app(DeliverySender::class)->send($delivery->id, $delivery->generation));
        $this->assertSame('sent', DB::table('telescope_monitor_deliveries')->value('status'));
        Telescope::auth(fn () => false);
        $this->assertFalse(app(Capabilities::class)->allows('manage', new Actor(TelescopeAccess::SHARED_KEY, '')));
    }

    public function test_saved_authenticated_actor_is_reauthorized_for_jobs_independently_of_current_visitor(): void
    {
        $allowed = $this->user();
        $other = $this->user('denied@example.test');
        $this->actingAs($allowed);
        $actor = app(ActorResolver::class)->current();
        $this->assertTrue(app(Capabilities::class)->allows('operate', $actor));
        $this->actingAs($other);
        $this->assertTrue(app(Capabilities::class)->allows('operate', $actor));
        Gate::define('viewTelescope', fn ($user) => false);
        $this->assertFalse(app(Capabilities::class)->allows('operate', $actor));
    }

    public function test_request_only_guest_credentials_are_not_replayed_or_bypassed_by_the_legacy_flag(): void
    {
        Telescope::auth(fn ($request) => $request->header('X-Telescope-Test') === 'allow');
        $this->withHeader('X-Telescope-Test', 'allow')->getJson('/telescope-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.actor.label', TelescopeAccess::SHARED_LABEL);
        $this->app->instance('request', Request::create('/'));
        $actor = new Actor(TelescopeAccess::SHARED_KEY, '');
        $this->assertFalse(app(Capabilities::class)->allows('manage', $actor));
        config(['telescope-monitor.authorization.allow_guest_jobs' => true]);
        $this->assertFalse(app(Capabilities::class)->allows('manage', $actor));
        config(['telescope-monitor.authorization.allow_guest_jobs' => false]);
        $this->assertFalse(app(Capabilities::class)->allows('manage', $actor));
    }

    public function test_gate_mode_and_unknown_drivers_do_not_inherit_local_telescope_privileges(): void
    {
        $this->local();
        config(['telescope-monitor.authorization.driver' => 'gates']);
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertStatus(401);
        config(['telescope-monitor.authorization.driver' => 'typo']);
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertStatus(401);
    }

    public function test_an_approved_http_visitor_does_not_grant_a_different_saved_actor_access(): void
    {
        $allowed = $this->user();
        $other = $this->user('denied@example.test');
        $this->actingAs($allowed);
        Route::middleware(['web', Authorize::class])->get('/auth-scope-fixture', function () use ($other) {
            return response()->json(['other_allowed' => app(Capabilities::class)->allows('manage', new Actor('users:'.$other->getAuthIdentifier(), ''))]);
        });
        $this->getJson('/auth-scope-fixture')->assertOk()->assertJsonPath('other_allowed', false);
    }

    public function test_deployed_telescope_approved_guest_email_test_sends_without_login_or_a_separate_flag(): void
    {
        Gate::define('viewTelescope', fn (?Authenticatable $user = null) => true);
        config(['telescope-monitor.authorization.allow_guest_jobs' => false]);
        $this->withHeader('X-Telescope-Test', 'allow');
        $connection = $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Array destination', 'email' => 'test@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
        $this->postJson('/telescope-monitor/api/v1/alerting/tests', ['client_key' => (string) Str::uuid(), 'connections' => [$connection]])->assertStatus(202);
        $this->app->instance('request', Request::create('/'));
        $delivery = DB::table('telescope_monitor_deliveries')->first();
        $this->assertTrue(app(DeliverySender::class)->send($delivery->id, $delivery->generation));
        $this->assertSame('sent', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertDatabaseCount('telescope_monitor_delivery_attempts', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(Auth::user());
    }
    public function test_revoked_telescope_guest_policy_cancels_pending_email_before_provider_io(): void
    {
        Gate::define('viewTelescope', fn (?Authenticatable $user = null) => true);
        $connection = $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Array destination', 'email' => 'test@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
        $this->postJson('/telescope-monitor/api/v1/alerting/tests', ['client_key' => (string) Str::uuid(), 'connections' => [$connection]])->assertStatus(202);
        Gate::define('viewTelescope', fn (?Authenticatable $user = null) => false);
        $this->app->instance('request', Request::create('/'));
        $delivery = DB::table('telescope_monitor_deliveries')->first();
        $this->assertFalse(app(DeliverySender::class)->send($delivery->id, $delivery->generation));
        $this->assertSame('initiator_unauthorized', DB::table('telescope_monitor_deliveries')->value('cancellation_reason'));
        $this->assertDatabaseCount('telescope_monitor_delivery_attempts', 0);
    }

    public function test_worker_policy_uses_only_the_saved_actor_and_restores_all_host_context(): void
    {
        $actor = $this->user();
        $visitor = $this->user('visitor@example.test');
        $this->actingAs($visitor);
        $original = Request::create('https://example.test/telescope-monitor', server: ['HTTP_AUTHORIZATION' => 'private-browser-value', 'REMOTE_ADDR' => '203.0.113.9']);
        $resolver = fn () => $visitor;
        $this->app->instance('request', $original);
        $original->setUserResolver($resolver);
        $auth = Auth::getFacadeRoot();
        $gate = Gate::getFacadeRoot();
        Telescope::auth(function ($request) use ($actor) {
            $this->assertSame($actor->getAuthIdentifier(), $request->user()->getAuthIdentifier());
            $this->assertSame($actor->getAuthIdentifier(), Auth::user()->getAuthIdentifier());
            $this->assertNull($request->header('Authorization'));
            $this->assertNull(request()->ip());
            $this->assertSame('CLI', request()->method());
            $this->assertSame([], $request->cookies->all());
            return true;
        });
        $this->assertTrue(app(\TelescopeMonitor\Auth\QueuedTelescopeAuthorization::class)->allows($actor));
        $this->assertSame($auth, Auth::getFacadeRoot());
        $this->assertSame($gate, Gate::getFacadeRoot());
        $this->assertSame($original, app('request'));
        $this->assertSame($resolver, $original->getUserResolver());
        $this->assertSame($visitor->getAuthIdentifier(), Auth::user()->getAuthIdentifier());
        Telescope::auth(fn () => throw new \RuntimeException('Policy failed'));
        $this->assertFalse(app(\TelescopeMonitor\Auth\QueuedTelescopeAuthorization::class)->allows($actor));
        $this->assertSame($original, app('request'));
        $this->assertSame($auth, Auth::getFacadeRoot());
        $this->assertSame($gate, Gate::getFacadeRoot());
    }

    public function test_worker_guest_cannot_borrow_a_signed_in_visitor_or_loopback_request(): void
    {
        $this->actingAs($this->user());
        $original = Request::create('http://127.0.0.1:8081/telescope-monitor', server: ['SERVER_ADDR' => '127.0.0.1']);
        $this->app->instance('request', $original);
        Telescope::auth(fn ($request) => Auth::check() || $request->ip() === '127.0.0.1' || $request->server('SERVER_ADDR') === '127.0.0.1');
        $this->assertFalse(app(\TelescopeMonitor\Auth\QueuedTelescopeAuthorization::class)->allows(null));
        $this->assertNotNull(Auth::user());
        $this->assertSame($original, app('request'));
    }

}
