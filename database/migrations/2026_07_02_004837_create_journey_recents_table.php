<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recently used journey endpoints (Google-Maps-style recents): every
     * planned journey records its destination — and its origin when the user
     * explicitly chose one — so the search fields can offer them back as
     * defaults before the user types.
     */
    public function up(): void
    {
        Schema::create('journey_recents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // origin | destination
            $table->string('name', 200);
            $table->string('area', 200)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('times_used')->default(1);
            $table->timestamp('last_used_at');
            $table->timestamps();

            $table->unique(['user_id', 'role', 'name']);
            $table->index(['user_id', 'role', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_recents');
    }
};
