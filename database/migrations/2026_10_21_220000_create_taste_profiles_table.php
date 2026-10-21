<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One nightly taste profile per person who opted in to
        // personalisation, under the ledger's pseudonym (never the account
        // id). `facts` is what code computed and the model was given; the
        // sentences only restate it. The person's own edits win over the
        // model's sentences, and hidden tags stay hidden across rewrites.
        Schema::create('taste_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64)->unique();
            $table->jsonb('facts');
            $table->char('facts_hash', 64);
            $table->jsonb('sentences');
            $table->jsonb('tags');
            $table->jsonb('edited_sentences')->nullable();
            $table->jsonb('hidden_tags')->default('[]');
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taste_profiles');
    }
};
