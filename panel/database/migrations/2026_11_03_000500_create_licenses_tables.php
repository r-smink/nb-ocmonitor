<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('plugin_file', 190);
            // elementor_pro, acf_pro, option (sleutel in een optie) of manual (zelf in WP-admin)
            $table->string('method', 20);
            $table->string('option_name', 160)->nullable();
            // Sleutel in de inventaris waaronder de site de status meldt (elementor_pro, acf_pro, rank_math_pro)
            $table->string('status_key', 40)->nullable();
            $table->string('settings_path', 190)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->text('key');
            $table->unsignedInteger('seats')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('license_products');
    }
};
