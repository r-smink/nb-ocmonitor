<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 64)->unique(); // publieke id in het protocol, bv. site_7f3a…
            $table->string('name');
            $table->string('client_name')->nullable();
            $table->string('url');
            $table->string('host');
            $table->string('status', 16)->default('pending'); // pending | online | unreachable | error
            $table->json('scopes')->nullable();
            $table->string('public_key', 64)->nullable(); // base64url, 32 bytes
            $table->string('key_id', 32)->nullable();
            $table->text('pairing_secret')->nullable(); // versleuteld; eenmalig
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('wp_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->string('plugin_version', 32)->nullable();
            $table->longText('inventory')->nullable();
            $table->timestamp('inventory_at')->nullable();
            $table->unsignedInteger('updates_count')->default(0);
            $table->unsignedBigInteger('last_event_seq')->default(0); // hoogste aaneengesloten ontvangen seq
            $table->timestamp('event_gap_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->timestamp('occurred_at');
            $table->string('type', 64);
            $table->string('object', 191)->default('');
            $table->string('from_version', 191)->nullable();
            $table->string('to_version', 191)->nullable();
            $table->string('result', 16);
            $table->string('source', 16);
            $table->string('job_id', 64)->nullable();
            $table->string('user_login', 60)->default('');
            $table->string('ip', 45)->default('');
            $table->string('user_agent')->default('');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['site_id', 'seq']);
            $table->index(['site_id', 'occurred_at']);
        });

        Schema::create('site_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 64)->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('command', 64);
            $table->json('args')->nullable();
            $table->string('status', 16)->default('pending'); // pending | running | done | failed | rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['site_id', 'command', 'status']);
        });

        Schema::create('protocol_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->unsignedBigInteger('expires_at')->index();
        });

        // Append-only met hash-keten: hash = sha256(prev_hash || canonieke regel).
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('protocol_nonces');
        Schema::dropIfExists('site_jobs');
        Schema::dropIfExists('site_events');
        Schema::dropIfExists('sites');
    }
};
