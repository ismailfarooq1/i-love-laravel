<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connections', function (Blueprint $table): void {
            $table->text('credentials')->nullable();
        });
        DB::table('ai_connections')->where('provider', '!=', 'chatgpt')->update(['api_key' => Crypt::encryptString('')]);
    }

    public function down(): void
    {
        Schema::table('ai_connections', function (Blueprint $table): void {
            $table->dropColumn('credentials');
        });
    }
};
