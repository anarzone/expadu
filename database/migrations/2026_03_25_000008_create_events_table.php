<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('emoji')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location_name')->nullable();
            $table->string('address')->nullable();
            $table->integer('max_attendees')->nullable();
            $table->boolean('is_free')->default(true);
            $table->decimal('price', 8, 2)->nullable();
            $table->foreignId('organiser_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE events ADD COLUMN location geography(POINT, 4326)');
        DB::statement('CREATE INDEX events_location_idx ON events USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
