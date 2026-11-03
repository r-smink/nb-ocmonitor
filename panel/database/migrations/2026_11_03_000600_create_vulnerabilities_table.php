<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alleen kwetsbaarheden van plugins, thema's en WordPress-versies die op onze sites voorkomen.
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64);
            $table->string('type', 10);
            $table->string('slug', 190);
            $table->string('title', 500)->nullable();
            $table->json('affected');
            $table->json('patched_versions')->nullable();
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('cvss_rating', 20)->nullable();
            $table->string('cve', 40)->nullable();
            $table->boolean('informational')->default(false);
            $table->string('remediation', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['uuid', 'type', 'slug']);
            $table->index(['type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vulnerabilities');
    }
};
