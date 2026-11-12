<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Aantal verzendpogingen: de signer probeert het opnieuw als de verbinding met de site niet tot stand kwam.
    public function up(): void
    {
        Schema::table('site_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('site_jobs', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
