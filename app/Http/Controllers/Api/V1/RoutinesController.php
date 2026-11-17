<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Personalisation\Routines;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
