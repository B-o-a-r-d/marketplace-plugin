<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_packages', function (Blueprint $table) {
            // The SDK contract version the plugin was built against
            // (extra.board.sdk_contract). Null = legacy install with no
            // declaration → treated as incompatible until reinstalled.
            $table->unsignedInteger('contract_version')->nullable()->after('sdk_constraint');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_packages', function (Blueprint $table) {
            $table->dropColumn('contract_version');
        });
    }
};
