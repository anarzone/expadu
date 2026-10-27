<?php

namespace App\Composer\Actions;

use App\Models\User;
use App\Models\UserPlace;
use Carbon\CarbonImmutable;

/**
 * Their own places beyond where Home and Work are (owner, 27 Oct 2026: "Composer can do
 * everything"): when they arrive at one and leave Work, on which days, and their other places
 * ("Gym"), added or removed, as their places' editor does (UserPlaceController). Addresses stay
 * out of what the AI is told; the card says them.
 */
final class MyPlacesAction implements ComposerAction
{
    /** Days as the editor's day_mode keeps them, or the days they name. */
    public const DAYS = ['every_day' => 'all', 'weekdays' => 'weekdays', 'weekends' => 'weekends'];

    private const WEEK = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(private MyPlaceAction $locator) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        return match ($action) {
            'set_place_times' => $this->prepareTimes($user, $input),
            'add_my_place' => $this->prepareAdd($user, $input),
            'remove_my_place' => $this->prepareRemove($user, $input),
            default => throw new ActionRefused('Composer can’t change that.'),
        };
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        if ($action === 'set_place_times') {
            $place = $this->own($user, $prepared['id']);
            $place->update($prepared['after']);

            return ['title' => "{$prepared['label']}: ".self::times($prepared['after']), 'sub' => $prepared['sub'], 'link' => self::LINK,
                'said' => "{$prepared['label']}'s times are now: ".self::times($prepared['after']).'.', 'undo' => ['id' => $place->id, 'label' => $prepared['label'], 'before' => $prepared['before']]];
        }
        if ($action === 'add_my_place') {
            $place = $user->places()->create([...$prepared['place'], 'emoji' => '📍', 'sort_order' => $user->places()->count()]);

            return ['title' => "{$prepared['name']} is one of your places", 'sub' => $prepared['sub'], 'link' => self::LINK,
                // The address stays out of what the AI is told; the card says it.
                'said' => "{$prepared['name']} is saved as one of their places.", 'undo' => ['id' => $place->id, 'name' => $prepared['name']]];
        }
        $this->own($user, $prepared['id'])->delete();

        return ['title' => "{$prepared['name']} isn’t one of your places any more", 'sub' => 'Undo puts it back as it was.', 'link' => self::LINK,
            'said' => "{$prepared['name']} is removed from their places.", 'undo' => ['name' => $prepared['name'], 'row' => $prepared['row']]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        if ($action === 'set_place_times') {
            $this->own($user, $undo['id'])->update($undo['before']);

            return ['title' => "Undone. {$undo['label']}: ".self::times($undo['before']).' again.'];
        }
        if ($action === 'add_my_place') {
            $user->places()->whereKey($undo['id'])->delete();

            return ['title' => "Undone. {$undo['name']} isn’t one of your places."];
        }
        $user->places()->create($undo['row']);

        return ['title' => "Undone. {$undo['name']} is one of your places again."];
    }

    /** Their places' editor in Composer's Profile. */
    private const LINK = ['label' => 'See your places →', 'href' => '/profile'];

    /** @return array<string, mixed> */
    private function prepareTimes(User $user, array $input): array
    {
        $place = $this->named($user, (string) ($input['which'] ?? ''));
        $label = self::label($place);
        $before = ['arrive_by' => self::hhmm($place->arrive_by), 'leave_at' => self::hhmm($place->leave_at), 'day_mode' => $place->day_mode, 'active_days' => $place->active_days];
        $after = $before;
        foreach (['arrive_by', 'leave_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $time = $input[$field];
                if ($time !== null && $time !== 'none' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time) !== 1) {
                    throw new ActionRefused('Say the time as HH:MM.');
                }
                $after[$field] = in_array($time, [null, 'none'], true) ? null : $time;
            }
        }
        if (array_key_exists('days', $input)) {
            $days = $input['days'];
            if (is_string($days) && isset(self::DAYS[$days])) {
                [$after['day_mode'], $after['active_days']] = [self::DAYS[$days], null];
            } elseif (is_array($days) && $days !== [] && array_diff($days, self::WEEK) === []) {
                [$after['day_mode'], $after['active_days']] = ['custom', array_values(array_intersect(self::WEEK, $days))];
            } else {
                throw new ActionRefused('Say the days: every day, weekdays, weekends, or the days of the week.');
            }
        }
        if ($after === $before) {
            throw new ActionRefused("{$label} already has those times.");
        }

        return ['id' => $place->id, 'label' => $label, 'before' => $before, 'after' => $after,
            'propose' => "Set {$label}'s times to ".self::times($after).'?', 'sub' => 'Travel, My day and your brief plan from them. Before: '.self::times($before).'.'];
    }

    /** @return array<string, mixed> */
    private function prepareAdd(User $user, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 2 || in_array(mb_strtolower($name), ['home', 'work'], true)) {
            throw new ActionRefused(mb_strlen($name) < 2 ? 'Say what they call it (“Gym”).' : 'Home and Work are set with set_my_place.');
        }
        if ($user->places()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new ActionRefused("They already have a place called {$name}.");
        }
        $place = $this->locator->locate(trim((string) ($input['place'] ?? '')), is_string($input['area'] ?? null) ? $input['area'] : null);

        return ['name' => $name, 'place' => [...$place, 'name' => $name, 'category' => 'custom'],
            'propose' => "Save {$name} as one of your places?", 'sub' => 'At '.($place['address'] ?? $place['name']).'. Travel plans ways to it from your places.'];
    }

    /** @return array<string, mixed> */
    private function prepareRemove(User $user, array $input): array
    {
        $place = $this->named($user, (string) ($input['which'] ?? $input['name'] ?? ''));
        if ($place->isProtected()) {
            throw new ActionRefused('Home and Work can’t be removed: Travel and My day need them. They can be set to another place.');
        }
        $row = array_intersect_key($place->getAttributes(), array_flip(['emoji', 'name', 'address', 'lat', 'lng', 'located_from_address', 'sort_order', 'arrive_by', 'leave_at', 'category', 'day_mode', 'active_days']));

        return ['id' => $place->id, 'name' => $place->name, 'row' => [...$row, 'active_days' => $place->active_days],
            'propose' => "Remove {$place->name} from your places?", 'sub' => 'Undo puts it back as it was.'];
    }

    /**
     * Home, Work, or one of their other places by the name they gave it.
     *
     * @throws ActionRefused
     */
    private function named(User $user, string $which): UserPlace
    {
        $said = mb_strtolower(trim($which));
        $place = in_array($said, ['home', 'work'], true) ? $user->places()->where('category', $said)->first()
            : $user->places()->whereNotIn('category', ['home', 'work'])->whereRaw('lower(name) = ?', [$said])->first();

        return $place ?? throw new ActionRefused(in_array($said, ['home', 'work'], true) ? ucfirst($said).' isn’t set yet: set it first (set_my_place).'
            : "They have no place called “{$which}”. my_places lists them.");
    }

    private function own(User $user, int $id): UserPlace
    {
        return $user->places()->whereKey($id)->first() ?? throw new ActionRefused('That place isn’t theirs any more.');
    }

    private static function label(UserPlace $place): string
    {
        return in_array($place->category, ['home', 'work'], true) ? ucfirst((string) $place->category) : (string) $place->name;
    }

    /** "arrive by 09:00, leave at 17:30, weekdays" */
    private static function times(array $t): string
    {
        $days = match ($t['day_mode'] ?? 'all') {
            'weekdays' => 'weekdays', 'weekends' => 'weekends',
            'custom' => implode(', ', array_map('ucfirst', $t['active_days'] ?? [])) ?: 'no days',
            default => 'every day',
        };

        return implode(', ', array_filter([isset($t['arrive_by']) ? "arrive by {$t['arrive_by']}" : 'no arrive-by time',
            isset($t['leave_at']) ? "leave at {$t['leave_at']}" : null, $days]));
    }

    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
