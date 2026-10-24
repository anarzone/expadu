<?php

namespace App\Composer\Actions;

use App\Composer\Answers\Destinations;
use App\Composer\CandidateRepository;
use App\Composer\WorkspaceRefusal;
use App\Composer\WorkspaceStore;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Adds to, takes from, swaps in or clears a day of the person's plans: the same edits,
 * refusals and Undo as Plans makes (WorkspaceStore), so either Undo puts it back.
 */
final class DayActions implements ComposerAction
{
    public function __construct(private WorkspaceStore $workspace, private CandidateRepository $catalogue, private Destinations $destinations) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $date = self::date($input['day'] ?? null, $now);
        $where = self::where($date, $now);
        $plan = $this->workspace->plan($user, $date);

        return match ($action) {
            'add_to_day' => (function () use ($input, $date, $where): array {
                [$candidate, $name, $veedel] = $this->candidate((string) ($input['item'] ?? ''), $date, is_string($input['area'] ?? null) ? $input['area'] : null);
                [$start, $end] = [self::clock($input['start'] ?? null), self::clock($input['end'] ?? null)];
                $time = $start !== null ? ', '.$start.($end !== null ? "–{$end}" : '') : '';

                return ['date' => $date, 'edit' => array_filter(['action' => 'add', 'date' => $date, 'candidate' => $candidate, 'start' => $start, 'end' => $start !== null ? $end : null]),
                    'name' => $name, 'propose' => "Add {$name} to {$where}{$time}?", 'sub' => null, 'about' => $name.($veedel !== null ? " in {$veedel}" : '')];
            })(),
            'remove_from_day' => (function () use ($input, $plan, $date, $where): array {
                $slot = $this->slot($plan, (string) ($input['item'] ?? ''), $where);

                return ['date' => $date, 'edit' => ['action' => 'remove', 'date' => $date, 'id' => $slot['id']], 'name' => $slot['name'],
                    'propose' => "Take {$slot['name']} off {$where}?", 'sub' => self::span($slot)];
            })(),
            'swap_in_day' => (function () use ($input, $plan, $date, $where): array {
                $slot = $this->slot($plan, (string) ($input['item'] ?? ''), $where);
                [$candidate, $name, $veedel] = $this->candidate((string) ($input['with'] ?? ''), $date, is_string($input['area'] ?? null) ? $input['area'] : null);

                return ['date' => $date, 'edit' => ['action' => 'replace', 'date' => $date, 'id' => $slot['id'], 'candidate' => $candidate],
                    'name' => $name, 'was' => $slot['name'], 'propose' => "Swap {$slot['name']} for {$name}?", 'sub' => self::span($slot),
                    'about' => $name.($veedel !== null ? " in {$veedel}" : '')];
            })(),
            'clear_day' => $plan === null ? throw new ActionRefused('There’s nothing planned '.($where === 'My day' ? 'today' : 'then').'.')
                : ['date' => $date, 'edit' => ['action' => 'cancel_day', 'date' => $date], 'name' => $where,
                    'propose' => "Clear {$where}?", 'sub' => count($plan['slots']).' '.(count($plan['slots']) === 1 ? 'thing' : 'things').' planned'],
            default => throw new ActionRefused('Composer can’t change a day that way.'),
        };
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        try {
            $made = $this->workspace->change($user, $prepared['edit']);
        } catch (WorkspaceRefusal $refusal) {
            throw new ActionRefused($refusal->getMessage());
        }
        $change = $made['change'];
        $row = $change['rows'][0] ?? null;
        $where = self::where($prepared['date'], $now);
        $title = match ($action) {
            'add_to_day' => "Added {$prepared['name']} to {$where}".(isset($row['to']) ? ", {$row['to']}" : ''),
            'remove_from_day' => "Took {$prepared['name']} off {$where}",
            'swap_in_day' => "Swapped {$prepared['was']} for {$prepared['name']}".(isset($row['to']) ? ", {$row['to']}" : ''),
            default => "Cleared {$where}",
        };

        return array_filter(['title' => $title, 'link' => ['label' => 'Open '.($where === 'My day' ? 'My day' : 'your plans').' →', 'nav' => 'plans'],
            // The day as it is now, the new item marked.
            'day' => $made['plan'] === null ? null : array_map(fn (array $slot): array => ['time' => self::span($slot), 'what' => (string) $slot['name'],
                'new' => in_array($slot['id'], array_column(array_filter($change['rows'], fn (array $r): bool => in_array($r['kind'], ['added', 'replaced'], true)), 'id'), true)],
                $made['plan']['slots']),
            'said' => $title.(isset($prepared['about']) ? " ({$prepared['about']})" : '').'.', 'undo' => ['change' => $change['id'], 'date' => $prepared['date'], 'name' => $prepared['name'], 'was' => $prepared['was'] ?? null]],
            fn ($v) => $v !== null);
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        try {
            $this->workspace->change($user, ['action' => 'undo', 'change' => $undo['change']]);
        } catch (WorkspaceRefusal $refusal) {
            throw new ActionRefused($refusal->getMessage());
        }
        $where = self::where($undo['date'], $now);

        return ['title' => match ($action) {
            'add_to_day' => "Undone. {$undo['name']} is off {$where} again.",
            'remove_from_day' => "Undone. {$undo['name']} is back in {$where}.",
            'swap_in_day' => "Undone. {$undo['was']} is back in place of {$undo['name']}.",
            default => "Undone. {$where} is back.",
        }];
    }

    /**
     * What to add: a place or listing its lookups found ("spot:12", "e34@1767225600"), else
     * the one place of that name.
     *
     * @return array{0: string, 1: string, 2: ?string} its candidate id, name and Veedel
     *
     * @throws ActionRefused
     */
    private function candidate(string $item, string $date, ?string $area = null): array
    {
        $id = match (true) {
            preg_match('/^spot:\d+$/', $item) === 1 => $item,
            preg_match('/^e(\d+)@(\d+)$/', $item, $event) === 1 => "event:{$event[1]}:{$event[2]}",
            default => null,
        };
        if ($id === null) {
            $found = array_values(array_filter($this->destinations->find($item, $area, null), fn (array $p): bool => $p['spot'] !== null));
            if (count($found) !== 1) {
                throw new ActionRefused($found === [] ? "“{$item}” isn’t among Expadu’s places."
                    : "More than one place is called “{$item}”: in ".implode(', ', array_filter(array_column($found, 'area'))).'. Ask which.');
            }
            $id = "spot:{$found[0]['spot']}";
        }
        $candidate = $this->catalogue->byIds([$id], CarbonImmutable::parse($date, 'Europe/Berlin'))[0] ?? null;
        if ($candidate === null) {
            throw new ActionRefused('That place or listing can’t be planned for that day.');
        }

        return [$candidate->id, $candidate->name, $candidate->veedel];
    }

    /**
     * The item in their day they mean, by its id or its name.
     *
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    private function slot(?array $plan, string $item, string $where): array
    {
        $slots = $plan['slots'] ?? [];
        $said = mb_strtolower(trim($item));
        $found = array_values(array_filter($slots, fn (array $slot): bool => $slot['id'] === $item
            || ($said !== '' && str_contains(mb_strtolower((string) $slot['name']), $said))));
        if ($found === []) {
            throw new ActionRefused("“{$item}” isn’t in {$where}.");
        }
        if (count($found) > 1) {
            throw new ActionRefused("More than one thing in {$where} fits “{$item}”: ".implode(', ', array_column($found, 'name')).'. Ask which.');
        }

        return $found[0];
    }

    /** today, tomorrow or a date within the next weeks; else today. */
    private static function date(mixed $day, CarbonImmutable $now): string
    {
        $today = $now->setTimezone('Europe/Berlin')->startOfDay();

        return match (true) {
            $day === 'tomorrow' => $today->addDay()->toDateString(),
            is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && ($date = CarbonImmutable::parse($day, 'Europe/Berlin'))->betweenIncluded($today, $today->addWeeks(8)) => $date->toDateString(),
            default => $today->toDateString(),
        };
    }

    /** "My day" today, else the day's name ("Saturday"). */
    private static function where(string $date, CarbonImmutable $now): string
    {
        return $date === $now->setTimezone('Europe/Berlin')->toDateString() ? 'My day' : CarbonImmutable::parse($date, 'Europe/Berlin')->isoFormat('dddd');
    }

    private static function clock(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }

    private static function span(array $slot): string
    {
        return ($slot['start_time'] ?? '').(($slot['end_time'] ?? null) !== null ? ' – '.$slot['end_time'] : '');
    }
}
