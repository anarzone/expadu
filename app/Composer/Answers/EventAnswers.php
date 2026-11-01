<?php

namespace App\Composer\Answers;

use App\Exceptions\CologneBoundaryUnavailable;
use App\Models\Event;
use App\Services\CologneServiceArea;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Listings as Composer shows them: Events' posters, in time order, still to come. The AI
 * relevance score only orders them, it never hides one (owner, 21 Oct 2026).
 */
class EventAnswers
{
    public function __construct(private EventOccurrencePresenter $presenter, private CologneServiceArea $area) {}

    /**
     * What's still on today, the listings Events would put first, as posters (Composer's
     * "around you now").
     *
     * @return list<array<string, mixed>>
     */
    public function stillOn(CarbonImmutable $now, int $limit): array
    {
        $now = $now->setTimezone('Europe/Berlin');

        return $this->posters($this->stillToCome(Event::occurringBetween($now->startOfDay(), $now->endOfDay()), $now), $limit);
    }

    /**
     * The first few listings as Events' posters, in time order.
     *
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return list<array<string, mixed>>
     */
    public function posters(Collection $occurrences, int $limit): array
    {
        // Ranked, never filtered: the listings Events would put first come first.
        $shown = $occurrences
            ->sortByDesc(fn (array $occurrence): array => [$occurrence['event']->relevance ?? -1, $occurrence['event']->quality_score ?? -1])
            ->take($limit);
        $plannable = $this->plannable($shown);

        return $shown
            ->sortBy(fn (array $occurrence): int => $occurrence['starts_at']->getTimestamp())
            ->map(fn (array $occurrence): array => $this->poster($occurrence, $plannable))
            ->values()->all();
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
