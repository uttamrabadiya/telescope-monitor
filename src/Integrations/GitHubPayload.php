<?php

namespace TelescopeMonitor\Integrations;

final class GitHubPayload
{
    public static function render(array $e, string $operation, ?string $title = null, array $labels = []): array
    {
        $plain = str_replace('@', '＠', $e['class']."\n".$e['message']."\n".$e['file'].':'.$e['line']);
        preg_match_all('/`+/', $plain, $runs);
        $max = 0;
        foreach ($runs[0] as $run) {
            $max = max($max, strlen($run));
        }$fence = str_repeat('`', max(3, $max + 1));
        $marker = 'telescope-monitor:'.$e['installation_id'].':'.$e['issue_id'].':'.$operation;

        return $e + ['github_title' => mb_substr(str_replace(["\r", "\n", '@'], [' ', ' ', '＠'], $title ?? $e['class'].': '.$e['message']), 0, 256), 'github_body' => 'Application: '.str_replace('@', '＠', $e['application'])."\nEnvironment: ".str_replace('@', '＠', $e['environment'])."\n\n".$fence."text\n".$plain."\n".$fence."\n\n[Open investigation](".$e['url'].")\n\n<!-- ".$marker.' -->', 'github_labels' => $labels, 'github_marker' => $marker];
    }
}
