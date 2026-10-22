<?php

namespace App\Composer\Answers;

use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Composer\PaperworkGlance;
use App\Models\User;
use App\Services\DisruptionService;
use App\Services\UserTransitLinesService;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "What's up?" (owner, 22 Oct 2026): what's around the person right now, from real data
 * only. That's what's still on today in the listings (ranked as Events ranks them, never
 * filtered), Cologne's weather, their own plans and appointments today and tomorrow,
 * KVB notices on the lines they use, and a Paperwork date this week. Each part shows
 * only when the data holds it, and there's always something to tap next. It also answers
 * whatever Composer has no answer of its own for, so no reply is a dead end.
 *
 * The AI may write the lead in the same call that reads the request, from the public
 * part only (listings and weather): the person's own day never leaves Expadu. Each of
 * its sentences is checked (AroundCheck) and kept for the answer for a few minutes;
 * without them, the lead is the code's own.
 */
final class AroundNow
{
    /** Listings shown, and the most the AI may name. */
    private const EVENTS = 3;

    /** The person's own plans and appointments shown, nearest first. */
    private const PLANS = 3;

    /** KVB notices on their lines shown, most severe first. */
    private const NOTICES = 2;

    /** Sentences the AI may write. */
    private const SENTENCES = 3;

    /** How long the AI's checked lead waits for its answer: the consent's own retention. */
    private const KEEP_MINUTES = 15;

    public function __construct(
        private EventAnswers $events,
        private WeatherService $weather,
        private RecordedAppointmentsBetween $appointments,
        private PaperworkGlance $paperwork,
        private DisruptionService $disruptions,
        private UserTransitLinesService $lines,
        private AroundCheck $check,
    ) {}

    /**
     * Whether a routed request is one this answers with the AI's lead: open or casual, or
     * nothing more specific understood it.
     *
     * @param  array<string, mixed>  $request
     */
    public static function opens(array $request): bool
    {
        return in_array($request['kind'], ['around', 'clarify'], true);
    }

    /**
     * The public part: what's still on today and the weather, as the answer shows them,
     * plus `items`, the same facts as the AI reads them, by the ids it cites.
     *
     * @return array{events: list<array<string, mixed>>, weather: ?string, when: string, items: array<string, string>}
     */
    public function listings(CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $events = $this->events->stillOn($now, self::EVENTS);
        $weather = $this->weatherNow();
        $items = ['now' => $now->format('l j F, H:i').', Cologne'];
        if ($weather !== null) {
            $items['weather'] = 'Cologne now: '.str_replace(' · ', ', ', $weather);
        }
        foreach ($events as $i => $event) {
            $items['e'.($i + 1)] = $this->eventLine($event);
        }

        return ['events' => $events, 'weather' => $weather, 'when' => $now->format('H:i') >= '18:00' ? 'tonight' : 'today', 'items' => $items];
    }

    /** The items as the AI reads them, one per line with its id. */
    public static function prompt(array $listings): string
    {
        return collect($listings['items'])->map(fn (string $text, string $id): string => "- {$id}: {$text}")->implode("\n");
    }

    /**
     * Keeps the AI's sentences that hold, with the listings they were written from, for
     * the answer to this request; with none, an earlier ask's lead goes too.
     *
     * @param  array<string, mixed>  $listings
     * @param  list<array{text: string, uses: list<string>}>  $reply
     */
    public function keep(User $user, string $text, array $listings, array $reply): void
    {
        $lead = $this->checked($listings['items'], $reply);
        $lead === ''
            ? Cache::forget($this->key($user, $text))
            : Cache::put($this->key($user, $text), ['listings' => $listings, 'lead' => $lead], now()->addMinutes(self::KEEP_MINUTES));
    }

    /**
     * The sentences that hold what their cited items say, joined; empty when none does.
     * The time and the weather are one fact each, shown with every answer, so any
     * sentence may use them; a listing only when the sentence cites it.
     *
     * @param  array<string, string>  $items
     * @param  list<array{text: string, uses: list<string>}>  $reply
     */
    public function checked(array $items, array $reply): string
    {
        return collect($reply)
            ->filter(fn (array $sentence): bool => $this->check->holds($sentence['text'], collect(['now', 'weather', ...$sentence['uses']])
                ->unique()->map(fn (string $id): string => $items[$id] ?? '')->implode("\n")))
            ->take(self::SENTENCES)
            ->map(fn (array $sentence): string => trim($sentence['text']))
            ->implode(' ');
    }

    /**
     * The answer: the lead, then the facts, the listings and what to do next.
     *
     * @param  array<string, mixed>  $request  the routed request ('around', or one with no answer of its own)
     * @param  array<string, array<string, mixed>>  $plans  the person's Composer plans, by date
     * @param  string|null  $first  said first when the request itself has no answer ("Expadu doesn't list doctors yet.")
     * @return array{text: string, extra: array<string, mixed>}
     */
    public function answer(User $user, string $text, array $request, array $plans, CarbonImmutable $now, ?string $first = null): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $kept = $first === null ? Cache::get($this->key($user, $text)) : null;
        $listings = is_array($kept) ? $kept['listings'] : $this->listings($now);
        $notices = $this->notices($user);
        $deadline = $this->deadline($user, $now);
        $facts = [
            ...($listings['weather'] !== null ? [['label' => 'Weather', 'value' => $listings['weather'], 'known' => true]] : []),
            ...$this->day($user, $plans, $now),
            ...array_map(fn (array $notice): array => $notice['fact'], $notices),
            ...($deadline !== null ? [$deadline['fact']] : []),
        ];

        return ['text' => is_array($kept) ? $kept['lead'] : $this->lead($request, $listings, $first), 'extra' => array_filter([
            'kind' => 'around',
            'written' => is_array($kept) ? 'ai' : 'listings',
            'events' => $listings['events'] ?: null,
            'facts' => $facts ?: null,
            'sources' => array_values(array_filter([
                $listings['events'] !== [] ? ['label' => 'Events', 'href' => route('events', ['date' => $now->toDateString()], false)] : null,
                ...array_map(fn (array $notice): ?array => $notice['source'], $notices),
                $deadline['source'] ?? null,
            ])) ?: null,
            'suggestions' => $this->next($listings, $now),
        ], fn ($value) => $value !== null)];
    }

    /**
     * The code's own lead, when the AI wrote none (it's off, or nothing it wrote held).
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $listings
     */
    private function lead(array $request, array $listings, ?string $first): string
    {
        $opening = $first ?? ($request['kind'] === 'around' ? null : 'I’m not sure I can help with that yet.');
        $around = $listings['events'] !== []
            ? "Here’s what’s still on in Cologne {$listings['when']}."
            : "Nothing more is listed in Cologne {$listings['when']}, but I can plan something for you.";

        return $opening === null ? $around : "{$opening} {$around}";
    }

    /**
     * Next steps that work as they're asked: tonight's listings, a plan from now, an indoor
     * one when rain's coming, another day.
     *
     * @param  array<string, mixed>  $listings
     * @return list<array<string, string>>
     */
    private function next(array $listings, CarbonImmutable $now): array
    {
        $rain = $listings['weather'] !== null && preg_match('/\brain/iu', $listings['weather']) === 1;

        return array_values(array_filter([
            $listings['events'] !== [] && $now->format('H:i') < '22:00' ? ['label' => 'What’s on tonight?', 'prompt' => 'What’s on tonight?'] : null,
            // Worded so the keyword reader plans them too, when the AI is off.
            $now->format('H:i') < '21:00' ? ['label' => 'Plan the rest of today', 'prompt' => 'Help me plan the rest of today'] : null,
            $rain && $now->format('H:i') < '21:00' ? ['label' => 'Something indoors', 'prompt' => 'Help me plan the rest of today indoors'] : null,
            ['label' => 'Plan my Saturday', 'prompt' => 'Help me plan Saturday'],
        ]));
    }

    /** Cologne's weather now and the next hours ("14° · Partly cloudy · Dry next 8 hours"), or null when unknown. */
    private function weatherNow(): ?string
    {
        try {
            $current = $this->weather->getCurrentWeather();
            $forecast = $this->weather->getForecast();
        } catch (Throwable) {
            return null;
        }
        if (($current['available'] ?? false) !== true) {
            return null;
        }
        $summary = $forecast['rain_summary'] ?? null;
        $condition = $current['condition'] ?? null;

        return "{$current['temperature']}°".(is_string($condition) && $condition !== 'Unavailable' ? " · {$condition}" : '')
            .(is_string($summary) && $summary !== '' && $summary !== 'No data' ? " · {$summary}" : '');
    }

    /** One listing as the AI reads it: its title, kind, venue, time and price, as listed. */
    private function eventLine(array $event): string
    {
        $start = CarbonImmutable::parse($event['occurrence_start'])->setTimezone('Europe/Berlin');
        $end = $event['occurrence_end'] !== null ? CarbonImmutable::parse($event['occurrence_end'])->setTimezone('Europe/Berlin') : null;
        $when = ! $event['time_listed'] ? 'today, time not listed'
            : 'today '.$start->format('H:i').($end !== null && $end->isSameDay($start) ? '–'.$end->format('H:i') : '');
        $venue = trim(($event['venue']['name'] ?? '').(filled($event['venue']['veedel'] ?? null) ? ", {$event['venue']['veedel']}" : ''), ', ');

        return "\"{$event['title']}\" ({$event['category']})".($venue !== '' ? " at {$venue}" : '').". {$when}."
            .(filled($event['price_text'] ?? null) ? " Price: {$event['price_text']}." : '');
    }

    /**
     * The person's own plans and recorded appointments still to come today, then
     * tomorrow's, nearest first, as their records hold them.
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @return list<array{label: string, value: string, known: bool}>
     */
    private function day(User $user, array $plans, CarbonImmutable $now): array
    {
        $tomorrow = $now->addDay()->toDateString();
        $items = collect($plans)->only([$now->toDateString(), $tomorrow])->flatMap(fn (array $plan): array => $plan['slots'] ?? [])
            ->map(fn (array $slot): array => ['start' => CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin'),
                'end' => isset($slot['end_at']) ? CarbonImmutable::parse($slot['end_at']) : null, 'title' => (string) $slot['name']]);
        try {
            $recorded = $this->appointments->for($user, $now->startOfDay(), $now->startOfDay()->addDays(2));
        } catch (Throwable) {
            $recorded = [];
        }
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
        try {
            $lines = array_map('strval', $this->lines->getRelevantLines($user)['lines']->all());
            $disruptions = $lines === [] ? [] : $this->disruptions->getLineDisruptions();
        } catch (Throwable) {
            return [];
        }

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
        try {
            $deadline = $this->paperwork->for($user)['deadline'];
        } catch (Throwable) {
            return null;
        }
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
            'source' => ['label' => 'Paperwork', 'href' => $deadline['occurrence_key'] !== null
                ? '/bureaucracy?view=detail&process='.rawurlencode((string) $deadline['occurrence_key']) : '/bureaucracy'],
        ];
    }

    private function key(User $user, string $text): string
    {
        return "composer:around:{$user->id}:".hash('sha256', trim($text));
    }
}
