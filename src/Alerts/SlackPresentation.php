<?php

namespace TelescopeMonitor\Alerts;

use Carbon\CarbonImmutable;

final class SlackPresentation
{
    public function payload(array $envelope): array
    {
        $context = $envelope['slack_context'] ?? [];
        $test = $envelope['test'];
        $label = $test ? 'Test notification' : $envelope['label'];
        $icon = $test ? '🧪' : match ($envelope['event']) {
            'error.created', 'error.regressed' => '🔴',
            'error.threshold_reached' => '⚠️',
            'error.resolved' => '✅',
            default => 'ℹ️',
        };
        $plain = fn (string $text, int $max = 3000) => ['type' => 'plain_text', 'text' => $this->escape($text, $max), 'emoji' => false];
        $blocks = [
            ['type' => 'header', 'text' => $plain($icon.' '.$label.' · '.$envelope['application'], 150)],
            [
                'type' => 'section',
                'text' => $plain($envelope['class']."\n".($envelope['message'] !== '' ? $envelope['message'] : 'No exception message captured')),
            ],
        ];
        if ($test) {
            $blocks[] = ['type' => 'context', 'elements' => [$plain('Synthetic delivery test · No real incident')]];
        }
        if ($envelope['file'] !== '') {
            $location = $envelope['file'].($envelope['line'] > 0 ? ':'.$envelope['line'] : '');
            $blocks[] = ['type' => 'section', 'text' => $this->markdown('*Source*'."\n".$this->code($location, 2900))];
        }
        $occurred = $this->time($envelope['occurred_at'], $context['timezone'] ?? 'UTC') ?? 'Time unavailable';
        $fields = [$plain('Environment'."\n".$envelope['environment'], 2000)];
        if (! $test) {
            $fields[] = $plain('Captured occurrences'."\n".$envelope['count'], 2000);
        }
        $fields[] = $plain(($test ? 'Sent at' : 'Event time')."\n".$occurred, 2000);
        if (! $test && ($first = $this->time($context['first_seen_at'] ?? null, $context['timezone'] ?? 'UTC'))) {
            $fields[] = $plain('First seen'."\n".$first, 2000);
        }
        $blocks[] = ['type' => 'section', 'fields' => $fields];
        $details = [];
        $execution = $context['execution'] ?? [];
        if (! $test && EventCatalog::occurrence($envelope['event'])) {
            if (! empty($execution['path_template'])) {
                $details[] = '*Request*  '.$this->code(trim(($execution['method'] ?? '').' '.$execution['path_template']), 1100);
            } elseif (! empty($execution['route_name'])) {
                $details[] = '*Route*  '.$this->code($execution['route_name'], 256);
            }
            foreach (['job_class' => 'Job', 'command' => 'Command'] as $key => $title) {
                if (! empty($execution[$key])) {
                    $details[] = '*'.$title.'*  '.$this->code($execution[$key], 256);
                }
            }
            if (! empty($context['release'])) {
                $details[] = '*Release*  '.$this->code($context['release'], 191);
            }
        }
        if ($details) {
            $blocks[] = ['type' => 'section', 'text' => $this->markdown(implode("\n", $details))];
        }
        $url = $this->url($envelope['url']);
        if ($url) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => $plain($test ? 'Open setup' : 'Open investigation'),
                        'action_id' => 'open_investigation',
                        'url' => $url,
                    ],
                ],
            ];
        }
        $footer = 'Telescope Monitor'.(! empty($context['reference']) ? ' · '.$context['reference'] : '');
        if (! $test) {
            $footer .= ' · Counts reflect captured events, not total traffic';
        }
        if (! $url) {
            $footer .= ' · Dashboard URL unavailable';
        }
        $blocks[] = ['type' => 'context', 'elements' => [$plain($footer)]];
        $fallback = $label.' · '.mb_substr($envelope['application'], 0, 120).' ['.mb_substr($envelope['environment'], 0, 120).
            "]\n".mb_substr($envelope['class'], 0, 512).': '.mb_substr($envelope['message'], 0, 1024);
        if ($envelope['file'] !== '') {
            $fallback .= "\n".mb_substr($envelope['file'], 0, 512).':'.$envelope['line'];
        }
        $fallback .= "\n".($test ? 'Synthetic delivery test · No real incident' : 'Captured occurrences: '.$envelope['count']).' · '.$occurred;
        if ($details) {
            $fallback .= "\n".html_entity_decode(implode("\n", $details), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $fallback .= "\n".$footer.($url ? "\n".$url : '');

        return [
            'text' => $this->escape($fallback, 14000),
            'blocks' => $blocks,
            'mrkdwn' => false,
            'parse' => 'none',
            'link_names' => false,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ];
    }

    private function escape(string $text, int $max): string
    {
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        if (mb_strlen($escaped) <= $max) {
            return $escaped;
        }
        $escaped = mb_substr($escaped, 0, $max - 1);
        // Do not cut through an escaped entity at the Slack character limit.
        return preg_replace('/&[^;]*$/', '', $escaped).'…';
    }

    private function code(string $text, int $max): string
    {
        return '`'.$this->escape(str_replace(["`", "\r", "\n"], ['ˋ', ' ', ' '], $text), $max).'`';
    }

    private function markdown(string $text): array
    {
        return ['type' => 'mrkdwn', 'text' => $text, 'verbatim' => true];
    }

    private function time(?string $value, string $timezone): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone($timezone)->format('d M Y, H:i:s T');
        } catch (\Throwable) {
            return null;
        }
    }

    private function url(string $url): ?string
    {
        $parts = parse_url($url);
        return $parts && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! preg_match('/[\x00-\x20<>|]/', $url)
            && mb_strlen($url) <= 3000 ? $url : null;
    }
}
