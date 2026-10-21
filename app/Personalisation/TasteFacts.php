<?php

namespace App\Personalisation;

use App\Enums\EventCategory;
use App\Enums\SpotCategory;
use App\Models\User;

/**
 * The facts a taste profile may state, computed in code from the person's
 * decayed affinities: liked and avoided categories and Veedels bucketed as
 * strong, some or avoid, preferred dayparts and a weekday/weekend lean. No
 * ids, names of places, scores or raw signals: this is all the model sees.
 */
final class TasteFacts
{
    public const DAYPARTS = ['morning', 'midday', 'afternoon', 'evening', 'night'];

    public function __construct(private Affinities $affinities) {}

    /**
     * Null without the opt-in or when the ledger is too thin to say anything.
     *
     * @return array{categories: list<array{name: string, level: string}>, veedels: list<array{name: string, level: string}>, dayparts: list<string>, week: string|null, signals: int}|null
     */
    public function for(User $user): ?array
    {
        $snapshot = $this->affinities->for($user);

        return $snapshot->isEmpty() ? null : self::from($snapshot);
    }

    /** @return array{categories: list<array{name: string, level: string}>, veedels: list<array{name: string, level: string}>, dayparts: list<string>, week: string|null, signals: int}|null */
    public static function from(AffinitySnapshot $snapshot): ?array
    {
        $rules = (array) config('personalisation.taste_profile');
        $categories = [];
        foreach ($snapshot->affinities['category']['all'] ?? [] as $key => $row) {
            if (($label = self::categoryLabel((string) $key)) !== null) {
                $categories[$label] = ($categories[$label] ?? 0.0) + $row['score'];
            }
        }
        $signals = array_sum(array_column($snapshot->affinities['category']['all'] ?? [], 'signals'));

        $known = array_flip(self::veedelNames());
        $veedels = array_filter(
            array_map(fn (array $row) => $row['score'], $snapshot->affinities['veedel']['all'] ?? []),
            fn (string|int $name) => isset($known[(string) $name]),
            ARRAY_FILTER_USE_KEY,
        );

        $liked = self::bucket($categories, $rules, (int) ($rules['categories'] ?? 5));
        if ($signals < (int) ($rules['min_signals'] ?? 5) || $liked['liked'] === []) {
            return null;
        }
        $places = self::bucket($veedels, $rules, (int) ($rules['veedels'] ?? 3));
        $avoid = (int) ($rules['avoid'] ?? 3);

        return [
            'categories' => [...$liked['liked'], ...array_slice($liked['avoided'], 0, $avoid)],
            'veedels' => [...$places['liked'], ...array_slice($places['avoided'], 0, $avoid)],
            'dayparts' => self::dayparts($snapshot, $rules),
            'week' => self::week($snapshot, $rules),
            'signals' => (int) $signals,
        ];
    }

    /** What the model is given and what the hash covers: no signal count, which changes nightly. */
    public static function forModel(array $facts): array
    {
        unset($facts['signals']);

        return $facts;
    }

    public static function hash(array $facts): string
    {
        return hash('sha256', json_encode(self::forModel($facts)).'|'.config('services.taste_profile.prompt_version'));
    }

    /** The words a category may be named by: its label, lower-cased. Unknown and "other" have none. */
    public static function categoryLabel(string $key): ?string
    {
        if ($key === EventCategory::IntlMeetup->value) {
            return 'international meetup';
        }
        if ($key === EventCategory::Other->value) {
            return null;
        }
        $label = SpotCategory::tryFrom($key)?->label() ?? EventCategory::tryFrom($key)?->label();

        return $label !== null ? mb_strtolower($label) : null;
    }

    /** @return list<string> every category label the app knows, for the sentence check */
    public static function categoryVocabulary(): array
    {
        $keys = [...array_map(fn (SpotCategory $c) => $c->value, SpotCategory::cases()), ...array_map(fn (EventCategory $c) => $c->value, EventCategory::cases())];

        return array_values(array_unique(array_filter(array_map(self::categoryLabel(...), $keys))));
    }

    /** @return list<string> */
    public static function veedelNames(): array
    {
        return array_values(array_filter(collect(config('veedels', []))->flatten()->all(), 'is_string'));
    }

    /**
     * @param  array<string, float>  $scores
     * @return array{liked: list<array{name: string, level: string}>, avoided: list<array{name: string, level: string}>}
     */
    private static function bucket(array $scores, array $rules, int $limit): array
    {
        $liked = array_filter($scores, fn (float $score) => $score >= (float) ($rules['some_min'] ?? 1.0));
        $avoided = array_filter($scores, fn (float $score) => $score <= (float) ($rules['avoid_max'] ?? -2.0));
        // Highest first; ties by name, so the same scores always give the same facts.
        uksort($liked, fn ($a, $b) => [$liked[$b], $a] <=> [$liked[$a], $b]);
        uksort($avoided, fn ($a, $b) => [$avoided[$a], $a] <=> [$avoided[$b], $b]);

        $top = $liked === [] ? 0.0 : max($liked);
        $strong = max((float) ($rules['strong_min'] ?? 5.0), $top * (float) ($rules['strong_share'] ?? 0.5));
        $rows = [];
        foreach (array_slice($liked, 0, $limit, true) as $name => $score) {
            $rows[] = ['name' => (string) $name, 'level' => $score >= $strong ? 'strong' : 'some'];
        }

        return [
            'liked' => $rows,
            'avoided' => array_map(fn ($name) => ['name' => (string) $name, 'level' => 'avoid'], array_keys($avoided)),
        ];
    }

    /** @return list<string> the dayparts holding a fair share of the positive score, strongest first */
    private static function dayparts(AffinitySnapshot $snapshot, array $rules): array
    {
        $scores = array_filter(
            array_map(fn (array $row) => $row['score'], $snapshot->affinities['daypart']['all'] ?? []),
            fn (float $score, string|int $daypart) => $score > 0 && in_array($daypart, self::DAYPARTS, true),
            ARRAY_FILTER_USE_BOTH,
        );
        $total = array_sum($scores);
        if ($total <= 0) {
            return [];
        }
        uksort($scores, fn ($a, $b) => [$scores[$b], $a] <=> [$scores[$a], $b]);
        $kept = array_filter($scores, fn (float $score) => $score / $total >= (float) ($rules['daypart_share'] ?? 0.25)
            && $score >= (float) ($rules['some_min'] ?? 1.0));

        return array_slice(array_map('strval', array_keys($kept)), 0, (int) ($rules['dayparts'] ?? 2));
    }

    /** "weekend" or "weekday" when one clearly wins per day (2 weekend days vs 5 weekdays), else null. */
    private static function week(AffinitySnapshot $snapshot, array $rules): ?string
    {
        $sum = fn (string $context): float => array_sum(array_filter(
            array_map(fn (array $row) => $row['score'], $snapshot->affinities['category'][$context] ?? []),
            fn (float $score) => $score > 0,
        ));
        $weekend = $sum('weekend') / 2;
        $weekday = $sum('weekday') / 5;
        $ratio = (float) ($rules['week_lean_ratio'] ?? 1.5);

        return match (true) {
            $weekend > 0 && $weekend >= $weekday * $ratio => 'weekend',
            $weekday > 0 && $weekday >= $weekend * $ratio => 'weekday',
            default => null,
        };
    }
}
