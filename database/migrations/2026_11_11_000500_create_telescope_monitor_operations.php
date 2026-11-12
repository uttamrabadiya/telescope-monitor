<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Database::connectionName())->table('telescope_monitor_installations', fn (Blueprint $t) => $t->json('retention_cutoffs')->nullable());
        Schema::connection(Database::connectionName())->create('telescope_monitor_purge_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->unsignedInteger('from_generation');
            $t->unsignedInteger('to_generation');
            $t->uuid('source_epoch');
            $t->unsignedBigInteger('source_sequence_floor')->default(0);
            $t->dateTime('source_time_cutoff', 6)->nullable();
            $t->string('initiated_by_actor_key', 191);
            $t->string('confirmation_digest', 64);
            $t->string('state', 24);
            $t->string('current_step', 64);
            $t->json('deletion_counts');
            $t->dateTime('started_at', 6);
            $t->dateTime('completed_at', 6)->nullable();
            $t->string('safe_error_code', 64)->nullable();
            $t->timestamps(6);
            $t->unique(['installation_id', 'to_generation'], 'tm_purge_generation');
            $t->index(['installation_id', 'state'], 'tm_purge_state');
        });
        Database::connection()->table('telescope_monitor_installations')->update(['schema_version' => 6]);
    }

    public function down(): void
    {
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_purge_runs');
        if (Schema::connection(Database::connectionName())->hasColumn('telescope_monitor_installations', 'retention_cutoffs')) {
            Schema::connection(Database::connectionName())->table('telescope_monitor_installations', fn (Blueprint $t) => $t->dropColumn('retention_cutoffs'));
        }if (Schema::connection(Database::connectionName())->hasTable('telescope_monitor_installations')) {
            Database::connection()->table('telescope_monitor_installations')->update(['schema_version' => 5]);
        }
    }
};
