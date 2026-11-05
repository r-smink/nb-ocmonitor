<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12); // running, done, failed
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('links')->default(0);
            $table->unsignedInteger('broken')->default(0);
            $table->string('error', 300)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'id']);
        });

        Schema::create('broken_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 40);
            $table->string('kind', 8); // link, image
            $table->boolean('internal')->default(false);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 200)->nullable();
            $table->json('found_on');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ignored_at')->nullable();
            $table->unique(['site_id', 'url_hash']);
        });

        Schema::create('link_cache', function (Blueprint $table) {
            $table->char('url_hash', 40)->primary();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 200)->nullable();
            $table->string('verdict', 8); // ok, broken, unknown
            $table->timestamp('checked_at');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('links_requested_at')->nullable();
            $table->string('ga4_property', 40)->nullable();
            $table->string('gsc_property', 255)->nullable();
        });

        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->json('data');
            $table->timestamp('fetched_at');
            $table->unique(['site_id', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['links_requested_at', 'ga4_property', 'gsc_property']));
        Schema::dropIfExists('link_cache');
        Schema::dropIfExists('broken_links');
        Schema::dropIfExists('link_scans');
    }
};
