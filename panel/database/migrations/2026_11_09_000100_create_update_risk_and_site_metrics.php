<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eisen van de nieuwste versie op wordpress.org, voor het risico per update.
        Schema::table('plugin_directory', function (Blueprint $table) {
            $table->string('version', 40)->nullable();
            $table->string('requires_php', 20)->nullable();
            $table->string('requires_wp', 20)->nullable();
            $table->string('tested', 20)->nullable();
        });

        // Changelog van wordpress.org per plugin, als losse versies: [{version, text}].
        Schema::create('plugin_changelogs', function (Blueprint $table) {
            $table->string('slug', 120)->primary();
            $table->string('version', 40);
            $table->json('entries');
            $table->timestamp('fetched_at');
        });

        // Samenvatting van Claude per sprong (van → naar); de changelog is openbaar, dus één keer voor alle sites.
        Schema::create('update_insights', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->string('slug', 120);
            $table->string('from_version', 40);
            $table->string('to_version', 40);
            $table->text('summary')->nullable();
            $table->string('risk', 10)->nullable();
            $table->string('reason', 300)->nullable();
            $table->string('model', 60);
            $table->timestamps();
            $table->unique(['type', 'slug', 'from_version', 'to_version']);
        });

        // Eén meetpunt per site per dag, uit de inventaris, voor de trendgrafieken.
        Schema::create('site_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('db_bytes')->nullable();
            $table->unsignedBigInteger('content_bytes')->nullable();
            $table->unsignedBigInteger('uploads_bytes')->nullable();
            $table->unsignedBigInteger('disk_free_bytes')->nullable();
            $table->boolean('storage_partial')->default(false);
            $table->unsignedSmallInteger('plugins')->default(0);
            $table->unsignedSmallInteger('plugins_active')->default(0);
            $table->unsignedSmallInteger('themes')->default(0);
            $table->unique(['site_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_metrics');
        Schema::dropIfExists('update_insights');
        Schema::dropIfExists('plugin_changelogs');
        Schema::table('plugin_directory', fn (Blueprint $table) => $table->dropColumn(['version', 'requires_php', 'requires_wp', 'tested']));
    }
};
