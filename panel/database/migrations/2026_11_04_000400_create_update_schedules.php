<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatische updates: welke sites, welke dagen en tijd, welke soorten, wat nooit.
        Schema::create('update_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->boolean('enabled')->default(true);
            $table->string('scope', 12)->default('all');   // all | tags | customers | sites
            $table->json('scope_ids')->nullable();
            $table->json('types');                          // plugin, theme, core, translations, client
            $table->boolean('security_only')->default(false);
            $table->json('days');                           // ISO-weekdagen 1 (ma) t/m 7 (zo)
            $table->string('time', 5);                      // HH:MM, Nederlandse tijd
            $table->json('exclude')->nullable();            // slugs die nooit automatisch mogen
            $table->date('paused_until')->nullable();
            $table->boolean('rollback_on_visual')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
        });

        Schema::table('site_jobs', function (Blueprint $table) {
            $table->foreignId('update_schedule_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('update_schedule_id');
        });
        Schema::dropIfExists('update_schedules');
    }
};
