<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composer-sourced plugins: the composer package name and the install
     * source ('composer' or the legacy 'archive' zipball extract).
     */
    public function up(): void
    {
        Schema::table('plugin_packages', function (Blueprint $table) {
            $table->string('package_name')->nullable()->after('repo');
            $table->string('source')->default('archive')->after('package_name');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_packages', function (Blueprint $table) {
            $table->dropColumn(['package_name', 'source']);
        });
    }
};
