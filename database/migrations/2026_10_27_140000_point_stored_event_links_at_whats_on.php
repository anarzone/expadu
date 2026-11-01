<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * There is no Events page any more (owner, 27 Oct 2026): a day's listings open in What's on, the
 * panel over Today (/today?whats_on=1), with no redirect. Links already stored point there:
 * alerts' deep links, notifications' data and Composer's saved answers. /events/saved keeps its
 * Saved filter, /events/{id} its listing, /events?… its day and filters. Only the path is
 * rewritten (also as JSON's `\/events`), never /api/events. Logs (user_events, composer_runs) stay.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: 'text'|'jsonb'}> */
    private const COLUMNS = [
        ['alerts', 'deep_link', 'text'],
        ['notifications', 'data', 'text'],
        ['composer_workspaces', 'state', 'jsonb'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // The path starts the text or follows something that can't be part of another path or
        // escape (a quote, a space): /api/events, a site's /events and JSON's api\/events never match.
        $at = '(^|[^/\\\\A-Za-z0-9_.-])(\\\\?/)events';
        $rewrites = [
            [$at.'(\\\\?/)saved(?=[?#"\\\\]|$)', '\\1\\2today?whats_on=1&saved=1'],
            [$at.'(\\\\?/)([0-9]+)(?=[?#"\\\\]|$)', '\\1\\2today?whats_on=1&event=\\4'],
            [$at.'\\?', '\\1\\2today?whats_on=1&'],
            [$at.'(?=[#"\\\\]|$)', '\\1\\2today?whats_on=1'],
        ];

        foreach (self::COLUMNS as [$table, $column, $type]) {
            $cast = $type === 'jsonb' ? '::jsonb' : '';
            foreach ($rewrites as [$pattern, $replacement]) {
                DB::update(
                    "update {$table} set {$column} = regexp_replace({$column}::text, ?, ?, 'g'){$cast} where {$column}::text ~ ?",
                    [$pattern, $replacement, $pattern],
                );
            }
        }
    }

    public function down(): void
    {
        // One way: the Events page is gone, so there is nothing to point back to.
    }
};
