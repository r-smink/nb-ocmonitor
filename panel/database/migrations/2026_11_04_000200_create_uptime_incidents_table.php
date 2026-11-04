<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Storingen (van offline tot weer online). Blijven langer bewaard dan de losse controles,
        // zodat de uptime per maand ook na het opschonen van uptime_checks klopt.
        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('cause', 255)->nullable();
            $table->timestamps();
            $table->index(['site_id', 'started_at']);
        });

        Schema::table('sites', function (Blueprint $table) {
            // Sinds wanneer de uptime gemeten wordt (begin van de SLA-berekening).
            $table->timestamp('uptime_since')->nullable();
        });

        \App\Ocm\Monitoring\Incidents::backfill();
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('uptime_since'));
        Schema::dropIfExists('uptime_incidents');
    }
};
