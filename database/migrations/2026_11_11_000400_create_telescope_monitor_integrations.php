<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Database::connectionName())->table('telescope_monitor_connections', function (Blueprint $t) {
            $t->uuid('transport_claim_token')->nullable();
            $t->dateTime('transport_lease_until', 6)->nullable();
            $t->dateTime('next_send_at', 6)->nullable();
        });
        Schema::connection(Database::connectionName())->table('telescope_monitor_deliveries', fn (Blueprint $t) => $t->string('credential_key_id', 64)->nullable());
        Schema::connection(Database::connectionName())->create('telescope_monitor_linked_issues', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('telescope_monitor_installations');
            $t->foreignId('issue_id')->constrained('telescope_monitor_issues');
            $t->foreignId('connection_id')->constrained('telescope_monitor_connections');
            $t->string('repository_fingerprint', 64);
            $t->string('repository_owner', 100);
            $t->string('repository_name', 100);
            $t->string('state', 24)->default('reserved');
            $t->uuid('operation_public_id');
            $t->foreignId('create_delivery_id')->nullable()->constrained('telescope_monitor_deliveries')->nullOnDelete();
            $t->unsignedBigInteger('remote_number')->nullable();
            $t->string('remote_url', 512)->nullable();
            $t->string('last_known_remote_state', 24)->nullable();
            $t->dateTime('checked_at', 6)->nullable();
            $t->string('linked_by_actor_key', 191)->nullable();
            $t->unsignedInteger('row_version')->default(1);
            $t->timestamps(6);
            $t->unique(['issue_id', 'repository_fingerprint'], 'tm_issue_repository');
            $t->index(['connection_id', 'state'], 'tm_link_connection_state');
            $t->index(['repository_fingerprint', 'remote_number'], 'tm_remote_issue');
        });
        Database::connection()->table('telescope_monitor_installations')->update(['schema_version' => 5]);
    }

    public function down(): void
    {
        Schema::connection(Database::connectionName())->dropIfExists('telescope_monitor_linked_issues');
        if (Schema::connection(Database::connectionName())->hasColumn('telescope_monitor_deliveries', 'credential_key_id')) {
            Schema::connection(Database::connectionName())->table('telescope_monitor_deliveries', fn (Blueprint $t) => $t->dropColumn('credential_key_id'));
        }
        if (Schema::connection(Database::connectionName())->hasColumn('telescope_monitor_connections', 'transport_claim_token')) {
            Schema::connection(Database::connectionName())->table('telescope_monitor_connections', fn (Blueprint $t) => $t->dropColumn(['transport_claim_token', 'transport_lease_until', 'next_send_at']));
        }
        if (Schema::connection(Database::connectionName())->hasTable('telescope_monitor_installations')) {
            Database::connection()->table('telescope_monitor_installations')->update(['schema_version' => 4]);
        }
    }
};
