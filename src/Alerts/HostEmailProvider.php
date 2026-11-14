<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class HostEmailProvider implements NotificationProvider
{
    public function send(array $envelope, array $target): Outcome
    {
        try {
            $report = isset($envelope['report']);
            $presentation = $report ? app(\TelescopeMonitor\Reports\ReportPresentation::class) : app(EmailPresentation::class);
            $mailer = Mail::mailer(config('telescope-monitor.alerts.mailer'));
            $legacy = version_compare(app()->version(), '9.0.0', '<');
            $swift = null;
            $message = $mailer->send(
                [
                    'html' => $report ? 'telescope-monitor::mail.report' : 'telescope-monitor::mail.exception',
                    'raw' => $presentation->text($envelope),
                ],
                ['mail' => $presentation->data($envelope)],
                function ($m) use ($target, $presentation, $envelope, $legacy, &$swift) {
                    $m->to($target['email'])->subject($presentation->subject($envelope));
                    if ($legacy) {
                        $swift = $m->getSwiftMessage();
                    }
                },
            );

            // Laravel 8 returns void even after a successful transport handoff.
            // Require its sent event so a cancelled MessageSending is not accepted.
            if ($legacy) {
                return $swift !== null && app(LegacyMailAcceptance::class)->accepted($swift) && method_exists($mailer, 'failures') && $mailer->failures() === []
                    ? Outcome::accepted() : Outcome::permanent();
            }

            return $message === null ? Outcome::permanent() : Outcome::accepted();
        } catch (TransportExceptionInterface $e) {
            $code = $e->getCode();

            return $code >= 500 && $code < 600 ? Outcome::permanent() : ($code >= 400 && $code < 500 ? Outcome::retryable() : Outcome::uncertain());
        } catch (\InvalidArgumentException) {
            return Outcome::permanent();
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if (is_a($e, 'Swift_TransportException')) {
                return $code >= 500 && $code < 600 ? Outcome::permanent() : ($code >= 400 && $code < 500 ? Outcome::retryable() : Outcome::uncertain());
            }
            return Outcome::retryable();
        }
    }
}
