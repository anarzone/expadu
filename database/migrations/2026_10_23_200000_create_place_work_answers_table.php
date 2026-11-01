<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| "Good for laptop work?" — one answer per person per place, from people who
| marked the place Been there or Saved (docs/places/design/laptop-answers.md).
| Personal data: deleted with the account (cascade) and pruned after 18 months;
| only anonymous totals (from 3 people) leave this table, as a place fact.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_work_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained()->cascadeOnDelete();
            $table->string('laptop', 3);               // yes | no
            $table->string('wifi', 3)->nullable();     // yes | no
            $table->string('sockets', 3)->nullable();  // yes | no
            $table->string('quiet', 3)->nullable();    // yes | no
            $table->timestampTz('answered_at');
            $table->timestamps();

            $table->unique(['user_id', 'spot_id']);
            $table->index(['spot_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_work_answers');
    }
};
