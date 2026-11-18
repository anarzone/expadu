<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Personalisation\LeaveNotice;
use App\Personalisation\Routines;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Routines learned from trips, on the phone: the ones they confirmed, and the one to offer now
 * ("You often go to Work around 08:10 on weekdays. Want me to tell you when to leave?"). Offered,
 * never assumed; each theirs to change, pause or forget.
 */
class RoutinesController extends Controller
{
    public function __construct(private Routines $routines) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            /** @var bool Routine learning on: their own yes, with personalisation on. */
            'learning' => Routines::learns($user),
            /** @var bool Noticing when they leave Home or Work (LeaveNotice): its own yes, with routine learning on. */
            'noticing' => LeaveNotice::on($user),
            /** @var int Trips kept for routines (90 days at most). */
            'trips_kept' => $this->routines->tripsKept($user),
            /** @var list<array{id: int, to_label: string, from_key: string|null, weekdays: list<int>, at: string, remind: bool}> Weekdays 1 (Monday) to 7. */
            'routines' => $this->routines->confirmed($user),
            /** @var array{to_key: string, to_label: string, from_key: string|null, weekdays: list<int>, at: string, times: int, trips: list<array{date: string, weekday: int, at: string}>}|null What their trips show, to offer, with the trips it rests on (every one to that place in the last three weeks); null when nothing does. */
            'offer' => $this->routines->offer($user, CarbonImmutable::now()),
        ]);
    }

    /**
     * Yes to the offer, with the days and time as they set them.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to_key' => ['required', 'string', 'max:64'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'at' => ['required', 'date_format:H:i'],
        ]);
        $offer = $this->routines->offer($request->user(), CarbonImmutable::now());
        abort_if($offer === null || $offer['to_key'] !== $validated['to_key'], 409, 'That routine isn’t on offer any more.');
        $id = $this->routines->confirm($request->user(), $offer, array_values(array_unique($validated['weekdays'])), $validated['at']);
        abort_if($id === null, 422, 'Routines need personalisation turned on.');

        return response()->json(['id' => $id], 201);
    }

    /**
     * Its days, its time, or whether it tells them when to leave.
     */
    public function update(Request $request, int $routine): JsonResponse
    {
        $validated = $request->validate([
            'weekdays' => ['sometimes', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'at' => ['sometimes', 'date_format:H:i'],
            'remind' => ['sometimes', 'boolean'],
        ]);
        abort_unless($this->routines->change($request->user(), $routine, $validated), 404);

        return response()->json(['changed' => true]);
    }

    public function destroy(Request $request, int $routine): JsonResponse
    {
        abort_unless($this->routines->forget($request->user(), $routine), 404);

        return response()->json(['forgotten' => true]);
    }

    /**
     * Forget my trips: the trips kept for routines are erased; the routines they confirmed stay.
     * Permanent (the phone asks first).
     */
    public function forgetTrips(Request $request): JsonResponse
    {
        return response()->json([
            /** @var int How many trips were erased. */
            'forgotten' => $this->routines->forgetTrips($request->user()),
        ]);
    }

    /**
     * Not now: that offer isn't made again for a while. Undo deletes the id it gives back.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $validated = $request->validate(['to_key' => ['required', 'string', 'max:64']]);
        $id = $this->routines->dismiss($request->user(), $validated['to_key'], CarbonImmutable::now());

        return response()->json([
            'dismissed' => $id !== null,
            /** @var int|null DELETE /routines/{id} takes the no back; null when it wasn't on offer. */
            'id' => $id,
        ]);
    }

    /**
     * They just left their Home or Work: what to tell them now, when a routine of theirs runs (204
     * when none does). The phone sends only which place, never where it is.
     */
    public function leaving(Request $request, LeaveNotice $notice): JsonResponse|Response
    {
        $validated = $request->validate(['from' => ['required', 'string', 'in:home,work']]);
        $note = $notice->leaving($request->user(), $validated['from'], CarbonImmutable::now());
        if ($note === null) {
            return response()->noContent();
        }

        return response()->json([
            /** @var array{title: string, body: string, link: string} */
            'notice' => $note,
        ]);
    }

    /**
     * A trip the phone noticed, from one of their places to the other: kept for routines like one
     * started in the app. Refused (422) when noticing isn't on.
     */
    public function noticed(Request $request, LeaveNotice $notice): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'in:home,work'],
            'to' => ['required', 'string', 'in:home,work', 'different:from'],
            'left_at' => ['required', 'date', 'before_or_equal:now', 'after:-1 day'],
        ]);
        $kept = $notice->noticed($request->user(), $validated['from'], $validated['to'], CarbonImmutable::parse($validated['left_at']));

        return $kept ? response()->json(['kept' => true], 201) : response()->json(['message' => 'Turn on noticing when you leave home or work first.'], 422);
    }
}
