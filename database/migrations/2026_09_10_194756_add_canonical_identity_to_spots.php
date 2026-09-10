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
            $table->foreignId('canonical_spot_id')->nullable()->constrained('spots')->restrictOnDelete();
            $table->index('canonical_spot_id');
        });

        Schema::create('place_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alias_spot_id')->unique()->constrained('spots')->restrictOnDelete();
            $table->foreignId('canonical_spot_id')->constrained('spots')->restrictOnDelete();
            $table->char('fingerprint', 64);
            $table->text('evidence');
            $table->jsonb('snapshot');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('place_reconciliations');
        Schema::table('spots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_spot_id');
        });
    }
};
