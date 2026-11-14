<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Database::connectionName())->table('telescope_monitor_occurrences', fn (Blueprint $t) => $t->index(['installation_id', 'occurred_at', 'issue_id'], 'tm_report_occurrences'));
        Schema::connection(Database::connectionName())->table('telescope_monitor_activity', fn (Blueprint $t) => $t->index(['installation_id', 'occurred_at', 'issue_id'], 'tm_report_activity'));
    }

    public function down(): void
    {
        if (Database::schema()->hasTable('telescope_monitor_activity')) {
            Database::schema()->table('telescope_monitor_activity', fn (Blueprint $t) => $t->dropIndex('tm_report_activity'));
        }
        if (Database::schema()->hasTable('telescope_monitor_occurrences')) {
            Database::schema()->table('telescope_monitor_occurrences', fn (Blueprint $t) => $t->dropIndex('tm_report_occurrences'));
        }
    }
};
