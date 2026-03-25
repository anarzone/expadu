<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('wifi_speed')->nullable();
            $table->string('noise_level')->nullable();
            $table->integer('time_limit_mins')->nullable();
            $table->json('opening_hours')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE spots ADD COLUMN location geography(POINT, 4326)');
        DB::statement('CREATE INDEX spots_location_idx ON spots USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('spots');
    }
};
