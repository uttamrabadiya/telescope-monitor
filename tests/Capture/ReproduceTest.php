<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Capture\Reproduce;
use TelescopeMonitor\Telescope\StoredEntries;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;
use TelescopeMonitor\Tests\TestCase;

final class ReproduceTest extends TestCase
{
    use CapturesExceptions;

    private function request(array $body = []): Request
    {
        $request = Request::create('https://example.test/api/groups/42?currency=INR&access_token=QUERY-CREDENTIAL', 'POST', cookies: ['session' => 'COOKIE-CREDENTIAL'], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer HEADER-CREDENTIAL',
            'HTTP_X_API_KEY' => 'API-CREDENTIAL',
        ], content: json_encode($body ?: [
            'amount' => 129.5,
            'email' => 'customer@example.test',
            'password' => 'BODY-CREDENTIAL',
            'nested' => ['refresh_token' => 'NESTED-CREDENTIAL'],
            'items' => [['amount' => 12, 'email' => 'other@example.test']],
            'empty' => (object) [],
        ]));
        $route = new Route('POST', 'api/groups/{group}', fn () => null);
        $route->name('groups.store');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        return $request;
    }

    private function replay(Request $request): array
    {
        $capture = app(Reproduce::class)->capture($request);
        $this->assertStringNotContainsString('customer@example.test', $capture);
        return app(Reproduce::class)->import($capture, ['retain_execution_context' => true]);
    }

    public function test_default_redacted_capture_is_encrypted_and_has_zero_database_queries(): void
    {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $data = $this->replay($this->request());
        $this->assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        $this->assertSame('ready', $data['state']);
        $this->assertSame('[REDACTED]', $data['parameters']['group']);
        $this->assertSame('[REDACTED]', $data['body']['amount']);
        $this->assertSame('[REDACTED]', $data['query']['currency']);
        $this->assertSame('application/json', $data['headers']['content-type']);
        $this->assertContains('/empty', $data['objects']);
        $this->assertCredentialsExcluded($data);
    }

    private function assertCredentialsExcluded(array $data): void
    {
        foreach (['QUERY-CREDENTIAL', 'HEADER-CREDENTIAL', 'API-CREDENTIAL', 'COOKIE-CREDENTIAL', 'BODY-CREDENTIAL', 'NESTED-CREDENTIAL'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($data));
        }
        $this->assertArrayNotHasKey('authorization', $data['headers']);
        $this->assertArrayNotHasKey('x-api-key', $data['headers']);
        $this->assertArrayNotHasKey('content-length', $data['headers']);
    }

    public function test_selected_fields_and_full_payload_preserve_values_but_always_exclude_credentials(): void
    {
        // Dotted route names are literal policy keys, not configuration dot paths.
        config([
            'telescope-monitor.reproduce.routes' => [
                'groups.store' => ['mode' => 'selected-values', 'fields' => ['parameters.group', 'body.amount', 'body.items.*.amount', 'query.currency']],
            ],
        ]);
        $selected = $this->replay($this->request());
        $this->assertSame('42', $selected['parameters']['group']);
        $this->assertSame(129.5, $selected['body']['amount']);
        $this->assertSame(12, $selected['body']['items'][0]['amount']);
        $this->assertSame('[REDACTED]', $selected['body']['email']);
        $this->assertCredentialsExcluded($selected);
        config([
            'telescope-monitor.reproduce.routes' => [
                'groups.store' => ['mode' => 'full-payload', 'headers' => ['content-type', 'authorization', 'x-api-key', 'cookie', 'content-length']],
            ],
        ]);
        $full = $this->replay($this->request());
        $this->assertSame('customer@example.test', $full['body']['email']);
        $this->assertSame('INR', $full['query']['currency']);
        $this->assertCredentialsExcluded($full);
        $this->assertSame('redacted', $this->replay($this->differentRoute())['mode']);
    }

    private function differentRoute(): Request
    {
        $request = $this->request();
        $route = new Route('POST', 'api/groups/{group}', fn () => null);
        $route->name('other.store');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        return $request;
    }

    public function test_stricter_policy_restricts_existing_values_and_relaxation_cannot_reconstruct_redacted_capture(): void
    {
        config(['telescope-monitor.reproduce.routes' => ['groups.store' => ['mode' => 'full-payload']]]);
        $full = $this->replay($this->request());
        config(['telescope-monitor.reproduce.routes' => []]);
        $restricted = app(Reproduce::class)->restrict($full, ['retain_execution_context' => true]);
        $this->assertSame('[REDACTED]', $restricted['body']['email']);
        config(['telescope-monitor.reproduce.routes' => ['groups.store' => ['mode' => 'full-payload']]]);
        $this->assertSame('[REDACTED]', app(Reproduce::class)->restrict($restricted, ['retain_execution_context' => true])['body']['email']);
        $this->assertSame('excluded', app(Reproduce::class)->restrict($full, ['retain_execution_context' => false])['state']);
    }

    public function test_bounds_unsupported_formats_and_credential_patterns_are_explicit(): void
    {
        config(['telescope-monitor.reproduce.routes' => ['groups.store' => ['mode' => 'full-payload']]]);
        $data = $this->replay($this->request(['note' => 'Bearer RAW-CREDENTIAL', 'key' => 'OPAQUE-CREDENTIAL', 'big' => str_repeat('x', 3000)]));
        $this->assertSame('[REDACTED]', $data['body']['key']);
        $this->assertSame('Bearer [REDACTED]', $data['body']['note']);
        $this->assertContains('field_limit', $data['omissions']);
        $this->assertContains('body_limit', $this->replay($this->request(['blob' => str_repeat('x', 40000)]))['omissions']);
        $request = $this->request();
        $request->headers->set('content-type', 'multipart/form-data; boundary=example');
        $this->assertContains('unsupported_body', $this->replay($request)['omissions']);
        config(['telescope-monitor.reproduce.max_bytes' => 1024]);
        $this->assertSame('too_large', $this->replay($this->request(array_fill_keys(range('a', 'j'), str_repeat('data ', 25))))['state']);
    }

    public function test_command_arguments_options_and_boolean_switches_are_preserved_without_credentials(): void
    {
        config(['telescope-monitor.reproduce.commands' => ['groups:process' => ['mode' => 'full-payload']]]);
        $input = new ArrayInput([
            'command' => 'groups:process',
            'group' => "42' $(echo test)",
            '--token' => 'COMMAND-CREDENTIAL',
            '--dry-run' => true,
            '--no-notify' => true,
        ], new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED),
            new InputArgument('group', InputArgument::REQUIRED),
            new InputOption('token', null, InputOption::VALUE_REQUIRED),
            new InputOption('dry-run', null, InputOption::VALUE_NONE),
            new InputOption('notify', null, InputOption::VALUE_NEGATABLE, '', true),
        ]));
        $data = app(Reproduce::class)->import(app(Reproduce::class)->command('groups:process', $input), ['retain_execution_context' => true]);
        $this->assertSame("42' $(echo test)", $data['arguments']['group']);
        $this->assertSame('[REDACTED]', $data['options']['token']);
        $this->assertTrue($data['options']['dry-run']);
        $this->assertTrue($data['options']['no-notify']);
    }

    public function test_real_http_pipeline_signs_replay_evidence_and_masks_both_viewer_endpoints(): void
    {
        config(['telescope-monitor.reproduce.routes' => ['groups.store' => ['mode' => 'full-payload']]]);
        Router::post('/replay/{group}', fn () => throw new \RuntimeException('Replay test'))->name('groups.store');
        $this->postJson('/replay/42', ['amount' => 129.5, 'email' => 'REPLAY-PRIVATE@example.test', 'password' => 'BODY-CREDENTIAL'])->assertStatus(500);
        Telescope::store(app(EntriesRepository::class));
        $source = DB::table('telescope_entries')->where('type', 'exception')->first();
        $this->assertStringNotContainsString('REPLAY-PRIVATE@example.test', json_decode($source->content, true)['telescope_monitor_reproduce']);
        $this->importSource();
        $this->process();
        $occurrence = DB::table('telescope_monitor_occurrences')->first();
        $issue = DB::table('telescope_monitor_issues')->first();
        $this->authorize(false, false);
        $this->getJson('/telescope-monitor/api/v1/capture/occurrences/'.$occurrence->public_id)->assertOk()->assertJsonPath('telescope_monitor_private.evidence.reproduce.state', 'restricted')->assertDontSee('REPLAY-PRIVATE');
        $this->getJson('/telescope-monitor/api/v1/issues/'.$issue->public_id)->assertOk()->assertDontSee('REPLAY-PRIVATE');
        $this->authorize();
        $this->getJson('/telescope-monitor/api/v1/capture/occurrences/'.$occurrence->public_id)->assertOk()->assertJsonPath('telescope_monitor_private.evidence.reproduce.body.email', 'REPLAY-PRIVATE@example.test');
        $this->assertStringNotContainsString('REPLAY-PRIVATE', DB::table('telescope_monitor_occurrence_evidence')->value('payload_ciphertext'));
        $this->assertSame('ready', app(EvidenceReader::class)->read($occurrence->public_id, true)['evidence']['reproduce']['state']);
        // Ciphertext is bound to the source proof; modifying it cannot import another occurrence.
        $row = app(StoredEntries::class)->exceptionByUuid($source->uuid)[0];
        $row['content']['telescope_monitor_reproduce'] = Crypt::encryptString('{"version":1,"state":"excluded"}');
        $this->assertNull(app(\TelescopeMonitor\Capture\SourceMarker::class)->read(app(\TelescopeMonitor\Repositories\Contracts\InstallationRepository::class)->current(), $row['uuid'], $row['tags'], $row['content']));
        $this->assertSame(0, app(StoredExceptionAdapter::class)->replayRows([$row], 'live')['buffered']);
    }
    public function test_real_command_capture_reads_the_exact_invocation_after_command_finished(): void
    {
        config([
            'telescope-monitor.reproduce.commands' => ['replay:process' => ['mode' => 'selected-values', 'fields' => ['arguments.group']]],
        ]);
        $kernel = new \Illuminate\Foundation\Console\Kernel($this->app, $this->app['events']);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->command('replay:process {group} {--token=} {--dry-run}', fn () => throw new \RuntimeException('Command replay failure'));
        $active = true;
        $queries = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$active, &$queries) {
            if ($active && str_contains($sql, 'telescope_monitor_')) {
                $queries[] = $sql;
            }
        });
        $this->assertSame(1, $kernel->handle(new ArrayInput(['command' => 'replay:process', 'group' => '42', '--token' => 'COMMAND-CREDENTIAL', '--dry-run' => true]), new \Symfony\Component\Console\Output\BufferedOutput));
        Telescope::store(app(EntriesRepository::class));
        $active = false;
        $this->assertSame([], $queries);
        $this->importSource();
        $this->process();
        $occurrence = DB::table('telescope_monitor_occurrences')->first();
        $data = app(EvidenceReader::class)->read($occurrence->public_id, true)['evidence']['reproduce'];
        $this->assertSame('command', $data['kind']);
        $this->assertSame('42', $data['arguments']['group']);
        $this->assertSame('[REDACTED]', $data['options']['token']);
        $this->assertTrue($data['options']['dry-run']);
    }

    public function test_disabled_watchers_and_legacy_snapshots_do_not_gain_replay_evidence(): void
    {
        config(['telescope.watchers.'.\Laravel\Telescope\Watchers\RequestWatcher::class => false]);
        $this->assertSame('excluded', $this->replay($this->request())['state']);
        $uuid = $this->capture();
        $source = app(StoredEntries::class)->exceptionByUuid($uuid)[0];
        // Regenerate an old-format proof with no replay metadata.
        unset($source['content']['telescope_monitor_reproduce']);
        $source['tags'] = app(\TelescopeMonitor\Capture\SourceStamp::class)->tags($uuid, $source['content']['telescope_monitor_capture']['occurred_at'], $source['content'], null);
        DB::table('telescope_monitor_capture_inbox')->delete();
        app(StoredExceptionAdapter::class)->replayRows([$source], 'live');
        $this->process();
        $data = app(EvidenceReader::class)->read(DB::table('telescope_monitor_occurrences')->value('public_id'), true)['evidence'];
        $this->assertArrayNotHasKey('reproduce', $data);
    }

}
