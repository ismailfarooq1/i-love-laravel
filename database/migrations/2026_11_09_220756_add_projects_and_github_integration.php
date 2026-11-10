<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backlog_projects', function (Blueprint $table): void {
            $table->dropUnique(['team_id']);
            $table->string('name')->default('Untitled project');
            $table->text('description')->nullable();
            $table->uuid('generation')->nullable();
            $table->string('connection_mode')->default('later');
            $table->string('repository_id')->nullable();
            $table->string('repository_full_name')->nullable();
            $table->boolean('repository_private')->default(true);
            $table->string('webhook_id')->nullable();
            $table->index(['team_id', 'id']);
        });
        DB::table('backlog_projects')->orderBy('id')->each(function (object $project): void {
            $state = json_decode($project->state, true);
            DB::table('backlog_projects')->where('id', $project->id)->update(['generation' => (string) Str::uuid(), 'name' => $state['name'] ?? 'Imported project']);
        });
        Schema::create('git_hub_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('mode');
            $table->string('slot')->default('identity');
            $table->string('github_user_id');
            $table->string('login');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('installation_id')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
            $table->unique(['team_id', 'user_id', 'mode', 'slot'], 'github_connection_identity_unique');
        });
        Schema::table('backlog_projects', function (Blueprint $table): void {
            $table->foreignId('github_connection_id')->nullable()->constrained('git_hub_connections')->restrictOnDelete();
        });
        Schema::create('git_hub_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backlog_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('github_connection_id')->constrained('git_hub_connections')->restrictOnDelete();
            $table->uuid('generation');
            $table->string('repository_id');
            $table->string('repository_full_name');
            $table->string('status')->default('preview');
            $table->json('items');
            $table->text('error')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('git_hub_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backlog_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_connection_id')->constrained('git_hub_connections')->restrictOnDelete();
            $table->uuid('generation');
            $table->string('node_id', 100);
            $table->uuid('marker')->unique();
            $table->string('github_id')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('url')->nullable();
            $table->string('creation_status')->default('reserved');
            $table->json('managed_dependencies')->nullable();
            $table->unsignedInteger('published_revision')->nullable();
            $table->string('remote_state')->default('open');
            $table->string('desired_state')->default('open');
            $table->string('pending_state')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['backlog_project_id', 'generation', 'node_id'], 'github_issue_local_node_unique');
            $table->index('github_id');
        });
        Schema::create('git_hub_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('backlog_projects')->whereNotNull('team_id')->select('team_id')->groupBy('team_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back project support while a team owns multiple projects. No data was removed.');
        }
        Schema::dropIfExists('git_hub_deliveries');
        Schema::dropIfExists('git_hub_issues');
        Schema::dropIfExists('git_hub_publications');
        Schema::table('backlog_projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('github_connection_id');
        });
        Schema::dropIfExists('git_hub_connections');
        Schema::table('backlog_projects', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'id']);
            $table->unique('team_id');
            $table->dropColumn(['name', 'description', 'generation', 'connection_mode', 'repository_id', 'repository_full_name', 'repository_private', 'webhook_id']);
        });
    }
};
