<?php

namespace App\Http\Controllers\Api\V1;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Day\PlannedTrips;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TodayWayRequest;
use App\Http\Requests\StartTripRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Leave card's trip from the iPhone. The phone names a way by what it is (on foot, by bike, or
 * which ride); the server works the day out again from where the phone is and takes the journey
 * from its own answer, never from the phone.
 * Starting goes through the web's own checks (StartTripRequest, TripController).
 */
class TodayTripController extends Controller
{
    /** How long Undo can put back the way a plan had. */
    private const UNDO_SECONDS = 600;

    /** I'm on my way: the trip starts on that way. A trip already running asks first (409). */
    public function start(TodayWayRequest $request, DayProjection $days): JsonResponse
    {
        $input = $request->validated();
        [$trip, $journey, $date] = $this->way($request, $days, $input);
        $item = $this->item($request, $days, $date, (string) $trip['target']['id']);

        $start = StartTripRequest::createFrom($request);
        $start->replace([
            'journey' => $journey,
            'destination' => ['name' => mb_substr($item->title, 0, 200), 'lat' => $item->lat, 'lng' => $item->lng],
            'origin' => isset($trip['from']['lat'], $trip['from']['lng'])
                ? ['name' => $trip['from']['label'] ?? null, 'lat' => $trip['from']['lat'], 'lng' => $trip['from']['lng']]
                : null,
            'plan' => ['date' => $date, 'target_id' => $item->id],
            'replace' => (bool) ($input['replace'] ?? false),
        ]);
        $start->setContainer(app())->setRedirector(app(Redirector::class));
        $start->validateResolved();

        return app(TripController::class)->start($start);
    }

    /** I'm there, or the trip is over: it ends. */
    public function end(Request $request): JsonResponse
    {
        return app(TripController::class)->end($request);
    }

    /** Use this (Other ways): the plan keeps that way; the card shows it from now on. */
    public function keep(TodayWayRequest $request, DayProjection $days, PlannedTrips $planned): JsonResponse
    {
        $input = $request->validated();
        [$trip, $journey, $date] = $this->way($request, $days, $input);
        $item = $this->item($request, $days, $date, (string) $trip['target']['id']);
        $user = $request->user();

        $before = $planned->for($user, $date, $item->id);
        $undo = (string) Str::uuid();
        Cache::put($this->undoKey($user->id, $undo), [
            'date' => $date, 'plan' => $item->id, 'snapshot' => $before === null ? null : $planned->snapshot($before),
        ], self::UNDO_SECONDS);
        $planned->keep($user, $date, $item, $journey, (bool) ($before?->remind ?? false));

        return response()->json(['undo' => $undo]);
    }

    /** Undo Use this: the way the plan had before, or none. */
    public function undo(Request $request, PlannedTrips $planned, string $undo): JsonResponse
    {
        $user = $request->user();
        $was = Cache::pull($this->undoKey($user->id, $undo));
        abort_if(! is_array($was), 410, 'That can’t be undone any more.');
        $was['snapshot'] === null
            ? $planned->forget($user, $was['date'], $was['plan'])
            : $planned->restore($user, $was['date'], $was['plan'], $was['snapshot']);

        return response()->json(['undone' => true]);
    }

    /**
     * The day's next trip as the server works it out now, and the journey of the way asked for.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function way(Request $request, DayProjection $days, array $input): array
    {
        $date = CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d');
        $trip = $days->for($request->user(), $date, $request)['next_trip'] ?? null;
        $journeys = $trip === null || ! is_array($trip['journey'] ?? null) ? [] : [
            $trip['journey'],
            ...array_map(fn (array $way) => $way['journey'] ?? null, $trip['alternatives'] ?? []),
        ];
        $journey = collect($journeys)->first(fn ($journey) => is_array($journey)
            && ($journey['mode'] ?? null) === $input['mode'] && self::departs($journey) === ($input['departs'] ?? null));
        if ($journey === null) {
            throw ValidationException::withMessages(['way' => 'That way isn’t there any more. Pull to check again.']);
        }

        return [$trip, $journey, $date];
    }

    /**
     * When the way's first ride leaves; null on foot or by bike.
     *
     * @param  array<string, mixed>  $journey
     */
    private static function departs(array $journey): ?string
    {
        $ride = collect($journey['legs'] ?? [])->first(fn ($leg) => is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true));

        return is_array($ride) ? ($ride['depart_time'] ?? null) : null;
    }

    private function item(Request $request, DayProjection $days, string $date, string $id): DayItem
    {
        $item = collect($days->itemsOn($request->user(), $date))->first(fn (DayItem $item) => $item->id === $id);
        if ($item === null || $item->lat === null || $item->lng === null) {
            throw ValidationException::withMessages(['way' => 'That plan has no place to go to.']);
        }

        return $item;
    }

    private function undoKey(int $user, string $undo): string
    {
        return "today-way-undo:{$user}:{$undo}";
    }
}
