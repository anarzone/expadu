<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The lists a person was shown, for measuring whether an order helps
        // (ListImpressions): the order the rules gave, the order shown and
        // the policy that put it so. Only for people who opted in, keyed by
        // the ledger's pseudonym; item keys only. The same list again on the
        // same day counts on its row. Pruned with the ledger.
        Schema::create('list_impressions', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64);
            $table->string('surface', 16);
            $table->date('day');
            $table->string('policy', 16);
            $table->string('version', 32);
            $table->char('list_hash', 64);
            $table->jsonb('baseline');
            $table->jsonb('shown');
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->unique(['subject_key', 'surface', 'day', 'list_hash']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_impressions');
    }
};
