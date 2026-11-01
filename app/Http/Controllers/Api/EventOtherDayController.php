<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventCategory;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Support\EventEvidence;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/events/other-day?date=&q=&category=&area=&free=&saved=
 *
 * When the Events page's search or filters match nothing on the chosen
 * day, the nearest other day (60 days either way, the later one on a tie)
 * where they do, so the no-match message can point there. Matches as the
 * page does: every search word in the title, venue, area or category.
 */
class EventOtherDayController extends Controller
{
    private const REACH_DAYS = 60;

    public function __invoke(Request $request, EventOccurrencePresenter $presenter): JsonResponse
    {
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:40'],
            'area' => ['nullable', 'string', 'max:100'],
            'free' => ['nullable', 'boolean'],
            'saved' => ['nullable', 'boolean'],
            'no_german' => ['nullable', 'boolean'],
        ]);

        $day = CarbonImmutable::parse($input['date'], 'Europe/Berlin');
        $words = preg_split('/\s+/u', mb_strtolower(trim($input['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Only the person's own bookmarks, keyed as the page keys them.
        $saved = ($input['saved'] ?? false)
            ? EventBookmark::query()->where('user_id', $request->user()->id)->get()
                ->mapWithKeys(fn (EventBookmark $b) => [$b->event_id.'|'.$b->occurrence_start->getTimestamp() => true])
            : null;

        $counts = Event::occurringBetween($day->subDays(self::REACH_DAYS)->startOfDay(), $day->addDays(self::REACH_DAYS)->endOfDay())
            ->filter(function (array $o) use ($input, $words, $saved, $presenter): bool {
                $event = $o['event'];
                $category = EventCategory::fromLegacy($event->category);

                if (($input['category'] ?? null) && $category->value !== $input['category']) {
                    return false;
                }

                if (($input['area'] ?? null) && $event->venue?->veedel !== $input['area']) {
                    return false;
                }

                if (($input['free'] ?? false) && ! in_array('free', $presenter->chips($event), true)) {
                    return false;
                }

                if (($input['no_german'] ?? false) && EventEvidence::noGerman(
                    $event->source === 'manual' ? $event->language : EventEvidence::language($event->language, EventEvidence::source($event)),
                    $event->language,
                    $category,
                ) === null) {
                    return false;
                }

                if ($saved !== null && ! $saved->has($event->id.'|'.$o['starts_at']->getTimestamp())) {
                    return false;
                }

                $hay = mb_strtolower(implode(' ', array_filter([
                    $event->title_en ?: $event->title,
                    $event->title,
                    $presenter->venueName($event->venue->name ?? $event->location_name),
                    $event->venue?->veedel,
                    $category->label(),
                ])));

                foreach ($words as $word) {
                    if (! str_contains($hay, $word)) {
                        return false;
                    }
                }

                return true;
            })
            ->countBy(fn (array $o) => $o['starts_at']->setTimezone('Europe/Berlin')->toDateString())
            ->except($day->toDateString());

        $nearest = $counts->keys()
            ->sortBy(fn (string $date) => [abs($day->diffInDays(CarbonImmutable::parse($date, 'Europe/Berlin'), false)), $date < $day->toDateString() ? 1 : 0])
            ->first();

        return response()->json([
            'date' => $nearest,
            'count' => $nearest ? $counts[$nearest] : 0,
        ]);
    }
}
