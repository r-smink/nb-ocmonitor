<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Laatste integriteitscontrole (security.integrity, plugin 0.9.0+).
            $table->json('integrity')->nullable();
            $table->timestamp('integrity_at')->nullable();
        });

        // Openbare gegevens van wordpress.org per plugin: gesloten? wanneer laatst bijgewerkt?
        Schema::create('plugin_directory', function (Blueprint $table) {
            $table->string('slug', 120)->primary();
            $table->boolean('listed')->default(false);   // staat (of stond) op wordpress.org
            $table->boolean('closed')->default(false);
            $table->string('closed_reason', 120)->nullable();
            $table->date('closed_date')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_directory');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['integrity', 'integrity_at']));
    }
};
