<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Uptime (ocm:monitor vanuit de scheduler)
            $table->string('uptime_state', 10)->nullable()->after('status');
            $table->timestamp('down_since')->nullable()->after('uptime_state');
            $table->unsignedSmallInteger('uptime_failures')->default(0)->after('down_since');
            $table->timestamp('uptime_checked_at')->nullable()->after('uptime_failures');
            $table->unsignedInteger('response_ms')->nullable()->after('uptime_checked_at');
            // SSL en domein
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_issuer', 190)->nullable();
            $table->string('ssl_error', 255)->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->timestamp('domain_expires_at')->nullable();
            $table->string('domain_error', 255)->nullable();
            $table->timestamp('domain_checked_at')->nullable();
            // WordPress-gebruikers (users.list)
            $table->json('wp_users')->nullable();
            $table->timestamp('wp_users_at')->nullable();
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->index(['site_id', 'checked_at']);
        });

        Schema::create('visual_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->json('job_ids')->nullable();
            $table->longText('before_image')->nullable();
            $table->longText('after_image')->nullable();
            $table->longText('diff_image')->nullable();
            $table->decimal('diff_percent', 5, 2)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('before_at')->nullable();
            $table->timestamp('after_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visual_checks');
        Schema::dropIfExists('uptime_checks');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['uptime_state', 'down_since', 'uptime_failures', 'uptime_checked_at', 'response_ms', 'ssl_expires_at', 'ssl_issuer',
                'ssl_error', 'ssl_checked_at', 'domain_expires_at', 'domain_error', 'domain_checked_at', 'wp_users', 'wp_users_at']);
        });
    }
};
