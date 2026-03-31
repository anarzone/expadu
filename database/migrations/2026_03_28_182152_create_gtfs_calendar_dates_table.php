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
        Schema::create('gtfs_calendar_dates', function (Blueprint $table) {
            $table->id();
            $table->string('service_id');
            $table->date('date');
            $table->integer('exception_type'); // 1 = added, 2 = removed

            $table->index(['date', 'exception_type']);
            $table->index('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gtfs_calendar_dates');
    }
};
