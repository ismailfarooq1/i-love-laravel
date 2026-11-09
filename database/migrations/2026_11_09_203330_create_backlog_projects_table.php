<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlog_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->json('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlog_projects');
    }
};
