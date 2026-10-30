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
    public const STATES = ['saved', 'been', 'not_been', 'more_like_this', 'not_interested', 'clear'];

    /** How Been there went, as its thumbs say. */
    public const RATINGS = ['up', 'down'];

    private const PROPOSE = ['saved' => 'Save %s for later?', 'been' => 'Mark %s as a place you’ve been?', 'not_been' => 'Unmark %s as a place you’ve been?',
        'more_like_this' => 'See more places like %s?', 'not_interested' => 'Stop suggesting %s?', 'clear' => 'Take %s off your saved places?'];

    private const DONE = ['saved' => 'Saved %s', 'been' => 'Marked %s as a place you’ve been', 'not_been' => '%s isn’t marked as a place you’ve been any more',
        'more_like_this' => 'You’ll see more places like %s', 'not_interested' => '%s won’t be suggested again', 'clear' => '%s isn’t saved any more'];

    private const UNDONE = ['saved' => 'Undone. %s isn’t saved.', 'clear' => 'Undone. %s is saved again.', 'been' => 'Undone. %s is back as it was.',
        'not_been' => 'Undone. %s is marked as a place you’ve been again.'];

    /** Been there's thumbs, in words. */
    private const RATED = ['up' => ', and you liked it', 'down' => ', and it wasn’t for you'];

    public function __construct(private RecordPlaceFeedback $feedback, private Destinations $destinations) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $state = in_array($input['state'] ?? null, self::STATES, true) ? $input['state'] : 'saved';
        $rating = $state === 'been' && in_array($input['rating'] ?? null, self::RATINGS, true) ? $input['rating'] : null;
        $spot = $this->spot(trim((string) ($input['place'] ?? '')), is_string($input['area'] ?? null) ? $input['area'] : null);
        // Been there is apart from the choice (saved, more like this, not for me): each is checked on its own.
        // Been again with thumbs they hadn't given is a change of its own.
        $current = $this->feedback->current($user, $spot);
        $already = match ($state) {
            'been' => $current['been'] && ($rating === null || $rating === ($current['been_rating'] ?? null)),
            'not_been' => ! $current['been'],
            default => $current['state'] === ($state === 'clear' ? null : $state),
        };
        if ($already) {
            throw new ActionRefused(match ($state) {
                'clear' => "{$spot->name} isn’t saved.", 'not_been' => "{$spot->name} isn’t marked as a place they’ve been.",
                default => sprintf(self::DONE[$state], $spot->name).' already.',
            });
        }
        $row = app(OpenNearby::class)->rows([$spot->id], null, null, $now, $user)->first();
        $name = (string) ($row['name'] ?? $spot->name);

        return ['spot' => $spot->id, 'state' => $state, 'rating' => $rating, 'name' => $name, 'previous' => $current,
            'propose' => rtrim(sprintf(self::PROPOSE[$state], $name), '?').(self::RATED[$rating] ?? '').'?', 'sub' => self::sub($row), 'link' => self::LINK,
            // What the AI may say of it: its kind and Veedel.
            'about' => implode(', ', array_filter([$name, isset($row['label']) ? 'a '.mb_strtolower($row['label']).(isset($row['veedel']) ? " in {$row['veedel']}" : '') : null]))];
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        $spot = Spot::query()->find($prepared['spot']) ?? throw new ActionRefused('That place is no longer listed.');
        $prepared['state'] === 'not_been' ? $this->feedback->forgetBeen($user, $spot) : $this->feedback->execute($user, $spot, $prepared['state'], $prepared['rating'] ?? null);
        $title = sprintf(self::DONE[$prepared['state']], $prepared['name']).(self::RATED[$prepared['rating'] ?? ''] ?? '');

        return ['title' => $title, 'sub' => $prepared['sub'], 'link' => self::LINK, 'said' => "{$title} ({$prepared['about']}).",
            'undo' => ['spot' => $spot->id, 'name' => $prepared['name'], 'state' => $prepared['state'], 'previous' => $prepared['previous']]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $spot = Spot::query()->find($undo['spot']) ?? throw new ActionRefused('That place is no longer listed.');
        $previous = $undo['previous'] ?? ['state' => null, 'been' => false, 'been_rating' => null];
        // Only what this change set goes back: Been on its own, the choice on its own.
        if (in_array($undo['state'], ['been', 'not_been'], true)) {
            $previous['been'] ? $this->feedback->been($user, $spot, $previous['been_rating'] ?? null) : $this->feedback->forgetBeen($user, $spot);
        } else {
            $this->feedback->execute($user, $spot, $previous['state'] ?? 'clear');
        }

        return ['title' => sprintf(self::UNDONE[$undo['state']] ?? 'Undone. %s is back as it was.', $undo['name'])];
    }

    /** Composer's Saved panel lists saved places and events. */
    private const LINK = ['label' => 'See your saved places →', 'nav' => 'saved'];

    /**
     * The place meant: one its lookups found ("spot:12"), else the one place of that name.
     *
     * @throws ActionRefused
     */
    public function spot(string $place, ?string $area): Spot
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
