<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Paperwork moved from /bureaucracy to /paperwork, its API from /bureaucracy/v2 to
 * /paperwork/v2, with no redirect (owner, 27 Oct 2026). Links the app stored point to
 * the new address: alerts' deep links, the database notifications' payloads (Paperwork
 * reminders) and the links in Composer's conversations (cards' "Open Paperwork",
 * sources, hand-offs). Page-view analytics (user_events) keep the address as visited.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('/bureaucracy', '/paperwork');
    }

    public function down(): void
    {
        $this->rewrite('/paperwork', '/bureaucracy');
    }

    private function rewrite(string $from, string $to): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A link is the whole address or its start: "/bureaucracy", "?…", "#…" or "/v2/…" after it.
        DB::update('UPDATE alerts SET deep_link = regexp_replace(deep_link, ?, ?) WHERE deep_link ~ ?',
            ['^'.$from.'(?=$|[/?#])', $to, '^'.$from.'($|[/?#])']);
        // A notification's payload is JSON text, its slashes escaped ("\/bureaucracy") or plain.
        DB::update('UPDATE notifications SET data = regexp_replace(data, ?, ?, \'g\') WHERE data ~ ?',
            ['"(\\\\?)'.$from.'(?=["?#]|\\\\?/)', '"\\1'.$to, '"\\\\?'.$from.'(["?#]|\\\\?/)']);
        // In a conversation every stored link is a JSON string of its own: "/bureaucracy…".
        DB::update('UPDATE composer_workspaces SET state = regexp_replace(state::text, ?, ?, \'g\')::jsonb WHERE state::text ~ ?',
            ['"'.$from.'(?=["/?#])', '"'.$to, '"'.$from.'["/?#]']);
    }
};
