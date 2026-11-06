<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatische planning: naast "nooit" ook "alleen deze" plugins en thema's.
        Schema::table('update_schedules', function (Blueprint $table) {
            $table->json('include')->nullable()->after('exclude');
        });

        // Per klant: laatste rapport downloadbaar maken in de dashboardwidget op de site.
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('widget_report')->default(false)->after('report_enabled');
        });

        // Opgevangen fatale PHP-fouten van de sites (errors.list).
        Schema::create('php_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('hash', 16);
            $table->string('type', 30);
            $table->text('message');
            $table->string('file', 300);
            $table->unsignedInteger('line')->default(0);
            $table->string('url', 300)->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unique(['site_id', 'hash']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('php_errors_seen')->default(0);
            $table->json('debug_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['php_errors_seen', 'debug_log']));
        Schema::dropIfExists('php_errors');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('widget_report'));
        Schema::table('update_schedules', fn (Blueprint $t) => $t->dropColumn('include'));
    }
};
