<?php

namespace TelescopeMonitor\Alerts;

final class EventCatalog
{
    public const LABELS = [
        'error.created' => 'New error',
        'error.regressed' => 'Regression',
        'error.threshold_reached' => 'Frequency threshold',
        'error.resolved' => 'Resolved',
        'error.reopened' => 'Reopened',
        'error.ignored' => 'Ignored',
        'error.snoozed' => 'Snoozed',
        'error.unsnoozed' => 'Unsnoozed',
        'error.snooze_expired' => 'Snooze expired',
        'note.created' => 'Note added',
        'assignment.changed' => 'Assignment changed',
    ];

    public static function canonical(string $type): string
    {
        return match ($type) {
            'error.resolve' => 'error.resolved','error.reopen' => 'error.reopened','error.ignore' => 'error.ignored','error.snooze' => 'error.snoozed','error.unsnooze' => 'error.unsnoozed','note.added' => 'note.created','error.assigned','error.unassigned' => 'assignment.changed',default => $type
        };
    }

    public static function occurrence(string $type): bool
    {
        return in_array(self::canonical($type), ['error.created', 'error.regressed', 'error.threshold_reached'], true);
    }
}
