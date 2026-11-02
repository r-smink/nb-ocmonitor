<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Gezet door "Nu meten"; ocm:pagespeed pakt deze sites als eerste op.
            $table->timestamp('pagespeed_requested_at')->nullable();
        });

        Schema::create('pagespeed_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('strategy', 8); // mobile | desktop
            $table->unsignedTinyInteger('performance')->nullable();
            $table->unsignedTinyInteger('accessibility')->nullable();
            $table->unsignedTinyInteger('best_practices')->nullable();
            $table->unsignedTinyInteger('seo')->nullable();
            // Labmetingen (Lighthouse)
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->unsignedInteger('fcp_ms')->nullable();
            $table->unsignedInteger('tbt_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->decimal('cls', 6, 3)->nullable();
            // Velddata van echte bezoekers (CrUX), als Google die heeft
            $table->json('field')->nullable();
            $table->json('opportunities')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('measured_at');
            $table->index(['site_id', 'strategy', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagespeed_results');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('pagespeed_requested_at');
        });
    }
};
