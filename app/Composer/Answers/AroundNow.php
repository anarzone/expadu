<?php

namespace App\Composer\Answers;

use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Composer\PaperworkGlance;
use App\Home\WhatsOn;
use App\Models\User;
use App\Services\DisruptionService;
use App\Services\UserTransitLinesService;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What's around the person right now, from real data only, for when Composer answers
 * without the AI (owner, 22 Oct 2026: it says so and shows what's on around you, never
 * guessing what the words meant). That's what's still on today in the listings (ranked as
 * Events ranks them), Cologne's weather, their own plans and appointments today and
 * tomorrow, KVB notices on the lines they use, and a Paperwork date this week. Each part
 * shows only when the data holds it, and there's always something to tap next. Its words
 * are Composer's own.
 */
final class AroundNow
{
    /** Listings shown. */
    private const EVENTS = 3;

    /** The person's own plans and appointments shown, nearest first. */
    private const PLANS = 3;

    /** KVB notices on their lines shown, most severe first. */
    private const NOTICES = 2;

    public function __construct(
        private EventAnswers $events,
        private WeatherService $weather,
        private RecordedAppointmentsBetween $appointments,
        private PaperworkGlance $paperwork,
        private DisruptionService $disruptions,
        private UserTransitLinesService $lines,
    ) {}

    /**
     * What's around them, after `$first` (why Composer answers without the AI): the facts,
     * the listings and what to do next, as cards.
     *
     * @param  array<string, array<string, mixed>>  $plans  the person's Composer plans, by date
     * @return array{text: string, blocks: list<array<string, mixed>>, suggestions: list<array<string, string>>}
     */
    public function answer(User $user, array $plans, CarbonImmutable $now, string $first): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $events = $this->events->stillOn($now, self::EVENTS);
        $weather = $this->weatherNow();
        $when = $now->format('H:i') >= '18:00' ? 'tonight' : 'today';
        $notices = $this->notices($user);
        $deadline = $this->deadline($user, $now);
        $facts = [
            ...($weather !== null ? [['label' => 'Weather', 'value' => $weather, 'known' => true]] : []),
            ...$this->day($user, $plans, $now),
            ...array_map(fn (array $notice): array => $notice['fact'], $notices),
            ...($deadline !== null ? [$deadline['fact']] : []),
        ];

        return [
            'text' => $first.' '.($events !== [] ? "Here’s what’s still on in Cologne {$when}." : "Nothing more is listed in Cologne {$when}."),
            'blocks' => array_values(array_filter([
                $facts !== [] ? array_filter(['type' => 'facts', 'facts' => $facts,
                    // One link each: KVB's live notices share one page.
                    'sources' => collect([...array_map(fn (array $notice): ?array => $notice['source'], $notices), $deadline['source'] ?? null])
                        ->filter()->unique('href')->values()->all() ?: null], fn ($v) => $v !== null) : null,
                $events !== [] ? ['type' => 'events', 'events' => $events,
                    'sources' => [['label' => 'What’s on', 'href' => WhatsOn::url(['date' => $now->toDateString()])]]] : null,
            ])),
            'suggestions' => $this->next($events !== [], $weather, $now),
        ];
    }

    /**
     * Next steps that work as they're asked: tonight's listings, a plan from now, an indoor
     * one when rain's coming, another day.
     *
     * @return list<array<string, string>>
     */
    private function next(bool $events, ?string $weather, CarbonImmutable $now): array
    {
        $rain = $weather !== null && preg_match('/\brain/iu', $weather) === 1;

        return array_values(array_filter([
            $events && $now->format('H:i') < '22:00' ? ['label' => 'What’s on tonight?', 'prompt' => 'What’s on tonight?'] : null,
            $now->format('H:i') < '21:00' ? ['label' => 'Plan the rest of today', 'prompt' => 'Help me plan the rest of today'] : null,
            $rain && $now->format('H:i') < '21:00' ? ['label' => 'Something indoors', 'prompt' => 'Help me plan the rest of today indoors'] : null,
            ['label' => 'Plan my Saturday', 'prompt' => 'Help me plan Saturday'],
        ]));
    }

    /** Cologne's weather now and the next hours ("14° · Partly cloudy · Dry next 8 hours"), or null when unknown. */
    private function weatherNow(): ?string
    {
        [$current, $forecast] = $this->safely('weather', fn (): array => [$this->weather->getCurrentWeather(), $this->weather->getForecast()], [[], []]);
        if (($current['available'] ?? false) !== true) {
            return null;
        }
        $summary = $forecast['rain_summary'] ?? null;
        $condition = $current['condition'] ?? null;

        return "{$current['temperature']}°".(is_string($condition) && $condition !== 'Unavailable' ? " · {$condition}" : '')
            .(is_string($summary) && $summary !== '' && $summary !== 'No data' ? " · {$summary}" : '');
    }

    /**
     * The person's own plans and recorded appointments still to come today, then
     * tomorrow's, nearest first, as their records hold them (also what the assistant may
     * look at, with the opt-in).
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @return list<array{label: string, value: string, known: bool}>
     */
    public function day(User $user, array $plans, CarbonImmutable $now): array
    {
        $tomorrow = $now->addDay()->toDateString();
        $items = collect($plans)->only([$now->toDateString(), $tomorrow])->flatMap(fn (array $plan): array => $plan['slots'] ?? [])
            ->map(fn (array $slot): array => ['start' => CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin'),
                'end' => isset($slot['end_at']) ? CarbonImmutable::parse($slot['end_at']) : null, 'title' => (string) $slot['name']]);
        $recorded = $this->safely('appointments', fn (): array => $this->appointments->for($user, $now->startOfDay(), $now->startOfDay()->addDays(2)), []);
        foreach ($recorded as $row) {
            $start = CarbonImmutable::parse($row['starts_at'])->setTimezone('Europe/Berlin');
            // An appointment the plan holds already is shown once.
            if (! $items->contains(fn (array $item): bool => $item['start']->equalTo($start))) {
                $items->push(['start' => $start, 'end' => null,
                    'title' => $row['process_title'] !== null ? "{$row['process_title']} appointment" : 'Your recorded appointment']);
            }
        }

        return $items
            ->filter(fn (array $item): bool => $item['start']->greaterThanOrEqualTo($now->startOfMinute()) || $item['end']?->greaterThan($now))
            ->sortBy(fn (array $item): int => $item['start']->getTimestamp())
            ->take(self::PLANS)
            ->map(fn (array $item): array => ['label' => ($item['start']->toDateString() === $tomorrow ? 'Tomorrow ' : 'Today ').$item['start']->format('H:i'),
                'value' => $item['title'], 'known' => true])
            ->values()->all();
    }

    /**
     * Open KVB notices on the lines the person uses, in English where a checked brief is
     * written, with KVB's own page.
     *
     * @return list<array{fact: array{label: string, value: string, known: bool}, source: ?array{label: string, href: string}}>
     */
    private function notices(User $user): array
    {
        [$lines, $disruptions] = $this->safely('notices', function () use ($user): array {
            $lines = array_map('strval', $this->lines->getRelevantLines($user)['lines']->all());

            return [$lines, $lines === [] ? [] : $this->disruptions->getLineDisruptions()];
        }, [[], []]);

        return collect($disruptions)
            ->map(fn (array $notice): array => [...$notice, 'mine' => array_values(array_intersect(array_map('strval', $notice['affected_lines'] ?? []), $lines))])
            ->filter(fn (array $notice): bool => $notice['mine'] !== [])
            ->take(self::NOTICES)
            ->map(fn (array $notice): array => [
                'fact' => ['label' => 'KVB '.implode(', ', $notice['mine']), 'value' => (string) ($notice['brief']['headline'] ?? $notice['title']), 'known' => true],
                'source' => $notice['url'] !== null ? ['label' => 'KVB notice', 'href' => $notice['url']] : null,
            ])
            ->values()->all();
    }

    /**
     * A Paperwork date this week, as Composer's Your day names it (frame.tsx): the step or
     * the document, its day, and the step to open.
     *
     * @return array{fact: array{label: string, value: string, known: bool}, source: array{label: string, href: string}}|null
     */
    private function deadline(User $user, CarbonImmutable $now): ?array
    {
        $deadline = $this->safely('paperwork', fn (): ?array => $this->paperwork->for($user)['deadline'], null);
        if ($deadline === null || $deadline['days_remaining'] < 0 || $deadline['days_remaining'] >= 7) {
            return null;
        }
        $date = CarbonImmutable::parse($deadline['date'], 'Europe/Berlin');
        $day = match ($deadline['date']) {
            $now->toDateString() => 'Today',
            $now->addDay()->toDateString() => 'Tomorrow',
            default => $date->format('D j M'),
        };
        $what = $deadline['kind'] === 'document_expiry'
            ? ($deadline['document'] === 'visa' ? 'Visa expires' : 'Document expires')
            : ($deadline['process'] ?? 'A date to aim for');

        return [
            'fact' => ['label' => 'Paperwork', 'value' => "{$what} · {$day}", 'known' => true],
            'source' => ['label' => 'Paperwork', 'href' => route('paperwork', $deadline['occurrence_key'] !== null
                ? ['view' => 'detail', 'process' => (string) $deadline['occurrence_key']] : [], false)],
        ];
    }

    /**
     * One part of the answer, which may fail without failing the rest. It reads in its own
     * savepoint, so a failed query never aborts a transaction around it. Only the part and
     * the error's type are logged.
     */
    private function safely(string $part, callable $read, mixed $otherwise): mixed
    {
        try {
            return DB::transaction(fn () => $read());
        } catch (Throwable $error) {
            Log::warning('Around you now: a part is unavailable.', ['part' => $part, 'error_type' => $error::class]);

            return $otherwise;
        }
    }
}
