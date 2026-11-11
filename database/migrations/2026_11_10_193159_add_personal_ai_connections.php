<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connections', function (Blueprint $table): void {
            $table->dropUnique(['team_id', 'provider']);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('slot')->default('team');
            $table->string('auth_type')->default('api_key');
            $table->boolean('shared_with_team')->default(true);
            $table->unique(['team_id', 'provider', 'slot']);
        });
    }

    public function down(): void
    {
        if (DB::table('ai_connections')->whereNotNull('user_id')->exists()) {
            throw new RuntimeException('Remove personal AI connections before rolling back. No credentials were deleted.');
        }
        Schema::table('ai_connections', function (Blueprint $table): void {
            $table->dropUnique(['team_id', 'provider', 'slot']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['slot', 'auth_type', 'shared_with_team']);
            $table->unique(['team_id', 'provider']);
        });
    }
};
