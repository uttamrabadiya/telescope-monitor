<?php

namespace TelescopeMonitor\Alerts;

use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Integrations\WebhookPayload;
use TelescopeMonitor\Services\RedactionService;

final class Envelope
{
    public function __construct(private CapturePolicy $policy, private RedactionService $redactor) {}

    public function sanitize(array $data): array
    {
        $patterns = $this->policy->read()['privacy']['redact_patterns'];
        foreach (['application' => 120, 'class' => 512, 'message' => 1024, 'file' => 2048, 'github_title' => 256, 'github_body' => 8192] as $key => $max) {
            if (! array_key_exists($key, $data) && str_starts_with($key, 'github_')) {
                continue;
            }
            $data[$key] = mb_strcut($this->redactor->text((string) ($data[$key] ?? ''), $patterns), 0, $max, 'UTF-8');
        }

        if (isset($data['email_context'])) {
            $data['email_context'] = app(EmailContext::class)->sanitize($data['email_context']);
        }

        if (isset($data['webhook_body'])) {
            $data['webhook_body'] = WebhookPayload::bytes($data);
        }

        return $data;
    }

    public static function text(array $e): string
    {
        return ($e['test'] ? "TEST NOTIFICATION — no real incident\n\n" : '').$e['label'].' · '.$e['application']."\nEnvironment: ".$e['environment']."\n".$e['class']."\n".$e['message']."\n".($e['file'] !== '' ? $e['file'].':'.$e['line']."\n" : '').'Occurred: '.$e['occurred_at']."\nCaptured count: ".$e['count']."\n\nOpen investigation: ".$e['url']."\n\nTransport acceptance is not proof of inbox delivery.";
    }
}
