<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_packages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('repo');                 // owner/name on GitHub
            $table->string('version');              // installed release tag (normalized)
            $table->string('sdk_constraint')->nullable();
            $table->string('path')->nullable();     // storage-relative extract dir
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('installed_by')->nullable();
            $table->string('available_version')->nullable();
            $table->boolean('breaking_update')->default(false);
            $table->text('load_error')->nullable(); // set when the loader can't boot it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_packages');
    }
};
