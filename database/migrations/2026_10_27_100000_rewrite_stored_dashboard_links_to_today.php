<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Today moved from /dashboard to /today, with no redirect (owner, 27 Oct
 * 2026: "not redirecting but fully update links"). Links already stored
 * follow it: alerts' deep links, the database notifications' payloads (push
 * and in-app) and the links in Composer's conversations. Only the path
 * itself is rewritten (/dashboard, /dashboard?…, #…, /…), on its own or
 * after one of this app's hosts; nothing else that says "dashboard".
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('/dashboard', '/today');
    }

    public function down(): void
    {
        $this->rewrite('/today', '/dashboard');
    }

    private function rewrite(string $from, string $to): void
    {
        // This app's own hosts only: another site's /dashboard is its own.
        $hosts = array_values(array_filter(array_unique([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            config('app.app_domain'),
            'app.expadu.com',
        ])));
        $hostPattern = implode('|', array_map(fn (string $host) => preg_quote($host, '~'), $hosts));
        $path = '~(^|https?://(?:'.$hostPattern.')(?::\d+)?)'.preg_quote($from, '~').'(?=$|[?#/])~';
        $like = '%'.ltrim($from, '/').'%';

        DB::table('alerts')->where('deep_link', 'like', $like)
            ->chunkById(500, function ($rows) use ($path, $to): void {
                foreach ($rows as $row) {
                    $link = preg_replace($path, '$1'.$to, (string) $row->deep_link);
                    if ($link !== $row->deep_link) {
                        DB::table('alerts')->where('id', $row->id)->update(['deep_link' => $link]);
                    }
                }
            });

        // JSON held as text (notifications.data) or jsonb (composer_workspaces.state):
        // each string value is rewritten where it is a link to the old path.
        foreach ([['notifications', 'data', 'id'], ['composer_workspaces', 'state', 'id']] as [$table, $column, $key]) {
            DB::table($table)->whereRaw("{$column}::text like ?", [$like])
                ->chunkById(200, function ($rows) use ($table, $column, $key, $path, $to): void {
                    foreach ($rows as $row) {
                        $data = json_decode((string) $row->{$column}, true);
                        if (! is_array($data)) {
                            continue;
                        }
                        $changed = false;
                        array_walk_recursive($data, function (mixed &$value) use ($path, $to, &$changed): void {
                            if (is_string($value)) {
                                $next = preg_replace($path, '$1'.$to, $value);
                                if ($next !== $value) {
                                    $value = $next;
                                    $changed = true;
                                }
                            }
                        });
                        if ($changed) {
                            DB::table($table)->where($key, $row->{$key})->update([$column => json_encode($data, JSON_UNESCAPED_UNICODE)]);
                        }
                    }
                }, $key);
        }
    }
};
