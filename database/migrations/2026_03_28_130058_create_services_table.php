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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category'); // doctor, dental, mental, bank, tax, legal, insure, pharmacy
            $table->string('subcategory')->nullable();
            $table->string('emoji')->nullable();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->float('lat')->nullable();
            $table->float('lng')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->json('languages')->nullable();
            $table->json('insurance_accepted')->nullable();
            $table->json('opening_hours')->nullable();
            $table->string('source')->default('manual'); // osm, manual, community
            $table->string('osm_id')->nullable()->unique();
            $table->boolean('is_verified')->default(false);
            $table->boolean('accepts_new')->default(true);
            $table->float('rating')->nullable();
            $table->integer('review_count')->default(0);
            $table->float('quality_score')->default(0.0);
            $table->timestamps();

            $table->index('category');
        });

        DB::statement('ALTER TABLE services ADD COLUMN location geography(POINT, 4326)');
        DB::statement('CREATE INDEX services_location_idx ON services USING GIST (location)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
