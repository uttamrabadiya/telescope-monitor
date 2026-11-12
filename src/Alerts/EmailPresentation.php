<?php

namespace TelescopeMonitor\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class EmailPresentation
{
    public function data(array $envelope): array
    {
        $context = $envelope['email_context'] ?? [];
        $heading = $envelope['test'] ? 'Test notification' : ($envelope['event'] === 'error.created' ? 'New error group' : $envelope['label']);
        $fallback = strtoupper(substr(Str::slug($envelope['application']), 0, 32)) ?: 'APP';
        $fallback .= $envelope['test'] ? '-TEST' : (! empty($envelope['issue_id']) ? '-'.strtoupper(substr(str_replace('-', '', $envelope['issue_id']), 0, 8)) : '');
        $reference = $context['reference'] ?? $fallback;
        $url = $envelope['url'];
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20]/', $url)) {
            $url = null;
        }
        $time = function ($value) use ($context): ?string {
            if (! $value) {
                return null;
            }
            try {
                return CarbonImmutable::parse($value)->setTimezone($context['timezone'] ?? 'UTC')->format('d M Y, H:i:s T');
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'event' => $envelope,
            'context' => $context,
            'heading' => $heading,
            'reference' => $reference,
            'url' => $url,
            'occurred' => $time($envelope['occurred_at']) ?? 'Time unavailable',
            'first_seen' => $time($context['first_seen_at'] ?? null),
            'last_seen' => $time($context['last_seen_at'] ?? null),
        ];
    }

    public function subject(array $envelope): string
    {
        $data = $this->data($envelope);
        $prefix = $envelope['test'] ? '[TEST] ' : ($envelope['event'] === 'error.created' ? '' : '['.$envelope['label'].'] ');
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $prefix.$data['reference'].' - '.$envelope['class'].': '.$envelope['message']), 0, 180);
    }

    public function text(array $envelope): string
    {
        $data = $this->data($envelope);
        $safe = $envelope;
        $safe['url'] = $data['url'] ?? '[Dashboard URL unavailable]';
        $base = Envelope::text($safe);
        $footer = "\n\nOpen investigation: ".$safe['url']."\n\nTransport acceptance is not proof of inbox delivery.";
        $text = $data['reference']."\n".(str_ends_with($base, $footer) ? substr($base, 0, -strlen($footer)) : $base);
        if ($data['context']['execution'] ?? []) {
            $text .= "\n\nRetained execution context\n";
            foreach ($data['context']['execution'] as $key => $value) {
                $text .= $key.': '.$value."\n";
            }
        }
        if ($data['context']['frames'] ?? []) {
            $text .= "\nApplication stack\n";
            foreach ($data['context']['frames'] as $frame) {
                $text .= $frame['file'].':'.$frame['line'].' '.$frame['class'].($frame['function'] ? '::'.$frame['function'] : '')."\n";
            }
        }
        foreach ($data['context']['tags'] ?? [] as $key => $value) {
            $text .= "\n".$key.': '.$value;
        }
        return $text.$footer;
    }
}
