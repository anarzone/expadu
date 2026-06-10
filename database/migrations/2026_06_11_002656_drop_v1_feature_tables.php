<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * v2 pivot: drops the tables behind killed v1 features — commute routines,
 * spot check-ins (visit detection), and the chat/social layer
 * (conversations, messages, language partners).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('routines');
        Schema::dropIfExists('spot_checkins');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('language_partners');
    }

    public function down(): void
    {
        // Intentionally irreversible — restore from the original create
        // migrations in git history if these features ever return.
    }
};
