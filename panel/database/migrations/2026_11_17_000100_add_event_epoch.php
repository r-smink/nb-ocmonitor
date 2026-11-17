<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Opnieuw koppelen (bijv. na een verhuizing naar een nieuwe installatie) begint een nieuwe reeks
    // event-nummers. De epoch houdt de reeksen uit elkaar, zodat de oude events bewaard blijven.
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('event_epoch')->default(0)->after('last_event_seq');
        });
        Schema::table('site_events', function (Blueprint $table) {
            $table->unsignedInteger('epoch')->default(0)->after('site_id');
        });
        Schema::table('site_events', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'seq']);
            $table->unique(['site_id', 'epoch', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::table('site_events', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'epoch', 'seq']);
            $table->unique(['site_id', 'seq']);
            $table->dropColumn('epoch');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('event_epoch');
        });
    }
};
