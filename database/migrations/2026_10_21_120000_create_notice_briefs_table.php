<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One plain-language brief per KVB notice text and language. Keyed
        // by the text, so a notice copied for several lines shares a brief
        // and an edited notice gets a new one.
        Schema::create('notice_briefs', function (Blueprint $table) {
            $table->id();
            $table->string('input_hash', 64);
            $table->string('locale', 5)->default('en');
            $table->string('headline', 200);
            $table->text('body');
            $table->string('impact', 24);
            $table->string('model', 64);
            $table->string('prompt_version', 16);
            $table->timestamps();

            $table->unique(['input_hash', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_briefs');
    }
};
