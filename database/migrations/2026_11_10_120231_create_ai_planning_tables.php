<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->text('api_key');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['team_id', 'provider']);
        });
        Schema::create('ai_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backlog_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_connection_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('connection_revision');
            $table->string('provider');
            $table->string('model');
            $table->uuid('generation');
            $table->unsignedInteger('project_version');
            $table->string('parent_node_id', 100)->nullable();
            $table->string('status')->default('queued');
            $table->json('input');
            $table->json('result')->nullable();
            $table->json('usage')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['backlog_project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
        Schema::dropIfExists('ai_connections');
    }
};
