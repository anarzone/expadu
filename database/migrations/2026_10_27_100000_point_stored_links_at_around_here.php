<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Around here moved from /explore to /around-here with no redirect (owner,
 * 27 Oct 2026: "not redirecting but fully update links"). Links already
 * stored point at the new path: alerts' deep links, notifications' data
 * and Composer's saved answers (a place card's href). Only the path is
 * rewritten (`/explore` before `?`, `#`, `/`, a quote or the end, also as
 * JSON's `\/explore`), never a host such as explore.overturemaps.org. Logs
 * of what happened (user_events, composer_runs) stay as they were.
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
        $this->swap('explore', 'around-here');
    }

    public function down(): void
    {
        $this->swap('around-here', 'explore');
    }

    private function swap(string $from, string $to): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A path: a slash (or JSON's escaped one) not right after another
        // slash, the name, then the end of the segment. A host such as
        // explore.overturemaps.org goes on with a dot, so it never matches.
        $pattern = '(^|[^/])(\\\\?/)'.$from.'(?=[?#/"\\\\]|$)';

        foreach (self::COLUMNS as [$table, $column, $type]) {
            $cast = $type === 'jsonb' ? '::jsonb' : '';
            DB::update(
                "update {$table} set {$column} = regexp_replace({$column}::text, ?, ?, 'g'){$cast} where {$column}::text ~ ?",
                [$pattern, '\\1\\2'.$to, $pattern],
            );
        }
    }
};
