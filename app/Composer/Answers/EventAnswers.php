<?php

namespace App\Composer\Answers;

use App\Exceptions\CologneBoundaryUnavailable;
use App\Models\Event;
use App\Services\CologneServiceArea;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "What's on tonight?" (the mock's respond() `event` kind): the listings still to
 * come in the window asked about, as Events lists them. The AI relevance score
 * only orders them, it never hides one (owner, 21 Oct 2026); nothing is invented
 * when the listings are empty.
 */
class EventAnswers
{
    /** Posters shown in the answer; the rest wait on Events. */
    private const SHOWN = 6;

    /** The mock's topic tests on a listing's own words. */
    private const TOPICS = [
        'music' => ['/music|concert|konzert/iu', 'concerts or live music'],
        'exhibition' => ['/exhibition|ausstellung|museum|\bart\b|kunst/iu', 'exhibitions'],
        'film' => ['/film|cinema|kino/iu', 'films'],
        'football' => ['/football|fußball|match/iu', 'football screenings'],
    ];

    private const NUMBERS = ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];

    public function __construct(private EventOccurrencePresenter $presenter, private CologneServiceArea $area) {}

    /**
     * @param  array<string, mixed>  $request  the router's event request (date, days, evening, topic)
     * @return array{text: string, extra: array<string, mixed>}
     */
    public function whatsOn(array $request, CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $day = CarbonImmutable::parse(max((string) $request['date'], $now->toDateString()), 'Europe/Berlin')->startOfDay();
        $days = max(1, (int) ($request['days'] ?? 1));
        $evening = (bool) ($request['evening'] ?? false);
        $from = $day->toDateString() === $now->toDateString() ? $now : $day;
        if ($evening) {
            $from = $from->max($day->setTime(17, 0));
        }
        $until = $day->addDays($days - 1)->endOfDay();

        $listed = $this->stillToCome(Event::occurringBetween($day, $until), $from);
        $topic = self::TOPICS[$request['topic'] ?? ''] ?? null;
        $matching = $topic === null ? $listed : $listed->filter(fn (array $occurrence): bool => (bool) preg_match($topic[0],
            $occurrence['event']->title.' '.$occurrence['event']->title_en.' '.$occurrence['event']->category.' '.$occurrence['event']->description));
        $shown = $matching->isNotEmpty() ? $matching : $listed;

        $when = match (true) {
            $days === 2 => 'this weekend',
            $days === 7 => 'this week',
            $day->toDateString() === $now->toDateString() => $evening || $now->hour >= 17 ? 'tonight' : 'today',
            default => 'on '.$day->isoFormat('dddd D MMMM'),
        };
        $browse = ['label' => 'See everything on Events', 'href' => route('events', ['date' => $day->toDateString()], false)];

        if ($listed->isEmpty()) {
            return ['text' => "Nothing more is listed {$when}.", 'extra' => ['kind' => 'event', 'suggestions' => [$browse]]];
        }

        $count = $shown->count();
        $plannable = $this->plannable($shown);
        $posters = $shown
            // Ranked, never filtered: the listings Events would put first come first.
            ->sortByDesc(fn (array $occurrence): array => [$occurrence['event']->relevance ?? -1, $occurrence['event']->quality_score ?? -1])
            ->take(self::SHOWN)
            ->sortBy(fn (array $occurrence): int => $occurrence['starts_at']->getTimestamp())
            ->map(fn (array $occurrence): array => $this->poster($occurrence, $plannable))
            ->values()->all();

        return [
            'text' => $topic !== null && $matching->isEmpty() ? "I don’t see {$topic[1]} {$when}. This is what’s on." : '',
            'extra' => [
                'kind' => 'event',
                'title' => ($count === 1 ? 'One event' : (self::NUMBERS[$count - 1] ?? (string) $count).' events')." {$when}.",
                'events' => $posters,
                'suggestions' => $count > self::SHOWN ? [$browse] : null,
            ],
        ];
    }

    /**
     * What hasn't finished: a listing that has started is gone once its listed time
     * has (Today's discovery keeps the same rule).
     *
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return Collection<int, array<string, mixed>>
     */
    private function stillToCome(Collection $occurrences, CarbonImmutable $from): Collection
    {
        return $occurrences->filter(function (array $occurrence) use ($from): bool {
            $start = $occurrence['starts_at'];
            $end = $occurrence['ends_at'];

            return ! $start->lessThan($from)
                || (! $this->presenter->timeListed($start, $end) && ($end === null || $end->greaterThan($from)));
        })->values();
    }

    /**
     * The events a plan can hold, by the catalogue's own rule (CandidateRepository): a
     * point inside Cologne and a known day. Only those offer Add to plan.
     *
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return list<int>
     */
    private function plannable(Collection $occurrences): array
    {
        try {
            $located = $this->area->eventCoordinates($occurrences->map(fn (array $occurrence): int => $occurrence['event']->id)->unique()->values()->all());
        } catch (CologneBoundaryUnavailable) {
            return [];
        }

        return $occurrences->filter(fn (array $occurrence): bool => $located->has($occurrence['event']->id)
            && ! in_array('multi-day-uncertain', $occurrence['event']->tags ?? [], true))
            ->map(fn (array $occurrence): int => $occurrence['event']->id)->values()->all();
    }

    /**
     * The fields Events' poster reads (EventOccurrencePresenter), plus what adding it
     * to a plan needs: Composer's candidate id and the day, when a plan can hold it.
     *
     * @param  array<string, mixed>  $occurrence
     * @param  list<int>  $plannable
     * @return array<string, mixed>
     */
    private function poster(array $occurrence, array $plannable): array
    {
        $card = $this->presenter->present($occurrence['event'], $occurrence['starts_at'], $occurrence['ends_at']);
        $event = $occurrence['event'];

        return [
            ...collect($card)->only(['id', 'occurrence_start', 'occurrence_end', 'title', 'title_original', 'title_lang',
                'time_listed', 'category', 'price_text', 'language'])->all(),
            'venue' => ['name' => $card['venue']['name'], 'veedel' => $card['venue']['veedel']],
            // CandidateRepository's ids: a series names its occurrence.
            'candidate' => $event->recurrence !== null ? "event:{$event->id}:{$occurrence['starts_at']->getTimestamp()}" : "event:{$event->id}",
            'date' => $occurrence['starts_at']->setTimezone('Europe/Berlin')->toDateString(),
            'plannable' => in_array($event->id, $plannable, true),
        ];
    }
}
