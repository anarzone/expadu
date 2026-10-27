<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Travel moved from /timetable to /travel with no redirect (owner, 27 Oct
     * 2026: "not redirecting but fully update links"). Links stored before the
     * move point at the old address: alerts' deep links, the database
     * notifications' payloads (leave reminders, delays, disruptions, get off)
     * and Composer's kept conversations (their hand-offs). Each is re-pointed.
     */
    public function up(): void
    {
        DB::table('alerts')
            ->where(fn ($q) => $q->where('deep_link', '/timetable')
                ->orWhere('deep_link', 'like', '/timetable?%')
                ->orWhere('deep_link', 'like', '/timetable/%'))
            ->update(['deep_link' => DB::raw("'/travel' || substr(deep_link, 11)")]);

        // JSON, written with escaped slashes ("\/timetable") or plain ones.
        DB::update(
            'update notifications set data = replace(replace(data, ?, ?), ?, ?) where data like ?',
            ['"\/timetable', '"\/travel', '"/timetable', '"/travel', '%timetable%'],
        );

        DB::update(
            'update composer_workspaces set state = replace(state::text, ?, ?)::jsonb where state::text like ?',
            ['"/timetable', '"/travel', '%"/timetable%'],
        );
    }

    /**
     * One way: /timetable no longer exists, so a link pointed back there
     * would lead nowhere.
     */
    public function down(): void
    {
        // No-op.
    }
};
