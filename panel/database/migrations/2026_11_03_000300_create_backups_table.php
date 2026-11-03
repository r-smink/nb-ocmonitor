<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('backup_id', 32)->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // pending → ready → (restoring → ready) → deleted, of failed
            $table->string('status', 16);
            $table->json('components')->nullable();
            $table->foreignId('job_id')->nullable()->constrained('site_jobs')->nullOnDelete();
            $table->json('update_job_ids')->nullable();
            $table->unsignedBigInteger('db_size')->nullable();
            $table->unsignedBigInteger('files_size')->nullable();
            $table->char('db_sha256', 64)->nullable();
            $table->char('files_sha256', 64)->nullable();
            $table->unsignedInteger('tables')->nullable();
            $table->boolean('woocommerce')->default(false);
            $table->string('error', 500)->nullable();
            $table->foreignId('restore_job_id')->nullable()->constrained('site_jobs')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
