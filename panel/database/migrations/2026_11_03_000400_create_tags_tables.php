<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('color', 10)->default('blue');
            $table->timestamps();
        });
        Schema::create('site_tag', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['site_id', 'tag_id']);
        });
        Schema::create('customer_tag', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tag');
        Schema::dropIfExists('site_tag');
        Schema::dropIfExists('tags');
    }
};
