<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notities per site, bijv. "klant wil in december geen updates". Vastgezet = altijd zichtbaar.
        Schema::create('site_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->index(['site_id', 'pinned']);
        });

        // Tokens voor de alleen-lezen API (/api/v1). Alleen de SHA-256 van het token wordt bewaard.
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 12);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('site_notes');
    }
};
