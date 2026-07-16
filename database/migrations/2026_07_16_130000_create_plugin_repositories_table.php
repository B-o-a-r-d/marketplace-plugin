<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom composer sources for the plugins project (marketplace equivalent
     * of composer.json `repositories`).
     */
    public function up(): void
    {
        Schema::create('plugin_repositories', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('vcs');
            $table->string('url')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_repositories');
    }
};
