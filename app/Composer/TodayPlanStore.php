<?php

namespace App\Composer;

use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Reads today's confirmed workspace plan, with compatibility for the older
 * cached Today pin. Dismissing this summary never cancels the underlying day.
 */
class TodayPlanStore
{
    private const MAX_TTL_HOURS = 72;

    private function key(User $user): string
    {
        return "composer:today:{$user->id}";
    }

    /**
     * Pin a composed plan (its stored array form) to Today.
     *
     * @param  array<string, mixed>  $plan  the cached composer:plan payload
     */
    public function save(User $user, array $plan, ?string $prompt): void
    {
        if (empty($plan['slots'])) {
            return;
        }
        $plan = app(PlaceIdentity::class)->normalizePlan($plan);

        $plan = app(PlaceIdentity::class)->normalizePlan($plan);
        app(WorkspaceStore::class)->saveConfirmed($user, $plan + ['prompt' => $prompt]);

        Cache::forget($this->key($user).':hidden');
        $start = $plan['constraints']['window_start'] ?? null;
        $until = is_string($start)
            ? CarbonImmutable::parse($start)->endOfDay()->addHours(6)
            : CarbonImmutable::now()->addDay();

        $ttl = max(
            3600,
            min(self::MAX_TTL_HOURS * 3600, (int) CarbonImmutable::now()->diffInSeconds($until, false)),
        );

        Cache::put($this->key($user), [
            'window_start' => $start,
            'constraints' => $plan['constraints'] ?? null,
            'origin' => $plan['origin'] ?? null,
            'prompt' => $prompt,
            'slots' => array_values($plan['slots']),
            'saved_at' => CarbonImmutable::now()->toIso8601String(),
        ], $ttl);
    }

    /**
     * The Today screen's view of the pinned plan, or null when none is set.
     *
     * @return array{date: ?string, weekday: string, prompt: ?string, slots: list<array<string, mixed>>}|null
     */
    public function get(User $user): ?array
    {
        $workspace = ComposerWorkspace::query()->where('user_id', $user->id)->first();
        $data = Cache::get($this->key($user));
        $today = CarbonImmutable::now('Europe/Berlin')->format('Y-m-d');
        $pinnedDate = is_array($data) && isset($data['window_start'])
            ? CarbonImmutable::parse($data['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d') : null;
        if ($workspace !== null) {
            $date = isset($workspace->state['plans'][$today]) ? $today : ($pinnedDate ?? $today);
            $plan = $workspace->state['plans'][$date] ?? null;
            if ($plan && ! empty($plan['slots'])) {
                if (Cache::get($this->key($user).':hidden') === hash('sha256', json_encode($plan))) {
                    return null;
                }

                $plan = app(PlanCatalogue::class)->project($plan);

                return ['date' => $date, 'weekday' => CarbonImmutable::parse($date)->isoFormat('dddd'), 'prompt' => $plan['prompt'] ?? null, 'slots' => $plan['slots']];
            }
            if (collect($workspace->state['versions'])->contains('date', $date)) {
                return null;
            }
        }
        if (! is_array($data) || empty($data['slots'])) {
            return null;
        }

        $data = app(PlaceIdentity::class)->normalizePlan($data);
        $data = app(PlanCatalogue::class)->project($data);
        $weekday = isset($data['window_start']) && is_string($data['window_start'])
            ? CarbonImmutable::parse($data['window_start'])->isoFormat('dddd')
            : 'day';

        return [
            'date' => $pinnedDate,
            'weekday' => $weekday,
            'prompt' => is_string($data['prompt'] ?? null) ? $data['prompt'] : null,
            'slots' => array_values($data['slots']),
        ];
    }

    /** Dismiss the Today summary without cancelling a saved day. */
    public function forget(User $user): void
    {
        $state = ComposerWorkspace::query()->where('user_id', $user->id)->first()?->state ?? ['plans' => []];
        $today = CarbonImmutable::now('Europe/Berlin')->format('Y-m-d');
        $cached = Cache::get($this->key($user));
        $date = isset($state['plans'][$today]) ? $today : (isset($cached['window_start']) ? CarbonImmutable::parse($cached['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d') : null);
        if ($date && isset($state['plans'][$date])) {
            Cache::put($this->key($user).':hidden', hash('sha256', json_encode($state['plans'][$date])), now()->addDays(4));
        }
        Cache::forget($this->key($user));
    }
}
