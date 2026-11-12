<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection(Database::connectionName())->hasTable('telescope_monitor_installations') && Schema::connection(Database::connectionName())->hasTable('telescope_monitor_issues')) {
            throw new RuntimeException('Prototype tables detected. Back up the database and use an explicitly reviewed conversion or a fresh database. No tables were changed.');
        }
        Schema::connection(Database::connectionName())->create('telescope_monitor_installations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('singleton_key', 32)->unique();
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('state', 16)->default('active');
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedBigInteger('config_revision')->default(1);
            $table->uuid('source_epoch');
            $table->string('source_signature', 64)->nullable();
            $table->unsignedBigInteger('source_sequence_checkpoint')->default(0);
            $table->unsignedBigInteger('source_sequence_floor')->default(0);
            $table->dateTime('purged_through_at', 6)->nullable();
            $table->unsignedBigInteger('last_purge_run_id')->nullable();
            $table->unsignedInteger('setup_version')->default(1);
            $table->json('setup_state')->nullable();
            $table->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $table->string('group_key', 64);
            $table->json('values');
            $table->unsignedInteger('values_schema_version')->default(1);
            $table->unsignedInteger('row_version')->default(1);
            $table->string('updated_by_actor_key', 191)->nullable();
            $table->timestamps(6);
            $table->unique(['installation_id', 'group_key'], 'tm_settings_group');
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $table->string('check_key', 64);
            $table->string('evidence_kind', 24);
            $table->string('state', 24);
            $table->dateTime('observed_at', 6)->nullable();
            $table->dateTime('last_started_at', 6)->nullable();
            $table->dateTime('last_success_at', 6)->nullable();
            $table->dateTime('last_failure_at', 6)->nullable();
            $table->json('sanitized_details');
            $table->uuid('operation_public_id')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps(6);
            $table->unique(['installation_id', 'check_key'], 'tm_health_check');
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_action_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $table->string('actor_lookup_hash', 64);
            $table->string('actor_key', 191);
            $table->string('operation', 64);
            $table->uuid('client_key');
            $table->string('keyed_request_digest', 64);
            $table->string('state', 24);
            $table->string('result_type', 64)->nullable();
            $table->uuid('result_public_id')->nullable();
            $table->unsignedInteger('resulting_row_version')->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->index();
            $table->timestamps(6);
            $table->unique(['installation_id', 'actor_lookup_hash', 'operation', 'client_key'], 'tm_action_idempotency');
        });
    }

    public function down(): void
    {
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_action_receipts');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_health_checks');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_settings');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_installations');
    }
};
