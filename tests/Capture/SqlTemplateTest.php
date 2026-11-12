<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Capture\Sanitizer;
use TelescopeMonitor\Capture\SqlTemplate;
use TelescopeMonitor\Tests\TestCase;

final class SqlTemplateTest extends TestCase
{
    use CapturesExceptions;

    public function test_literals_comments_numbers_and_bindings_are_never_retained_in_templates(): void
    {
        $cases = [
            "SELECT `id`, `amount` FROM `widgets` WHERE `email` = 'secret@example.test' AND id=123 /* private comment */" => 'SELECT `id`, `amount` FROM `widgets` WHERE `email` = ? AND id = ?',
            "insert into users (email, age) values ('O''Reilly', 42)" => 'insert into users (email, age) values (?, ?)',
            'UPDATE users SET amount=1.2e-3 WHERE id=:secretBindingName' => 'UPDATE users SET amount = ? WHERE id = ?',
            "SELECT X'CAFEBABE', B'1010', _utf8mb4'sensitive', N'private', 0xDEADBEEF, 0b1010, TRUE, NULL -- secret" => 'SELECT ?, ?, ?, ?, ?, ?, ?, ?',
        ];
        foreach ($cases as $sql => $expected) {
            $result = app(SqlTemplate::class)->capture($sql, 'mysql');
            $this->assertSame('retained', $result['sql_state']);
            $this->assertSame($expected, $result['sql_template']);
        }
    }

    public function test_ambiguous_unsupported_multi_statement_and_oversized_sql_is_omitted_entirely(): void
    {
        foreach ([
            "select 'escaped\\'secret'",
            "select 'unterminated",
            'select * from `email@example.test`',
            'select $$secret$$',
            'CALL secret_procedure()',
            "select 1; select 'secret'",
            'select @secret',
            'select 0xINVALID',
            'select (1',
            'select 1 /* nested /* comment */',
            'select "sensitive-double-quoted-value" from users',
            'select 1 /*!50000 sensitive */',
        ] as $sql) {
            $this->assertSame(['sql_state' => 'unsupported'], app(SqlTemplate::class)->capture($sql, 'mysql'), $sql);
        }
        $this->assertSame(['sql_state' => 'unsupported'], app(SqlTemplate::class)->capture('select 1', 'pgsql'));
        $this->assertSame(['sql_state' => 'too_large'], app(SqlTemplate::class)->capture('select '.str_repeat('x, ', 9000), 'mysql'));
        $this->assertSame(['sql_state' => 'too_large'], app(SqlTemplate::class)->capture('select '.implode(', ', array_fill(0, 60, 'column_name')).' from users', 'mysql', 128));
    }

    public function test_capture_is_opt_in_and_policy_is_applied_before_buffering_and_when_reading(): void
    {
        $this->authorize();
        $batch = [
            [
                'type' => 'query',
                'content' => [
                    'driver' => 'mysql',
                    'connection' => 'mysql',
                    'time' => 1.98,
                    'sql' => "select * from users where email='LEAK-EMAIL' and id=9898",
                    'bindings' => ['LEAK-BINDING'],
                ],
            ],
        ];
        $policy = app(CapturePolicy::class)->read();
        $default = app(Sanitizer::class)->batch($batch, $policy);
        $this->assertSame('disabled', $default['queries'][0]['sql_state']);
        $this->assertArrayNotHasKey('sql_template', $default['queries'][0]);
        $this->patchJson('/telescope-monitor/api/v1/settings/privacy', ['version' => 0, 'values' => ['retain_sql_templates' => true]])->assertOk();
        $enabled = app(Sanitizer::class)->batch($batch, app(CapturePolicy::class)->read());
        $this->assertStringNotContainsString('LEAK', json_encode($enabled));
        $this->assertStringNotContainsString('9898', json_encode($enabled));
        $this->assertSame('retained', $enabled['queries'][0]['sql_state']);
        Telescope::$entriesQueue = [];
        Telescope::recordQuery(IncomingEntry::make($batch[0]['content']));
        $this->capture('SQL template capture');
        Telescope::store(app(EntriesRepository::class));
        $inbox = DB::table('telescope_monitor_capture_inbox')->first();
        $buffer = Crypt::decryptString($inbox->payload_ciphertext);
        $this->assertStringNotContainsString('LEAK', $buffer);
        $this->assertStringContainsString('sql_template', $buffer);
        $this->process();
        $id = DB::table('telescope_monitor_occurrences')->value('public_id');
        $reader = app(EvidenceReader::class);
        $this->assertSame('retained', $reader->read($id)['evidence']['queries'][0]['sql_state']);
        $this->patchJson('/telescope-monitor/api/v1/settings/privacy', ['version' => 1, 'values' => ['retain_sql_templates' => false]])->assertOk();
        $read = $reader->read($id)['evidence'];
        $this->assertArrayNotHasKey('sql_template', $read['queries'][0]);
        $this->assertSame('disabled', $read['queries'][0]['sql_state']);
        foreach ($read['timeline'] as $event) {
            $this->assertArrayNotHasKey('sql_template', $event);
        }
    }

    public function test_restricter_policy_before_processing_removes_both_sql_copies_and_custom_redactions_fail_closed(): void
    {
        config(['telescope-monitor.capture.sql_templates' => true]);
        $policy = app(CapturePolicy::class)->read();
        $policy['privacy']['redact_patterns'] = ['~private_table~'];
        $data = app(Sanitizer::class)->batch([['type' => 'query', 'content' => ['driver' => 'mysql', 'sql' => 'select id from private_table', 'time' => INF]]], $policy);
        $this->assertArrayNotHasKey('sql_template', $data['queries'][0]);
        $this->assertArrayNotHasKey('duration_ms', $data['queries'][0]);
        $this->assertStringNotContainsString('private_table', json_encode($data));
        Telescope::recordQuery(IncomingEntry::make(['driver' => 'mysql', 'sql' => "select * from users where email='LEAK'", 'time' => 1]));
        $this->capture();
        config(['telescope-monitor.locked' => ['privacy.retain_sql_templates' => false]]);
        $this->process();
        $payload = json_decode(Crypt::decryptString(DB::table('telescope_monitor_occurrence_evidence')->value('payload_ciphertext')), true);
        $this->assertArrayNotHasKey('sql_template', $payload['queries'][0]);
        $this->assertSame('disabled', $payload['queries'][0]['sql_state']);
    }

    public function test_activity_has_useful_request_job_and_command_fields_without_query_strings_arguments_or_payloads(): void
    {
        $data = app(Sanitizer::class)->batch([
            [
                'type' => 'request',
                'content' => ['method' => 'POST', 'uri' => '/api/widgets?token=LEAK', 'response_status' => 201, 'payload' => ['LEAK']],
            ],
            ['type' => 'job', 'content' => ['name' => 'App\\Jobs\\SendWidgetNotification', 'data' => ['LEAK']]],
            ['type' => 'command', 'content' => ['command' => 'notify:send --secret=LEAK', 'arguments' => ['LEAK']]],
        ], app(CapturePolicy::class)->read());
        $this->assertSame('/api/widgets', $data['timeline'][0]['path']);
        $this->assertSame('App\\Jobs\\SendWidgetNotification', $data['timeline'][1]['job_class']);
        $this->assertSame('[unavailable]', $data['timeline'][2]['command']);
        $this->assertStringNotContainsString('LEAK', json_encode($data));
    }

    public function test_sql_setting_can_be_deployment_locked_and_old_snapshots_do_not_gain_sql(): void
    {
        $this->authorize();
        config(['telescope-monitor.locked' => ['privacy.retain_sql_templates' => false]]);
        $this->patchJson('/telescope-monitor/api/v1/settings/privacy', ['version' => 0, 'values' => ['retain_sql_templates' => true]])->assertUnprocessable();
        $old = [
            'version' => 1,
            'marker' => [],
            'exception' => ['class' => 'RuntimeException', 'message' => 'Old snapshot', 'file' => 'file.php', 'line' => 1, 'trace' => []],
            'origin' => 'web',
            'execution' => [],
            'queries' => [['connection' => 'mysql', 'duration_ms' => 2]],
            'timeline' => [['type' => 'query', 'connection' => 'mysql', 'duration_ms' => 2]],
            'logs' => [],
            'tags' => [],
            'impact' => null,
            'coverage' => [],
        ];
        $restricted = app(Sanitizer::class)->restrict($old, app(CapturePolicy::class)->read());
        $this->assertArrayNotHasKey('sql_state', $restricted['queries'][0]);
        $this->assertArrayNotHasKey('sql_template', $restricted['queries'][0]);
    }

    public function test_sql_heavy_batches_stay_bounded_and_report_partial_measurements(): void
    {
        config(['telescope-monitor.capture.sql_templates' => true, 'telescope-monitor.capture.sql_max_bytes' => 4096]);
        $sql = 'select '.implode(', ', array_fill(0, 250, 'column_name')).' from users where id=12345';
        $entries = array_fill(0, 100, ['type' => 'query', 'content' => ['driver' => 'mysql', 'connection' => 'mysql', 'sql' => $sql, 'time' => 1]]);
        $batch = app(Sanitizer::class)->batch($entries, app(CapturePolicy::class)->read());
        $this->assertLessThanOrEqual(65536, strlen(json_encode($batch)));
        $this->assertLessThan(100, count($batch['queries']));
        $this->assertSame('truncated', $batch['coverage']['queries']);
        $this->assertStringNotContainsString('12345', json_encode($batch));
    }
}
