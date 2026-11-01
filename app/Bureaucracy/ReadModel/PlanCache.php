<?php

namespace App\Bureaucracy\ReadModel;

use App\Models\BureaucracyPerson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A built plan, kept for exactly what it was built from (speed plan, 26 Oct 2026: pages
 * load whole). Its key is the actor, the person and the jurisdiction with everything a
 * plan reads: plan_data_version, which a trigger on every bureaucracy table, the
 * catalogue's tasks, users and user_tasks bumps in the writing transaction (and its xmin,
 * so a rolled-back write never names a later state); today; the bureaucracy, services
 * and app config; and the code it is built by. Any write reads afresh, a plan is never
 * kept past its own next_reassessment_at, and a failed build (no access, stopped
 * processing) throws before anything is kept. The value is signed with the app key, so
 * an entry the app didn't write is a miss.
 */
final class PlanCache
{
    /** Where the code that builds a plan lives: a newer file anywhere here reads afresh. */
    private const Code = ['app/Bureaucracy', 'app/Privacy', 'app/Models', 'app/Home', 'config'];

    /** @param  int  $outside  the transaction depth that counts as none (tests run inside one) */
    public function __construct(private int $outside = 0) {}

    /** @param  Closure(): array  $build */
    public function remember(User $actor, BureaucracyPerson $person, string $jurisdiction, CarbonImmutable $today, Closure $build): array
    {
        // Inside another transaction a plan can see writes that may never commit: build it, don't keep it.
        $version = DB::transactionLevel() <= $this->outside ? $this->version() : null;
        if ($version === null) {
            return $build();
        }
        $key = 'bureaucracy.plan.'.hash('sha256', json_encode([
            'actor' => $actor->id, 'person' => $person->id, 'jurisdiction' => $jurisdiction, 'data' => $version,
            'today' => $today->toDateString(), 'config' => $this->config(), 'code' => $this->code(),
        ], JSON_THROW_ON_ERROR));

        $plan = $this->read($key);
        if ($plan !== null && $this->current($plan)) {
            return $plan;
        }
        $plan = $build();
        $this->write($key, $plan);

        return $plan;
    }

    /** @return array{0: int, 1: string}|null the data version and the transaction that wrote it */
    private function version(): ?array
    {
        try {
            $row = DB::selectOne('SELECT version, xmin::text AS writer FROM plan_data_version WHERE id = 1');
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [(int) $row->version, (string) $row->writer];
    }

    private function config(): string
    {
        $config = array_filter(config()->all(), fn (string $name): bool => str_starts_with($name, 'bureaucracy_'), ARRAY_FILTER_USE_KEY);
        ksort($config);

        return hash('sha256', serialize([$config, config('services'), config('app')]));
    }

    private function code(): int
    {
        $newest = 0;
        foreach (self::Code as $path) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($path), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $newest = max($newest, $file->getMTime());
            }
        }

        return $newest;
    }

    /** A plan still within its own window: it changes by itself at next_reassessment_at. */
    private function current(array $plan): bool
    {
        $until = $plan['next_reassessment_at'] ?? null;

        return is_string($until) && CarbonImmutable::parse($until)->isFuture();
    }

    private function read(string $key): ?array
    {
        try {
            $stored = Cache::get($key);
        } catch (Throwable) {
            return null;
        }
        [$signature, $json] = is_string($stored) && str_contains($stored, '|') ? explode('|', $stored, 2) : ['', ''];
        if (! hash_equals($this->sign($key, $json), $signature)) {
            return null;
        }
        $plan = json_decode($json, true);

        return is_array($plan) ? $plan : null;
    }

    /** A scalar, as cached objects break; kept no longer than the plan's own window. */
    private function write(string $key, array $plan): void
    {
        $until = $plan['next_reassessment_at'] ?? null;
        if (! is_string($until) || ! CarbonImmutable::parse($until)->isFuture()) {
            return;
        }
        try {
            $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            Cache::put($key, $this->sign($key, $json).'|'.$json, CarbonImmutable::parse($until));
        } catch (Throwable) {
            // Unkept, the next read builds it again.
        }
    }

    private function sign(string $key, string $json): string
    {
        return hash_hmac('sha256', $key."\n".$json, (string) config('app.key'));
    }
}
