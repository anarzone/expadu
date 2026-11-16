<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Composer remembers about a person (Memory): the lasting facts they told it ("Vegetarian",
 * "Has a 3-year-old"), or added on the "What Expadu knows" screen. Kept under the personalisation
 * pseudonym like the rest of what personalisation keeps, and erased with it (SignalRecorder::forget).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_facts', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64)->index();
            $table->string('text', 160);
            // told: said to Composer; added: typed on the screen.
            $table->string('source', 16);
            // To the millisecond: two facts kept in one round keep their order, and an undone forget its place.
            $table->timestampsTz(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_facts');
    }
};
