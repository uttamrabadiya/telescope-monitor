<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Database::connectionName())->create('exception_monitor_connections', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->string('provider', 16);
            $t->string('display_name', 120);
            $t->string('target_fingerprint', 64);
            $t->json('target_public');
            $t->boolean('enabled')->default(false);
            $t->dateTime('disconnected_at', 6)->nullable();
            $t->json('options');
            $t->unsignedInteger('options_version')->default(1);
            $t->unsignedInteger('row_version')->default(1);
            $t->unsignedBigInteger('replaces_connection_id')->nullable();
            $t->dateTime('last_test_at', 6)->nullable();
            $t->string('last_test_outcome', 32)->nullable();
            $t->unique(['installation_id', 'provider', 'target_fingerprint'], 'em_connection_target');
            $t->index(['installation_id', 'provider', 'enabled'], 'em_connection_enabled');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_alert_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->string('name', 120);
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('definition_version')->default(1);
            $t->json('event_types');
            $t->json('conditions');
            $t->unsignedInteger('threshold_count')->nullable();
            $t->unsignedInteger('window_seconds')->nullable();
            $t->unsignedInteger('cooldown_seconds')->default(1800);
            $t->string('created_by_actor_key', 191);
            $t->unsignedInteger('row_version')->default(1);
            $t->dateTime('deleted_at', 6)->nullable();
            $t->index(['installation_id', 'enabled', 'deleted_at'], 'em_rules_enabled');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_rule_connections', function (Blueprint $t) {
            $t->foreignId('rule_id')->constrained('exception_monitor_alert_rules');
            $t->foreignId('connection_id')->constrained('exception_monitor_connections');
            $t->primary(['rule_id', 'connection_id'], 'em_rule_connection');
            $t->index('connection_id');
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->unsignedInteger('generation');
            $t->foreignId('issue_id')->nullable()->constrained('exception_monitor_issues');
            $t->foreignId('event_id')->constrained('exception_monitor_alert_events');
            $t->foreignId('connection_id')->constrained('exception_monitor_connections');
            $t->string('action', 32);
            $t->string('dedupe_key', 64);
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('parent_delivery_id')->nullable();
            $t->string('target_fingerprint', 64);
            $t->string('actor_key', 191)->nullable();
            $t->boolean('actor_required')->default(false);
            $t->string('event_family', 64);
            $t->unsignedInteger('alert_generation')->default(0);
            $t->string('status', 24)->default('pending');
            $t->string('cancellation_reason', 64)->nullable();
            $t->dateTime('eligible_at', 6);
            $t->dateTime('expires_at', 6);
            $t->unsignedInteger('attempt_count_total')->default(0);
            $t->unsignedInteger('retry_cycle')->default(1);
            $t->unsignedInteger('cycle_attempts')->default(0);
            $t->uuid('claim_token')->nullable();
            $t->dateTime('lease_until', 6)->nullable();
            $t->longText('payload_ciphertext')->nullable();
            $t->unsignedInteger('payload_version')->default(1);
            $t->string('payload_digest', 64)->nullable();
            $t->dateTime('payload_frozen_at', 6)->nullable();
            $t->unsignedBigInteger('export_policy_revision')->nullable();
            $t->dateTime('sent_at', 6)->nullable();
            $t->dateTime('completed_at', 6)->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->unique(['installation_id', 'generation', 'dedupe_key', 'revision'], 'em_delivery_identity');
            $t->index(['status', 'eligible_at', 'id'], 'em_delivery_due');
            $t->index(['status', 'lease_until'], 'em_delivery_leases');
            $t->index(['issue_id', 'created_at', 'id'], 'em_issue_deliveries');
            $t->index(['connection_id', 'status'], 'em_connection_deliveries');
            $t->index('completed_at');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_delivery_rules', function (Blueprint $t) {
            $t->foreignId('delivery_id')->constrained('exception_monitor_deliveries')->cascadeOnDelete();
            $t->foreignId('rule_id')->nullable()->constrained('exception_monitor_alert_rules');
            $t->uuid('rule_public_id');
            $t->unsignedInteger('rule_version');
            $t->foreignId('matching_event_id')->constrained('exception_monitor_alert_events');
            $t->json('conditions_snapshot');
            $t->primary(['delivery_id', 'rule_public_id', 'rule_version', 'matching_event_id'], 'em_delivery_support');
            $t->index(['rule_id', 'delivery_id'], 'em_rule_deliveries');
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_delivery_attempts', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->foreignId('delivery_id')->constrained('exception_monitor_deliveries')->cascadeOnDelete();
            $t->unsignedInteger('sequence_number');
            $t->unsignedInteger('retry_cycle');
            $t->uuid('claim_token');
            $t->dateTime('started_at', 6);
            $t->dateTime('completed_at', 6)->nullable();
            $t->string('outcome', 32);
            $t->unsignedInteger('provider_status')->nullable();
            $t->string('safe_error_code', 64)->nullable();
            $t->string('sanitized_error_summary', 255)->nullable();
            $t->dateTime('retry_after_at', 6)->nullable();
            $t->string('used_secret_key_id', 64)->nullable();
            $t->string('validated_remote_reference', 255)->nullable();
            $t->unique(['delivery_id', 'sequence_number'], 'em_attempt_sequence');
            $t->index(['delivery_id', 'started_at'], 'em_attempt_history');
            $t->index('completed_at');
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_notification_gates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->foreignId('issue_id')->constrained('exception_monitor_issues');
            $t->string('target_fingerprint', 64);
            $t->string('event_family', 64);
            $t->dateTime('last_sent_at', 6)->nullable();
            $t->uuid('last_event_public_id')->nullable();
            $t->unsignedBigInteger('reserved_delivery_id')->nullable();
            $t->foreign('reserved_delivery_id', 'em_gate_reserved_delivery_fk')->references('id')->on('exception_monitor_deliveries')->nullOnDelete();
            $t->uuid('reservation_token')->nullable();
            $t->dateTime('lease_until', 6)->nullable();
            $t->unique(['issue_id', 'target_fingerprint', 'event_family'], 'em_notification_gate');
            $t->index('lease_until');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->table('exception_monitor_secrets', fn (Blueprint $t) => $t->foreign('connection_id')->references('id')->on('exception_monitor_connections'));
        Database::connection()->table('exception_monitor_installations')->update(['schema_version' => 4]);
    }

    public function down(): void
    {
        if (Schema::connection(Database::connectionName())->hasTable('exception_monitor_connections') && Schema::connection(Database::connectionName())->hasTable('exception_monitor_secrets')) {
            Schema::connection(Database::connectionName())->table('exception_monitor_secrets', fn (Blueprint $t) => $t->dropForeign(['connection_id']));
        }
        foreach (['notification_gates', 'delivery_attempts', 'delivery_rules', 'deliveries', 'rule_connections', 'alert_rules', 'connections'] as $table) {
            Schema::connection(Database::connectionName())->dropIfExists('exception_monitor_'.$table);
        }
        if (Schema::connection(Database::connectionName())->hasTable('exception_monitor_installations')) {
            Database::connection()->table('exception_monitor_installations')->update(['schema_version' => 3]);
        }
    }
};
