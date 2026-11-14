<?php

namespace TelescopeMonitor\Reports;

use TelescopeMonitor\Services\RedactionService;

final class ReportPresentation
{
    public static function safeUrl(string $url): ?string
    {
        $p = parse_url($url);

        return $p && in_array($p['scheme'] ?? '', ['http', 'https'], true) && ! empty($p['host']) && ! isset($p['user']) && ! isset($p['pass']) && ! preg_match('/[\x00-\x20]/', $url) ? $url : null;
    }

    public function sanitize(array $report, array $patterns): array
    {
        $text = fn ($v, $max = 512) => mb_strcut(app(RedactionService::class)->text((string) $v, $patterns), 0, $max, 'UTF-8');
        foreach (['timezone', 'period_label', 'schedule'] as $key) {
            $report[$key] = $text($report[$key] ?? '');
        }
        $report['environments'] = array_map(fn ($v) => $text($v, 64), $report['environments'] ?? []);
        foreach (['url', 'preferences_url'] as $key) {
            $report[$key] = self::safeUrl((string) ($report[$key] ?? ''));
        }
        $report['top'] = array_map(fn ($row) => [
            'class' => $text($row['class']),
            'environment' => $text($row['environment'], 64),
            'count' => (int) $row['count'],
            'regressions' => (int) $row['regressions'],
            'status' => $row['status'],
            'unassigned' => (bool) $row['unassigned'],
            'url' => self::safeUrl((string) ($row['url'] ?? '')),
        ], array_slice($report['top'] ?? [], 0, 3));

        return $report;
    }

    public function data(array $envelope): array
    {
        return [
            'event' => $envelope,
            'report' => $envelope['report'],
            'heading' => $envelope['report']['kind'] === 'daily' ? 'Your daily error briefing' : 'Your week in errors',
        ];
    }

    public function subject(array $e): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', ($e['test'] ? '[PREVIEW] ' : '').ucfirst($e['report']['kind']).' report · '.$e['application'].' · '.$e['report']['period_label']), 0, 180);
    }

    public function text(array $e): string
    {
        $r = $e['report'];
        $lines = [
            $e['test'] ? 'PREVIEW — actual captured data; automatic delivery settings are unchanged.' : '',
            $this->data($e)['heading'],
            $e['application'].' · '.implode(', ', $r['environments']),
            $r['period_label'].' · '.$r['timezone'],
            $r['occurrences'].' occurrences · '.$r['new_groups'].' new error groups · '.($r['regressions'] ?? 'Unavailable').' regressions',
            ! $r['complete'] ? 'Partial coverage: monitoring history or retained analytics does not cover the full period.' : '',
            $r['comparison_available'] ? 'Previous period: '.$r['previous_occurrences'].' occurrences'.($r['change_percent'] !== null ? ' ('.$r['change_percent'].'%)' : '') : 'Comparison unavailable: incomplete history.',
            'Captured occurrences',
        ];
        foreach ($r['buckets'] as $b) {
            $lines[] = $b['label'].': '.$b['count'].($b['previous'] !== null ? ' (previous week: '.$b['previous'].')' : '');
        }
        $lines[] = ($r['resolved'] ?? 'Unavailable').' groups resolved · '.$r['unresolved'].' unresolved now';
        if (! $r['activity_complete']) {
            $lines[] = 'Resolution and regression totals unavailable: incomplete retained activity history.';
        }
        if ($r['kind'] === 'weekly') {
            $lines[] = $r['aging'].' unresolved groups first seen over 7 days ago';
        }
        $lines[] = $r['kind'] === 'daily' ? 'Needs attention' : 'Prioritize next week';
        foreach ($r['top'] as $row) {
            $lines[] = $row['class'].' · '.$row['environment'].' · '.$row['status'].' · '.$row['count'].' occurrences'.($row['unassigned'] ? ' · Unassigned' : '').' · '.($row['url'] ?? 'Dashboard URL unavailable');
        }
        if (! $r['top']) {
            $lines[] = 'No currently unresolved errors with captured occurrences in this period.';
        }
        $lines[] = 'Review report: '.($r['url'] ?? 'Dashboard URL unavailable');
        $lines[] = 'Based on captured exceptions, not total application traffic. Backlog and assignment reflect preparation time.';
        $lines[] = $r['schedule'];
        $lines[] = 'Manage report preferences: '.($r['preferences_url'] ?? 'Dashboard URL unavailable');

        return implode("\n", array_filter($lines, fn ($v) => $v !== ''));
    }
}
