<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eén rij per incident: een issue (site + sleutel) van open tot opgelost.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('level', 8);
            $table->string('category', 30);
            $table->string('text', 500);
            $table->timestamp('opened_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // false = al open bij de eerste meting (bestaande situatie): niet melden, wel in de geschiedenis.
            $table->boolean('notify')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'key', 'resolved_at']);
            $table->index('resolved_at');
        });

        // Wat er naar wie is verstuurd, zodat elk incident één keer per ontvanger wordt gemeld.
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->string('recipient', 40); // user:<id> of channel:<slack|teams|webhook>
            $table->string('kind', 10);      // open | escalate | reminder | resolve
            $table->string('level', 8);
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();
            $table->index(['alert_id', 'recipient']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('alert_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('alert_prefs'));
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alerts');
    }
};
