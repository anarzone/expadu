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
        Schema::table('events', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('category');
            $table->boolean('is_expat_relevant')->default(false)->after('tags');
            $table->string('source')->nullable()->after('is_expat_relevant');
            $table->string('source_url')->nullable()->after('source');
            $table->float('quality_score')->default(0.0)->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['tags', 'is_expat_relevant', 'source', 'source_url', 'quality_score']);
        });
    }
};
