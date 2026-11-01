<?php

namespace App\Home;

use App\Composer\WorkspaceStore;
use App\Day\DayProjection;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Today's "Pick up where you left off" (the mock's .column-continuation):
 * what the Composer conversation it opens on came to, as a wrap-up, never
 * the person's own words (owner, 21 Oct 2026): a plan it made for a day
 * ahead ("Your Saturday plan · 3 stops") or a trip it worked out today
 * ("Your trip to Mülheim"). A conversation that came to nothing has no
 * card. Today's own plans are in Your day already. Read through
 * WorkspaceStore::stored(), the upgraded state without each place's
 * availability projected (a stop that needs review still counts);
 * changes apply directly in Composer, so it only offers to carry on.
 */
final class TodayContinuation
{
    public function __construct(private WorkspaceStore $workspaces) {}

    /** @return array{kind: 'plan'|'trip', title: string, last_at: ?string}|null */
    public function for(User $user, ?CarbonImmutable $now = null): ?array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(DayProjection::TIMEZONE);
        $today = $now->format('Y-m-d');
        $state = $this->workspaces->stored($user);
        $active = collect($state['conversations'] ?? [])->firstWhere('id', $state['active'] ?? null);

        // Its latest result first: what it came to last is what to pick up.
        foreach (array_reverse($active['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }
            $kind = $message['kind'] ?? null;
            $date = $message['date'] ?? null;
            if (in_array($kind, ['plan', 'change'], true) && is_string($date) && $date > $today) {
                // The plan as it is now: an undone change leaves nothing to pick up.
                $stops = count($state['plans'][$date]['slots'] ?? []);
                if ($stops > 0) {
                    return ['kind' => 'plan', 'title' => $this->planOn($date, $now).' · '.$stops.' '.($stops === 1 ? 'stop' : 'stops'),
                        'last_at' => $message['created_at'] ?? null];
                }
            }
            $to = $message['trip']['for']['label'] ?? $message['trip']['for']['place'] ?? null;
            if ($kind === 'trip' && is_string($to) && $to !== '' && $this->on($message['created_at'] ?? null, $today)) {
                return ['kind' => 'trip', 'title' => "Your trip to {$to}", 'last_at' => $message['created_at']];
            }
        }

        return null;
    }

    /** "Your plan for tomorrow", "Your Saturday plan" within the week, else "Your plan for 3 Nov". */
    private function planOn(string $date, CarbonImmutable $now): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, DayProjection::TIMEZONE);
        $days = (int) $now->startOfDay()->diffInDays($day);

        return match (true) {
            $days === 1 => 'Your plan for tomorrow',
            $days < 7 => "Your {$day->format('l')} plan",
            default => "Your plan for {$day->format('j M')}",
        };
    }

    /** Whether a reply was written on this Cologne day (a trip goes stale with it). */
    private function on(?string $at, string $today): bool
    {
        return is_string($at) && CarbonImmutable::parse($at)->setTimezone(DayProjection::TIMEZONE)->format('Y-m-d') === $today;
    }
}
