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
        Schema::create('city_news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('source'); // koeln.de, kvb, stadt-koeln
            $table->string('source_url')->unique();
            $table->string('category')->default('general'); // transit, regulation, event, culture, weather
            $table->string('relevance')->default('all'); // all, expat, transit, bureaucracy
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'published_at']);
            $table->index('relevance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_news');
    }
};
