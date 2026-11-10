<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Korte servernaam (bijv. DO1, DO3) zodat in één oogopslag te zien is waar een site draait.
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('server', 20)->nullable()->after('host')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['server']);
            $table->dropColumn('server');
        });
    }
};
