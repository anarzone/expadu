<?php

namespace App\Composer\Actions;

use App\Composer\Answers\Destinations;
use App\Models\Spot;
use App\Models\User;
use App\Places\OpenNearby;
use App\Places\RecordPlaceFeedback;
use Carbon\CarbonImmutable;

/**
 * Saves a place, or marks it been, more like this or not for me, or takes that back: the
 * same record as Around here's buttons (RecordPlaceFeedback).
 */
final class PlaceFeedbackAction implements ComposerAction
{
    public const STATES = ['saved', 'been', 'more_like_this', 'not_interested', 'clear'];

    private const PROPOSE = ['saved' => 'Save %s for later?', 'been' => 'Mark %s as a place you’ve been?',
        'more_like_this' => 'See more places like %s?', 'not_interested' => 'Stop suggesting %s?', 'clear' => 'Take %s off your saved places?'];

    private const DONE = ['saved' => 'Saved %s', 'been' => 'Marked %s as a place you’ve been', 'more_like_this' => 'You’ll see more places like %s',
        'not_interested' => '%s won’t be suggested again', 'clear' => '%s isn’t saved any more'];

    private const UNDONE = ['saved' => 'Undone. %s isn’t saved.', 'clear' => 'Undone. %s is saved again.'];

    public function __construct(private RecordPlaceFeedback $feedback, private Destinations $destinations) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $state = in_array($input['state'] ?? null, self::STATES, true) ? $input['state'] : 'saved';
        $spot = $this->spot(trim((string) ($input['place'] ?? '')), is_string($input['area'] ?? null) ? $input['area'] : null);
        $current = $this->feedback->current($user, $spot);
        if (($current['state'] ?? null) === ($state === 'clear' ? null : $state)) {
            throw new ActionRefused($state === 'clear' ? "{$spot->name} isn’t saved." : sprintf(self::DONE[$state], $spot->name).' already.');
        }
        $row = app(OpenNearby::class)->rows([$spot->id], null, null, $now, $user)->first();
        $name = (string) ($row['name'] ?? $spot->name);

        return ['spot' => $spot->id, 'state' => $state, 'name' => $name, 'previous' => $current,
            'propose' => sprintf(self::PROPOSE[$state], $name), 'sub' => self::sub($row), 'link' => self::LINK,
            // What the AI may say of it: its kind and Veedel.
            'about' => implode(', ', array_filter([$name, isset($row['label']) ? 'a '.mb_strtolower($row['label']).(isset($row['veedel']) ? " in {$row['veedel']}" : '') : null]))];
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        $spot = Spot::query()->find($prepared['spot']) ?? throw new ActionRefused('That place is no longer listed.');
        $this->feedback->execute($user, $spot, $prepared['state']);
        $title = sprintf(self::DONE[$prepared['state']], $prepared['name']);

        return ['title' => $title, 'sub' => $prepared['sub'], 'link' => self::LINK, 'said' => "{$title} ({$prepared['about']}).",
            'undo' => ['spot' => $spot->id, 'name' => $prepared['name'], 'state' => $prepared['state'], 'previous' => $prepared['previous']]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $spot = Spot::query()->find($undo['spot']) ?? throw new ActionRefused('That place is no longer listed.');
        $previous = $undo['previous'] ?? null;
        $this->feedback->execute($user, $spot, $previous['state'] ?? 'clear', $previous['rating'] ?? null);

        return ['title' => sprintf(self::UNDONE[$undo['state']] ?? 'Undone. %s is back as it was.', $undo['name'])];
    }

    /** Composer's Saved panel lists saved places and events. */
    private const LINK = ['label' => 'See your saved places →', 'nav' => 'saved'];

    /**
     * The place meant: one its lookups found ("spot:12"), else the one place of that name.
     *
     * @throws ActionRefused
     */
    private function spot(string $place, ?string $area): Spot
    {
        if (preg_match('/^spot:(\d+)$/', $place, $id) === 1) {
            return Spot::query()->where('is_active', true)->find((int) $id[1]) ?? throw new ActionRefused('That place is no longer listed.');
        }
        if (mb_strlen($place) < 3) {
            throw new ActionRefused('Say which place.');
        }
        $found = array_values(array_filter($this->destinations->find($place, $area, null), fn (array $p): bool => $p['spot'] !== null));
        if ($found === []) {
            throw new ActionRefused("“{$place}” isn’t among Expadu’s places.");
        }
        if (count($found) > 1) {
            throw new ActionRefused("More than one place is called “{$place}”: in ".implode(', ', array_filter(array_column($found, 'area'))).'. Ask which.');
        }

        return Spot::query()->findOrFail($found[0]['spot']);
    }

    /** "Café · Mülheim · open until 19:30 today", from its listed facts only. */
    private static function sub(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }
        $hours = $row['hours'] ?? [];
        $when = match (true) {
            ($hours['state'] ?? null) === 'open' && isset($hours['until']) => "open until {$hours['until']} today",
            ($hours['state'] ?? null) === 'closed' && isset($hours['opens']) => "opens at {$hours['opens']} today",
            default => null,
        };

        return implode(' · ', array_filter([$row['label'] ?? null, $row['veedel'] ?? null, $when])) ?: null;
    }
}
