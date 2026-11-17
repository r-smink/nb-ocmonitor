<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verzonden/mislukte mails per dag (SMTP-module, client-plugin 0.17.0+), los van de bewaartermijn van de e-maillog op de site.
        Schema::create('site_mail_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unique(['site_id', 'date']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('report_mail_period', 10)->default('month')->after('report_sections');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_mail_stats');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('report_mail_period');
        });
    }
};
