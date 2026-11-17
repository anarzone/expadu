<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A week of Today's ideas in totals (WeeklyMeasure): nothing per person.
     */
    public function up(): void
    {
        Schema::create('personalisation_weeks', function (Blueprint $table) {
            $table->id();
            $table->date('week')->unique();
            $table->jsonb('report');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalisation_weeks');
    }
};
