<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Slot monitoring never worked: the booking system is an
        // unscrapeable SPA, so checkSlots() always returned check_online
        // and a monitor could never fire. The honest model is link-out.
        Schema::dropIfExists('slot_monitors');
    }

    public function down(): void
    {
        // Intentionally irreversible — the feature is removed.
    }
};
