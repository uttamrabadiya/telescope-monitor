<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Database::connectionName())->create('exception_monitor_notes', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->foreignId('issue_id')->constrained('exception_monitor_issues');
            $t->string('author_actor_key', 191);
            $t->string('author_lookup_hash', 64);
            $t->longText('body_ciphertext')->nullable();
            $t->unsignedInteger('body_version')->default(1);
            $t->unsignedInteger('row_version')->default(1);
            $t->dateTime('edited_at', 6)->nullable();
            $t->dateTime('deleted_at', 6)->nullable();
            $t->string('deleted_by_actor_key', 191)->nullable();
            $t->timestamps(6);
            $t->index(['issue_id', 'created_at', 'id'], 'em_notes_history');
            $t->index(['installation_id', 'author_lookup_hash'], 'em_notes_author');
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_saved_views', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->string('owner_actor_key', 191);
            $t->string('owner_lookup_hash', 64);
            $t->string('name', 80);
            $t->string('visibility', 16)->default('personal');
            $t->unsignedInteger('filter_schema_version')->default(1);
            $t->json('filters');
            $t->string('sort_key', 32);
            $t->json('relative_range');
            $t->unsignedInteger('row_version')->default(1);
            $t->timestamps(6);
            $t->index(['installation_id', 'visibility', 'name'], 'em_views_visibility');
            $t->index(['installation_id', 'owner_lookup_hash', 'updated_at'], 'em_views_owner');
        });
        Schema::connection(Database::connectionName())->create('exception_monitor_view_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('installation_id')->constrained('exception_monitor_installations');
            $t->string('actor_key', 191);
            $t->string('actor_lookup_hash', 64);
            $t->unsignedInteger('preferences_version')->default(1);
            $t->json('shortcuts');
            $t->string('default_view_ref', 64)->nullable();
            $t->unsignedInteger('row_version')->default(1);
            $t->timestamps(6);
            $t->unique(['installation_id', 'actor_lookup_hash'], 'em_view_actor_preferences');
        });
        Schema::connection(Database::connectionName())->table('exception_monitor_issues', fn (Blueprint $t) => $t->index(['installation_id', 'assignee_lookup_hash', 'status'], 'em_issue_assignee'));
        Database::connection()->table('exception_monitor_installations')->update(['schema_version' => 3]);
    }

    public function down(): void
    {
        if (Schema::connection(Database::connectionName())->hasTable('exception_monitor_issues') && Schema::connection(Database::connectionName())->hasIndex('exception_monitor_issues', 'em_issue_assignee')) {
            Schema::connection(Database::connectionName())->table('exception_monitor_issues', fn (Blueprint $t) => $t->dropIndex('em_issue_assignee'));
        }
        Schema::connection(Database::connectionName())->dropIfExists('exception_monitor_view_preferences');
        Schema::connection(Database::connectionName())->dropIfExists('exception_monitor_saved_views');
        Schema::connection(Database::connectionName())->dropIfExists('exception_monitor_notes');
        if (Schema::connection(Database::connectionName())->hasTable('exception_monitor_installations')) {
            Database::connection()->table('exception_monitor_installations')->update(['schema_version' => 2]);
        }
    }
};
