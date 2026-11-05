<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eén onderhoudsbeurt verplaatsen zonder het contract (en de volgende datums) te veranderen.
        Schema::table('maintenance_runs', function (Blueprint $table) {
            $table->date('planned_on')->nullable()->after('due_on');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_runs', function (Blueprint $table) {
            $table->dropColumn('planned_on');
        });
    }
};
