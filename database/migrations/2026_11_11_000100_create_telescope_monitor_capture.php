<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Database::connectionName())->create('telescope_monitor_capture_inbox', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->unsignedInteger('generation');
            $t->uuid('source_epoch');
            $t->string('capture_key', 64);
            $t->string('kind', 32);
            $t->string('mode', 24);
            $t->uuid('source_uuid')->nullable();
            $t->uuid('batch_uuid')->nullable();
            $t->unsignedBigInteger('source_sequence')->nullable();
            $t->string('environment', 64);
            $t->dateTime('occurred_at', 6);
            $t->string('time_precision', 16)->default('microsecond');
            $t->dateTime('received_at', 6);
            $t->unsignedInteger('grouping_version')->default(1);
            $t->string('fingerprint_hash', 64)->nullable();
            $t->unsignedBigInteger('privacy_revision');
            $t->unsignedInteger('envelope_version')->default(1);
            $t->longText('payload_ciphertext')->nullable();
            $t->unsignedInteger('plaintext_bytes')->default(0);
            $t->string('payload_digest', 64)->nullable();
            $t->string('state', 24)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->dateTime('eligible_at', 6);
            $t->uuid('claim_token')->nullable();
            $t->dateTime('lease_until', 6)->nullable();
            $t->dateTime('processed_at', 6)->nullable();
            $t->dateTime('completed_at', 6)->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->unique(['installation_id', 'generation', 'capture_key'], 'tm_capture_identity');
            $t->index(['state', 'eligible_at', 'id'], 'tm_capture_due');
            $t->index(['state', 'lease_until'], 'tm_capture_leases');
            $t->index('completed_at');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_issues', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->string('environment', 64);
            $t->boolean('is_test')->default(false);
            $t->unsignedInteger('grouping_version');
            $t->string('fingerprint_hash', 64);
            $t->string('exception_class', 512);
            $t->text('message');
            $t->text('file');
            $t->unsignedInteger('line');
            $t->string('status', 16)->default('unresolved');
            $t->unsignedBigInteger('lifetime_count')->default(0);
            $t->dateTime('first_seen_at', 6);
            $t->dateTime('last_seen_at', 6);
            $t->dateTime('resolved_at', 6)->nullable();
            $t->dateTime('resolution_cutoff_at', 6)->nullable();
            $t->dateTime('snoozed_at', 6)->nullable();
            $t->dateTime('snoozed_until', 6)->nullable();
            $t->dateTime('notification_cutoff_at', 6)->nullable();
            $t->unsignedInteger('alert_generation')->default(0);
            $t->string('assignee_actor_key', 191)->nullable();
            $t->string('assignee_lookup_hash', 64)->nullable();
            $t->unsignedInteger('state_version')->default(1);
            $t->boolean('recovered_only')->default(false);
            $t->unique(['installation_id', 'environment', 'is_test', 'grouping_version', 'fingerprint_hash'], 'tm_issue_group');
            $t->index(['installation_id', 'environment', 'is_test', 'status', 'last_seen_at', 'id'], 'tm_issue_inbox');
            $t->index(['status', 'snoozed_until'], 'tm_issue_snooze');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_occurrences', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->foreignId('issue_id')->constrained('telescope_monitor_issues');
            $t->uuid('source_epoch');
            $t->uuid('source_uuid');
            $t->unsignedBigInteger('source_sequence')->nullable();
            $t->uuid('batch_uuid')->nullable();
            $t->dateTime('occurred_at', 6);
            $t->string('time_precision', 16);
            $t->dateTime('received_at', 6);
            $t->string('origin', 16);
            $t->string('mode', 24);
            $t->boolean('is_test');
            $t->unique(['installation_id', 'source_epoch', 'source_uuid'], 'tm_occurrence_identity');
            $t->index(['issue_id', 'occurred_at', 'id'], 'tm_occurrence_history');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_occurrence_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('occurrence_id')->unique()->constrained('telescope_monitor_occurrences');
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->unsignedInteger('evidence_version')->default(1);
            $t->unsignedBigInteger('privacy_revision');
            $t->longText('payload_ciphertext');
            $t->unsignedInteger('plaintext_bytes');
            $t->string('payload_digest', 64);
            $t->string('route_name', 191)->nullable();
            $t->text('path_template')->nullable();
            $t->string('job_class', 512)->nullable();
            $t->string('command_name', 191)->nullable();
            $t->string('release', 191)->nullable();
            $t->string('impact_subject_hash', 64)->nullable();
            $t->string('impact_key_id', 64)->nullable();
            $t->json('coverage');
            $t->dateTime('expires_at', 6)->index();
            $t->index(['installation_id', 'route_name', 'occurrence_id'], 'tm_evidence_route');
            $t->index(['installation_id', 'release', 'occurrence_id'], 'tm_evidence_release');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_evidence_tags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('evidence_id')->constrained('telescope_monitor_occurrence_evidence')->cascadeOnDelete();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->string('tag_key', 64);
            $t->string('tag_value', 64)->nullable();
            $t->string('tag_hash', 64);
            $t->unique(['evidence_id', 'tag_hash'], 'tm_evidence_tag');
            $t->index(['installation_id', 'tag_hash', 'evidence_id'], 'tm_tag_lookup');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_metric_buckets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->foreignId('issue_id')->constrained('telescope_monitor_issues');
            $t->string('resolution', 4);
            $t->dateTime('bucket_start_at', 6);
            $t->unsignedBigInteger('occurrence_count')->default(0);
            $t->unsignedBigInteger('new_group_count')->default(0);
            $t->unsignedBigInteger('regression_count')->default(0);
            $t->dateTime('expires_at', 6)->index();
            $t->unique(['issue_id', 'resolution', 'bucket_start_at'], 'tm_metric_bucket');
            $t->index(['installation_id', 'resolution', 'bucket_start_at'], 'tm_metric_range');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_activity', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->foreignId('issue_id')->nullable()->constrained('telescope_monitor_issues');
            $t->string('event_type', 64);
            $t->string('actor_kind', 16);
            $t->string('actor_key', 191)->nullable();
            $t->string('subject_type', 64);
            $t->uuid('subject_public_id');
            $t->uuid('action_public_id');
            $t->dateTime('occurred_at', 6);
            $t->json('metadata');
            $t->dateTime('expires_at', 6)->index();
            $t->unique(['installation_id', 'action_public_id', 'event_type', 'subject_public_id'], 'tm_activity_action');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_alert_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->foreignId('issue_id')->nullable()->constrained('telescope_monitor_issues');
            $t->unsignedInteger('generation');
            $t->string('event_type', 64);
            $t->unsignedInteger('event_version')->default(1);
            $t->string('event_key', 64);
            $t->foreignId('occurrence_id')->nullable()->constrained('telescope_monitor_occurrences');
            $t->uuid('action_public_id')->nullable();
            $t->unsignedInteger('issue_state_version');
            $t->unsignedInteger('alert_generation');
            $t->dateTime('occurred_at', 6);
            $t->dateTime('recorded_at', 6);
            $t->string('provenance', 24);
            $t->string('actor_key', 191)->nullable();
            $t->longText('payload_ciphertext');
            $t->unsignedInteger('payload_version')->default(1);
            $t->string('payload_digest', 64);
            $t->string('planning_state', 24)->default('disabled');
            $t->dateTime('expires_at', 6)->index();
            $t->unique(['installation_id', 'generation', 'event_key'], 'tm_event_identity');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->create('telescope_monitor_secrets', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->unsignedBigInteger('connection_id')->nullable();
            $t->string('owner_key', 64);
            $t->string('secret_name', 64);
            $t->unsignedInteger('revision');
            $t->string('key_id', 64)->unique();
            $t->string('source', 16)->default('database');
            $t->string('deployment_reference', 191)->nullable();
            $t->longText('ciphertext')->nullable();
            $t->unsignedInteger('encryption_version')->default(1);
            $t->string('state', 16)->default('active');
            $t->dateTime('activated_at', 6)->nullable();
            $t->dateTime('retire_after', 6)->nullable();
            $t->string('created_by_actor_key', 191)->nullable();
            $t->unique(['installation_id', 'owner_key', 'secret_name', 'revision'], 'tm_secret_revision');
            $t->timestamps(6);
        });
        Schema::connection(Database::connectionName())->table('telescope_monitor_action_receipts', function (Blueprint $t) {
            $t->uuid('public_id')->nullable()->unique();
            $t->unsignedInteger('generation')->default(1);
        });
        Database::connection()->table('telescope_monitor_installations')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'singleton_key' => 'default',
            'source_epoch' => (string) Str::uuid(),
            'setup_state' => '[]',
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        Database::connection()->table('telescope_monitor_installations')->update(['schema_version' => 2]);
    }

    public function down(): void
    {
        if (Schema::connection(Database::connectionName())->hasColumn('telescope_monitor_action_receipts', 'public_id')) {
            Schema::connection(Database::connectionName())->table('telescope_monitor_action_receipts', function (Blueprint $t) {
                $t->dropUnique(['public_id']);
                $t->dropColumn(['public_id', 'generation']);
            });
        }
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_secrets');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_alert_events');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_activity');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_metric_buckets');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_evidence_tags');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_occurrence_evidence');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_occurrences');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_issues');
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_capture_inbox');
        if (Schema::connection(Database::connectionName())->hasTable('telescope_monitor_installations')) {
            Database::connection()->table('telescope_monitor_installations')->update(['schema_version' => 1]);
        }
    }
};
