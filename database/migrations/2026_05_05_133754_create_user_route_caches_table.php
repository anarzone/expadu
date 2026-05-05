<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_route_caches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_place_id')->constrained('user_places')->cascadeOnDelete();
            $table->foreignId('to_place_id')->constrained('user_places')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->jsonb('lines');
            $table->text('polyline')->nullable();
            $table->jsonb('bbox')->nullable();
            $table->jsonb('typical_window');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'from_place_id', 'to_place_id', 'mode'], 'user_route_caches_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_route_caches');
    }
};
