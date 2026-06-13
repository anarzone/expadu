<?php

namespace App\Home;

use App\Composer\IntentWeights;
use App\Models\Event;
use App\Models\User;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;

/**
 * The dynamic prompt chips under the search box — personalised WITHOUT an
 * LLM. Each chip is chosen from a template library gated by real signals
 * (profile, arrival stage, weather, day/time, learned IntentWeights,
 * deadlines, events) and carries either a canonical `prompt` (so tapping it
 * hits the deterministic pipeline with a known intent — no guessing) or a
 * direct `href`. When a provider key lands, the LLM can rephrase the labels
 * behind this same shape; the selection logic stays.
 */
class PromptSuggestions
{
    public function __construct(
        private readonly IntentWeights $intents,
        private readonly ProfileEngine $profiles,
        private readonly WeatherService $weather,
    ) {}

    /**
     * @return list<array{label: string, prompt?: string, href?: string}>
     */
    public function for(User $user): array
    {
        $profile = $this->profiles->build($user);
        $now = CarbonImmutable::now('Europe/Berlin');
        $weights = $this->intents->for($user);

        /** @var list<array{p: int, label: string, prompt?: string, href?: string}> $candidates */
        $candidates = [];

        $daysSinceArrival = $profile->daysSinceArrival();
        if ($daysSinceArrival !== null && $daysSinceArrival <= 60) {
            $candidates[] = ['p' => 100, 'label' => '📋 Sort your Anmeldung', 'href' => '/bureaucracy'];
        }

        if (! empty($profile->attributes['child_born'])) {
            $candidates[] = ['p' => 70, 'label' => '🧸 Something with the kids', 'prompt' => 'something with the kids today'];
        }

        if ($this->rainExpected()) {
            $candidates[] = ['p' => 60, 'label' => '☔ Rainy-day picks nearby', 'prompt' => 'indoor things to do near me today'];
        }

        $isWeekendWindow = ($now->isFriday() && $now->hour >= 15) || $now->isSaturday() || $now->isSunday();
        if ($isWeekendWindow) {
            $day = $now->isSunday() ? 'Sunday' : 'Saturday';
            $candidates[] = ['p' => 55, 'label' => '🗓️ Plan my weekend', 'prompt' => "plan my {$day} afternoon"];
        }

        if ($now->hour >= 17) {
            $candidates[] = ['p' => 50, 'label' => '🌆 Plans for tonight', 'prompt' => 'something to do tonight'];
        }

        $topCategory = $this->topCategory($weights);
        if ($topCategory !== null) {
            $candidates[] = ['p' => 45, 'label' => '⭐ More '.$topCategory.' near you', 'prompt' => "{$topCategory} near me today"];
        }

        if ($this->hasEventsTonight()) {
            $candidates[] = ['p' => 40, 'label' => "🎭 What's on tonight", 'href' => '/events'];
        }

        // Always-available fallbacks so there are never fewer than a few chips.
        $candidates[] = ['p' => 10, 'label' => '🌳 Free afternoon nearby', 'prompt' => 'free afternoon nearby'];
        $candidates[] = ['p' => 8, 'label' => '👋 Meet people this week', 'prompt' => 'meet people this week'];

        usort($candidates, fn ($a, $b) => $b['p'] <=> $a['p']);

        return array_map(
            fn (array $c) => array_filter([
                'label' => $c['label'],
                'prompt' => $c['prompt'] ?? null,
                'href' => $c['href'] ?? null,
            ], fn ($v) => $v !== null),
            array_slice($candidates, 0, 4),
        );
    }

    /**
     * Highest-weighted category from the learned signals, if any.
     *
     * @param  array<string, float>  $weights  "category|veedel" → weight
     */
    private function topCategory(array $weights): ?string
    {
        if ($weights === []) {
            return null;
        }

        arsort($weights);
        $topKey = array_key_first($weights);

        $category = explode('|', (string) $topKey)[0];

        return $category !== '' ? $category : null;
    }

    private function hasEventsTonight(): bool
    {
        return Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->exists();
    }

    private function rainExpected(): bool
    {
        try {
            return (bool) ($this->weather->getForecast()['rain_starts'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
