<?php

namespace TelescopeMonitor\Tests\Fixtures;

use Illuminate\Support\Facades\Mail;

trait ReadsMail
{
    private function sentEmails(): array
    {
        $legacy = version_compare(app()->version(), '9.0.0', '<');
        $mailer = Mail::mailer('array');
        $transport = $legacy ? $mailer->getSwiftMailer()->getTransport() : $mailer->getSymfonyTransport();

        return $transport->messages()->map(function ($sent) use ($legacy) {
            if (! $legacy) {
                $message = $sent->getOriginalMessage();

                return [
                    'subject' => $message->getSubject(),
                    'html' => $message->getHtmlBody(),
                    'text' => $message->getTextBody(),
                    'to' => array_map(fn ($address) => $address->getAddress(), $message->getTo()),
                ];
            }
            $parts = [$sent, ...$sent->getChildren()];
            $body = fn ($type) => collect($parts)->first(fn ($part) => $part->getContentType() === $type)?->getBody() ?? '';

            return [
                'subject' => $sent->getSubject(),
                'html' => $body('text/html') ?: $sent->getBody(),
                'text' => $body('text/plain'),
                'to' => array_keys($sent->getTo()),
            ];
        })->all();
    }
}
