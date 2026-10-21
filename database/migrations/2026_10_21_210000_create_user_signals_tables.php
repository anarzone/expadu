<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The behaviour ledger: one row per action on a catalogue item, for
        // people who opted in to personalisation. Keyed by a pseudonym, never
        // the account id; no coordinates or typed text. Pruned after 90 days.
        // The item's category, Veedel and price band are kept as they were
        // when the person acted, so aggregates survive a place being merged
        // or removed and need no join.
        Schema::create('user_signals', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64);
            $table->string('action', 32);
            $table->string('surface', 16);
            $table->string('item_type', 8);
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('veedel', 100)->nullable();
            $table->string('price_band', 8)->nullable();
            $table->jsonb('context');
            $table->timestampTz('created_at');

            $table->index(['subject_key', 'item_type', 'item_id']);
            $table->index('created_at');
        });

        // Decayed affinities per person, rebuilt nightly by signals:aggregate.
        Schema::create('user_affinities', function (Blueprint $table) {
            $table->char('subject_key', 64);
            $table->string('dimension', 16);
            $table->string('key', 100);
            $table->string('context', 32);
            $table->double('score');
            $table->unsignedInteger('signals_count');
            $table->timestampTz('updated_at');

            $table->primary(['subject_key', 'dimension', 'key', 'context']);
        });

        // Per-place negatives and hides. A hide is written live when the
        // person acts and recomputed nightly with the decayed score.
        Schema::create('user_place_negatives', function (Blueprint $table) {
            $table->char('subject_key', 64);
            $table->string('item_type', 8);
            $table->unsignedBigInteger('item_id');
            $table->string('context', 32);
            $table->double('score');
            $table->timestampTz('hidden_until')->nullable();
            $table->unsignedInteger('signals_count');
            $table->timestampTz('updated_at');

            $table->primary(['subject_key', 'item_type', 'item_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_place_negatives');
        Schema::dropIfExists('user_affinities');
        Schema::dropIfExists('user_signals');
    }
};
