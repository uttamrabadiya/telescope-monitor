<?php

namespace TelescopeMonitor\Tests\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Integrations\GitHubPayload;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class GitHubExportTest extends TestCase
{
    use CapturesExceptions;

    public function test_preview_includes_retained_frames_and_summary_and_respects_export_controls(): void
    {
        $this->authorize();
        $connection = $this->postJson('/telescope-monitor/api/v1/integrations', [
            'client_key' => (string) Str::uuid(),
            'provider' => 'github',
            'name' => 'Test',
            'enabled' => true,
            'owner' => 'acme',
            'repo' => 'app',
            'credential' => 'private',
        ])->assertOk()->json('telescope_monitor_private.id');
        $this->capture('A dummy exception');
        $this->process();
        $issue = DB::table('telescope_monitor_issues')->value('public_id');
        $preview = fn () => $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/preview', ['connection' => $connection, 'preview_key' => (string) Str::uuid()])->assertOk()->json('telescope_monitor_private.body');
        $body = $preview();
        $this->assertStringContainsString('Occurrences: 1', $body);
        $this->assertStringContainsString('First seen (UTC):', $body);
        $this->assertStringContainsString('Status at export: unresolved', $body);
        $this->assertStringContainsString('## Application stack trace', $body);
        $this->assertStringNotContainsString('never-copy', $body);
        $this->assertStringNotContainsString('opaque-123', $body);
        config(['telescope-monitor.integrations.github.include_stack_trace' => false]);
        $this->assertStringNotContainsString('## Application stack trace', $preview());
        config(['telescope-monitor.integrations.github.include_context' => false]);
        $this->assertStringContainsString('Occurrences: 1', $preview());
    }

    public function test_untrusted_markdown_and_mentions_remain_in_bounded_code_blocks(): void
    {
        $e = [
            'application' => '@everyone',
            'environment' => 'testing',
            'count' => '42',
            'class' => 'Exception',
            'message' => str_repeat('`', 3200),
            'file' => 'app/Test.php',
            'line' => 12,
            'installation_id' => 'installation',
            'issue_id' => 'issue',
            'url' => 'https://example.test/errors/issue',
        ];
        $export = GitHubPayload::render($e, 'operation');
        $this->assertStringNotContainsString('@everyone', $export['github_body']);
        $this->assertLessThan(8192, strlen($export['github_body']));
        $this->assertStringEndsWith('<!-- telescope-monitor:installation:issue:operation -->', $export['github_body']);
    }
}
