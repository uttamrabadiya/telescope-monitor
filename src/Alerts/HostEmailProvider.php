<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class HostEmailProvider implements NotificationProvider
{
    public function send(array $envelope, array $target): Outcome
    {
        try {
            $subject = mb_substr(str_replace(["\r", "\n"], ' ', ($envelope['test'] ? '[TEST] ' : '').$envelope['label'].' · '.$envelope['application']), 0, 180);
            $message = Mail::mailer(config('exception-monitor.alerts.mailer'))->raw(Envelope::text($envelope), fn ($m) => $m->to($target['email'])->subject($subject));

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
