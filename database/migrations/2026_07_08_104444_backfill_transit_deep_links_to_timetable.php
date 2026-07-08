<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The /transit page was removed in the v2 pivot — departures now live at
     * /timetable. Alerts created before the notification fix still deep-link to
     * the dead /transit route, so tapping one does an Inertia visit that 404s
     * (a non-Inertia response), surfacing a misleading "ad blocker" toast that
     * sticks until reload. Re-point every stored alert at the live route.
     */
    public function up(): void
    {
        DB::table('alerts')
            ->where('deep_link', '/transit')
            ->update(['deep_link' => '/timetable']);
    }

    /**
     * Intentionally irreversible: reversing would restore a dead route and
     * re-introduce the bug. This is a one-way data forward-fix.
     */
    public function down(): void
    {
        // No-op.
    }
};
