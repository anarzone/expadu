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
        Schema::table('spots', function (Blueprint $table) {
            $table->string('cuisine')->nullable()->after('category');
            $table->string('price_range')->nullable()->after('cuisine');
            $table->json('tags')->nullable()->after('price_range');
            $table->string('source')->nullable()->after('rating');
            $table->string('source_id')->nullable()->after('source');
            $table->string('phone')->nullable()->after('source_id');
            $table->string('website')->nullable()->after('phone');

            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropUnique(['source', 'source_id']);
            $table->dropColumn(['cuisine', 'price_range', 'tags', 'source', 'source_id', 'phone', 'website']);
        });
    }
};
