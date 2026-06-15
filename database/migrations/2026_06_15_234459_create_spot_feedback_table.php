<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('spot_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained()->cascadeOnDelete();
            // The user's standing relationship to this place. One row per
            // (user, spot): more_like_this | saved | been | not_interested.
            $table->string('state');
            // Only set when state = been: up | down (post-visit quality).
            $table->string('rating')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'spot_id']);
            // Fast per-user lookups for discovery suppression + saved/been lists.
            $table->index(['user_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_feedback');
    }
};
