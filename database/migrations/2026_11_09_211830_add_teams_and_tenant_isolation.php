<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::table('backlog_projects', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backlog_projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
