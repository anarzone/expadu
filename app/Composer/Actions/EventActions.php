<?php

namespace App\Composer\Actions;

use App\Models\Event;
use App\Models\User;
use App\Services\EventReminders;
use App\Services\SavedEvents;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Saves a listing for later or sets a reminder for it: the same records as the event
 * sheet's Save and Remind me (SavedEvents, EventReminders), with their Undo.
 */
final class EventActions implements ComposerAction
{
    /** A reminder's time, as said. */
    private const WHEN = ['1h' => 'an hour before', '3h' => 'three hours before', 'morning' => 'on the morning of'];

    private const LINK = ['label' => 'See your saved events →', 'nav' => 'saved'];

    public function __construct(private SavedEvents $saved, private EventReminders $reminders) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        [$event, $start] = $this->occurrence((string) ($input['event'] ?? ''), $now);
        $title = (string) ($event->title_en ?: $event->title);
        $base = ['event' => $event->id, 'start' => $start->toIso8601String(), 'title' => $title, 'sub' => self::sub($event, $start, $now), 'link' => self::LINK,
            'about' => "{$title}, ".self::when($start, $now)];

        if ($action === 'save_event') {
            $saved = ($input['saved'] ?? true) !== false;
            if ($this->saved->isSaved($user, $event->id, $start) === $saved) {
                throw new ActionRefused($saved ? "{$title} is saved already." : "{$title} isn’t saved.");
            }

            return [...$base, 'saved' => $saved, 'propose' => $saved ? "Save {$title} for later?" : "Take {$title} off your saved events?"];
        }
        $on = ($input['on'] ?? true) !== false;
        $offset = in_array($input['when'] ?? null, EventReminders::OFFSETS, true) ? $input['when'] : '1h';
        $previous = $this->reminders->current($user, $event->id, $start);
        if (! $on && $previous === null) {
            throw new ActionRefused("No reminder is set for {$title}.");
        }
        if ($on && $previous === $offset) {
            throw new ActionRefused('A reminder '.self::WHEN[$offset]." {$title} is set already.");
        }

        return [...$base, 'on' => $on, 'offset' => $offset, 'previous' => $previous,
            'propose' => $on ? 'Remind you '.self::WHEN[$offset]." {$title}?" : "Turn off the reminder for {$title}?"];
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        $start = CarbonImmutable::parse($prepared['start'])->setTimezone('Europe/Berlin');
        try {
            if ($action === 'save_event') {
                $this->saved->remember($user, $prepared['event'], $start, $prepared['saved']);
                $title = $prepared['saved'] ? "Saved {$prepared['title']}" : "{$prepared['title']} isn’t saved any more";

                return ['title' => $title, 'sub' => $prepared['sub'], 'link' => self::LINK, 'said' => "{$title} ({$prepared['about']}).",
                    'undo' => ['event' => $prepared['event'], 'start' => $prepared['start'], 'title' => $prepared['title'], 'saved' => $prepared['saved']]];
            }
            $prepared['on'] ? $this->reminders->set($user, $prepared['event'], $start, $prepared['offset']) : $this->reminders->forget($user, $prepared['event'], $start);
        } catch (ValidationException $error) {
            throw new ActionRefused((string) collect($error->errors())->flatten()->first());
        }
        $title = $prepared['on'] ? 'You’ll get a reminder '.self::WHEN[$prepared['offset']]." {$prepared['title']}" : "No reminder for {$prepared['title']} any more";

        return ['title' => $title, 'sub' => $prepared['sub'], 'said' => "{$title} ({$prepared['about']}).",
            'undo' => ['event' => $prepared['event'], 'start' => $prepared['start'], 'title' => $prepared['title'], 'previous' => $prepared['previous']]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $start = CarbonImmutable::parse($undo['start'])->setTimezone('Europe/Berlin');
        try {
            if ($action === 'save_event') {
                $this->saved->remember($user, $undo['event'], $start, ! $undo['saved']);

                return ['title' => $undo['saved'] ? "Undone. {$undo['title']} isn’t saved." : "Undone. {$undo['title']} is saved again."];
            }
            $undo['previous'] === null ? $this->reminders->forget($user, $undo['event'], $start) : $this->reminders->set($user, $undo['event'], $start, $undo['previous']);
        } catch (ValidationException $error) {
            throw new ActionRefused((string) collect($error->errors())->flatten()->first());
        }

        return ['title' => $undo['previous'] === null ? "Undone. No reminder for {$undo['title']}." : "Undone. The reminder for {$undo['title']} is back as it was."];
    }

    /**
     * The listing meant: one its lookups found ("e34@1767225600"), or a listing this
     * conversation showed ("e34"), at its next time in the next two weeks.
     *
     * @return array{0: Event, 1: CarbonImmutable}
     *
     * @throws ActionRefused
     */
    private function occurrence(string $id, CarbonImmutable $now): array
    {
        if (preg_match('/^e(\d+)(?:@(\d+))?$/', $id, $m) !== 1) {
            throw new ActionRefused('Say which listing, from the ones shown.');
        }
        $start = isset($m[2]) ? CarbonImmutable::createFromTimestamp((int) $m[2], 'Europe/Berlin') : null;
        $found = Event::occurringBetween(($start ?? $now)->startOfDay(), ($start ?? $now)->addDays($start === null ? 14 : 0)->endOfDay(), [(int) $m[1]])
            ->first(fn (array $o): bool => $start === null ? $o['starts_at']->isFuture() : $o['starts_at']->equalTo($start));
        if ($found === null) {
            throw new ActionRefused('That listing isn’t on any more.');
        }

        return [$found['event'], CarbonImmutable::instance($found['starts_at'])->setTimezone('Europe/Berlin')];
    }

    /** "Today at 20:00 · Stadtgarten". */
    private static function sub(Event $event, CarbonImmutable $start, CarbonImmutable $now): string
    {
        return implode(' · ', array_filter([ucfirst(self::when($start, $now)), $event->location_name]));
    }

    private static function when(CarbonImmutable $start, CarbonImmutable $now): string
    {
        $day = match (true) {
            $start->isSameDay($now) => 'today',
            $start->isSameDay($now->addDay()) => 'tomorrow',
            default => $start->format('l j F'),
        };

        return "{$day} at {$start->format('H:i')}";
    }
}
