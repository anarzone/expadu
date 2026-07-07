<?php

namespace App\Home;

use App\Enums\SpotCategory;

/**
 * The dynamic prompt chips under the search box — personalised WITHOUT an LLM.
 * Each chip is chosen from a template library gated by real signals from the
 * shared HomeContext (profile, arrival stage, weather, day/time, learned
 * IntentWeights) and carries either a canonical `prompt` (so tapping it hits
 * the deterministic pipeline with a known intent — no guessing) or a direct
 * `href`. Chips are planning/ask entry points; browse content (e.g. tonight's
 * events) is owned by the rails, so it isn't duplicated here. When a provider
 * key lands, the LLM can rephrase the labels behind this same shape; the
 * selection logic stays.
 */
class PromptSuggestions
{
    /**
     * @return list<array{label: string, icon: string, prompt?: string, href?: string}>
     */
    public function for(HomeContext $context): array
    {
        $profile = $context->profile;
        $now = $context->now;

        /** @var list<array{p: int, label: string, icon: string, prompt?: string, href?: string}> $candidates */
        $candidates = [];

        $daysSinceArrival = $profile->daysSinceArrival();
        if ($daysSinceArrival !== null && $daysSinceArrival <= 60) {
            $candidates[] = ['p' => 100, 'label' => 'Sort your Anmeldung', 'icon' => 'checklist', 'href' => '/bureaucracy'];
        }

        if (! empty($profile->attributes['child_born_at'])) {
            $candidates[] = ['p' => 70, 'label' => 'Something with the kids', 'icon' => 'kids', 'prompt' => 'something with the kids today'];
        }

        if ($context->rainExpected) {
            $candidates[] = ['p' => 60, 'label' => 'Rainy-day picks', 'icon' => 'umbrella', 'prompt' => 'indoor things to do today'];
        }

        if ($context->isWeekendWindow) {
            $day = $now->isSunday() ? 'Sunday' : 'Saturday';
            $candidates[] = ['p' => 55, 'label' => 'Plan my weekend', 'icon' => 'calendar', 'prompt' => "plan my {$day} afternoon"];
        }

        if ($context->isEvening) {
            $candidates[] = ['p' => 50, 'label' => 'Plans for tonight', 'icon' => 'moon', 'prompt' => 'something to do tonight'];
        }

        $topCategory = $this->topCategory($context->intentWeights);
        if ($topCategory !== null) {
            // Humanise the raw signal slug ("dog_park") for both the chip label
            // and the prompt — "More dog_park" read as a bug, and "dog_park
            // today" didn't parse (the parser expects "dog park", with a space).
            $enum = SpotCategory::tryFrom($topCategory);
            $label = $enum ? mb_strtolower($enum->label()) : str_replace('_', ' ', $topCategory);
            $candidates[] = ['p' => 45, 'label' => 'More '.$label, 'icon' => 'star', 'prompt' => "{$label} today"];
        }

        // Always-available fallbacks so there are never fewer than a few chips.
        $candidates[] = ['p' => 10, 'label' => 'Free afternoon', 'icon' => 'sun', 'prompt' => 'free afternoon'];
        $candidates[] = ['p' => 8, 'label' => 'Meet people this week', 'icon' => 'users', 'prompt' => 'meet people this week'];

        usort($candidates, fn ($a, $b) => $b['p'] <=> $a['p']);

        return array_map(
            fn (array $c) => array_filter([
                'label' => $c['label'],
                'icon' => $c['icon'],
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
}
