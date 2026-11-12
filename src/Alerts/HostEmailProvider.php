<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class HostEmailProvider implements NotificationProvider
{
    public function send(array $envelope, array $target): Outcome
    {
        try {
            $presentation = app(EmailPresentation::class);
            $message = Mail::mailer(config('telescope-monitor.alerts.mailer'))->send(
                ['html' => 'telescope-monitor::mail.exception', 'raw' => $presentation->text($envelope)],
                ['mail' => $presentation->data($envelope)],
                fn ($m) => $m->to($target['email'])->subject($presentation->subject($envelope)),
            );

            return $message === null ? Outcome::permanent() : Outcome::accepted();
        } catch (TransportExceptionInterface $e) {
            $code = $e->getCode();

            return $code >= 500 && $code < 600 ? Outcome::permanent() : ($code >= 400 && $code < 500 ? Outcome::retryable() : Outcome::uncertain());
        } catch (\InvalidArgumentException) {
            return Outcome::permanent();
        } catch (\Throwable) {
            return Outcome::retryable();
        }
    }
}
