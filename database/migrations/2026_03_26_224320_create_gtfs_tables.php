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
        Schema::create('gtfs_stops', function (Blueprint $table) {
            $table->string('stop_id')->primary();
            $table->string('stop_name');
            $table->decimal('stop_lat', 10, 7);
            $table->decimal('stop_lng', 10, 7);
            $table->unsignedTinyInteger('location_type')->default(0);
            $table->string('parent_station')->nullable();

            $table->index('parent_station');
            $table->index(['stop_lat', 'stop_lng']);
        });

        Schema::create('gtfs_routes', function (Blueprint $table) {
            $table->string('route_id')->primary();
            $table->string('route_short_name')->nullable();
            $table->string('route_long_name')->nullable();
            $table->unsignedSmallInteger('route_type');
            $table->string('route_color', 6)->nullable();
        });

        Schema::create('gtfs_trips', function (Blueprint $table) {
            $table->string('trip_id')->primary();
            $table->string('route_id');
            $table->string('service_id');
            $table->string('trip_headsign')->nullable();
            $table->unsignedTinyInteger('direction_id')->nullable();

            $table->index('route_id');
            $table->index('service_id');
        });

        Schema::create('gtfs_stop_times', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id');
            $table->string('stop_id');
            $table->string('arrival_time', 8);
            $table->string('departure_time', 8);
            $table->unsignedSmallInteger('stop_sequence');

            $table->index('trip_id');
            $table->index('stop_id');
            $table->index(['stop_id', 'departure_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gtfs_stop_times');
        Schema::dropIfExists('gtfs_trips');
        Schema::dropIfExists('gtfs_routes');
        Schema::dropIfExists('gtfs_stops');
    }
};
