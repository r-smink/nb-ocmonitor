<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email');
            $table->string('report_cc')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            // Onderhoudscontract: aantal beurten per jaar (0 = geen contract).
            $table->unsignedTinyInteger('visits_per_year')->default(0);
            $table->date('contract_start')->nullable();
            $table->boolean('report_enabled')->default(false);
            $table->json('report_sections')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('client_name')->constrained()->nullOnDelete();
        });

        Schema::create('maintenance_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('report_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'due_on']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Logo, achtergronden en fonts in de database (base64): geen extra volume nodig en ze zitten in de backup.
        Schema::create('report_assets', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->unique();
            $table->string('filename');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->timestamps();
        });

        // Bestaande klantnamen bij sites omzetten naar klanten.
        $now = now();
        foreach (DB::table('sites')->whereNotNull('client_name')->where('client_name', '!=', '')->distinct()->pluck('client_name') as $name) {
            $id = DB::table('customers')->insertGetId(['name' => $name, 'email' => '', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('sites')->where('client_name', $name)->update(['customer_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_assets');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('maintenance_runs');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customers');
    }
};
