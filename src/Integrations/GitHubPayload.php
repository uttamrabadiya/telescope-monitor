<?php

namespace TelescopeMonitor\Integrations;

final class GitHubPayload
{
    private static function block(string $text, int $limit): string
    {
        $text = str_replace('@', '＠', mb_strcut($text, 0, $limit, 'UTF-8'));
        $text = preg_replace_callback('/`{17,}/', fn () => str_repeat('`', 16), $text);
        $text = mb_strcut($text, 0, $limit, 'UTF-8');
        preg_match_all('/`+/', $text, $runs);
        $max = 0;
        foreach ($runs[0] as $run) {
            $max = max($max, strlen($run));
        }
        $fence = str_repeat('`', max(3, $max + 1));

        return $fence."text\n".$text."\n".$fence;
    }

    public static function render(array $e, string $operation, ?string $title = null, array $labels = []): array
    {
        $context = $e['github_context'] ?? [];
        $metadata = 'Application: '.$e['application']."\nEnvironment: ".$e['environment']."\nOccurrences: ".$e['count'];
        foreach ([
            'reference' => 'Reference',
            'status' => 'Status at export',
            'first_seen_at' => 'First seen (UTC)',
            'last_seen_at' => 'Last seen (UTC)',
            'release' => 'Release',
        ] as $key => $label) {
            if (! empty($context[$key])) {
                $metadata .= "\n".$label.': '.$context[$key];
            }
        }
        $body = "## Error summary\n\n".self::block($metadata, 800)."\n\n## Exception\n\n".self::block($e['class']."\n".$e['message']."\n".$e['file'].':'.$e['line'], 2400);
        $execution = [];
        foreach ([
            'method' => 'HTTP method',
            'path_template' => 'Path template',
            'route_name' => 'Route',
            'job_class' => 'Job',
            'command' => 'Command',
        ] as $key => $label) {
            if (! empty($context['execution'][$key])) {
                $execution[] = $label.': '.$context['execution'][$key];
            }
        }
        if ($execution) {
            $body .= "\n\n## Execution\n\n".self::block(implode("\n", $execution), 600);
        }
        if (! empty($context['frames'])) {
            $frames = [];
            foreach ($context['frames'] as $frame) {
                $frames[] = $frame['file'].':'.$frame['line'].' — '.$frame['class'].($frame['class'] !== '' ? '::' : '').$frame['function'].'()';
            }
            $body .= "\n\n## Application stack trace\n\n".self::block(implode("\n", $frames), 900)."\n\nBounded application frames; open the investigation for the full retained trace.";
        }
        $marker = 'telescope-monitor:'.$e['installation_id'].':'.$e['issue_id'].':'.$operation;
        $body .= "\n\n[Open investigation](".$e['url'].")\n\nSnapshot at export time. Request bodies, headers, cookies, SQL, user identities and notes are excluded.\n\n<!-- ".$marker.' -->';

        return $e + [
            'github_title' => mb_substr(str_replace(["\r", "\n", '@'], [' ', ' ', '＠'], $title ?? $e['class'].': '.$e['message']), 0, 256),
            'github_body' => $body,
            'github_labels' => $labels,
            'github_marker' => $marker,
        ];
    }
}
