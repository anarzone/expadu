<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Travel's ★ favourite stops, per account (plan call #10). A stop is kept
     * by its KVB station name; its place is read from KVB when shown.
     */
    public function up(): void
    {
        Schema::create('favourite_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourite_stops');
    }
};
