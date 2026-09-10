<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->foreignId('destination_spot_id')->nullable()->constrained('spots')->restrictOnDelete();
            $table->foreignId('destination_reviewed_parent_id')->nullable()->constrained('spots')->restrictOnDelete();
            $table->text('destination_grouping_evidence')->nullable();
            $table->timestampTz('destination_reviewed_at')->nullable();
        });
        Schema::create('place_destination_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_id')->constrained('spots')->restrictOnDelete();
            $table->foreignId('destination_spot_id')->nullable()->constrained('spots')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->text('evidence');
            $table->jsonb('snapshot');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_destination_reviews');
        Schema::table('spots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_spot_id');
            $table->dropConstrainedForeignId('destination_reviewed_parent_id');
            $table->dropColumn(['destination_grouping_evidence', 'destination_reviewed_at']);
        });
    }
};
