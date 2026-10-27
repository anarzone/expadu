<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlaceWorkAnswer;
use App\Models\Spot;
use App\Places\PlaceWorkAnswers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Good for laptop work?" on a place's sheet (docs/places/design/laptop-answers.md).
 *
 *   GET    api/places/{spot}/work-answer   their own answer, whether they may answer, the place's totals
 *   PUT    api/places/{spot}/work-answer   answer or change it (Been there or Saved only)
 *   DELETE api/places/{spot}/work-answer   withdraw it
 *   GET    api/places/work-answers         all their own answers (their data, for the download)
 *
 * Totals carry counts only; no one else's answers are ever returned.
 *
 * Limits (limit()): 20 new answers a day, and 10 changes a place a day. Adding
 * the follow-up chips is part of the answer, and an Undo of their own last
 * change within 10 minutes isn't counted.
 */
class PlaceWorkAnswerController extends Controller
{
    public const NEW_PER_DAY = 20;

    public const CHANGES_PER_PLACE_PER_DAY = 10;

    public const UNDO_MINUTES = 10;

    public function __construct(private PlaceWorkAnswers $answers) {}

    public function show(Request $request, Spot $spot): JsonResponse
    {
        return response()->json($this->state($request, $spot));
    }

    public function update(Request $request, Spot $spot): JsonResponse
    {
        $validated = $request->validate([
            'laptop' => ['required', 'string', 'in:yes,no'],
            'wifi' => ['nullable', 'string', 'in:yes,no'],
            'sockets' => ['nullable', 'string', 'in:yes,no'],
            'quiet' => ['nullable', 'string', 'in:yes,no'],
        ]);
        abort_unless($this->answers->canAnswer($request->user(), $spot), 403, 'Mark the place Been there or Saved first.');
        $before = $this->answerArray($this->answers->answerOf($request->user(), $spot));
        $after = ['laptop' => $validated['laptop'], 'wifi' => $validated['wifi'] ?? null, 'sockets' => $validated['sockets'] ?? null, 'quiet' => $validated['quiet'] ?? null];
        if ($before !== $after) {
            $this->limit($request, $spot, $before, $after);
            $this->answers->save($request->user(), $spot, $validated);
        }

        return response()->json($this->state($request, $spot));
    }

    public function destroy(Request $request, Spot $spot): JsonResponse
    {
        $before = $this->answerArray($this->answers->answerOf($request->user(), $spot));
        if ($before !== null) {
            $this->limit($request, $spot, $before, null);
            $this->answers->withdraw($request->user(), $spot);
        }

        return response()->json($this->state($request, $spot));
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['answers' => PlaceWorkAnswer::query()->with('spot:id,name')
            ->where('user_id', $request->user()->id)->orderByDesc('answered_at')->get()
            ->map(fn (PlaceWorkAnswer $a): array => [
                'place' => ['id' => $a->spot_id, 'name' => $a->spot?->name],
                'laptop' => $a->laptop, 'wifi' => $a->wifi, 'sockets' => $a->sockets, 'quiet' => $a->quiet,
                'answered_at' => $a->answered_at->toIso8601String(),
            ])->values()]);
    }

    /**
     * Counts this change against the day's limits (429 when over), then
     * remembers it so the next request can be recognised as its Undo.
     *
     * @param  array<string, ?string>|null  $before
     * @param  array<string, ?string>|null  $after
     */
    private function limit(Request $request, Spot $spot, ?array $before, ?array $after): void
    {
        $user = $request->user()->getAuthIdentifier();
        $place = $user.':'.$this->answers->canonical($spot);
        $last = Cache::get('place-answers:last:'.$place);
        $undo = is_string($last) && json_decode($last, true) === ['restores' => $after];
        $chips = $before !== null && $after !== null && $before['laptop'] === $after['laptop'];

        $keys = array_filter([
            $before === null && ! $undo ? ['place-answers:new:'.$user, self::NEW_PER_DAY] : null,
            ! $undo && ! $chips ? ['place-answers:place:'.$place, self::CHANGES_PER_PLACE_PER_DAY] : null,
        ]);
        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                abort(429, 'Too many changes today.', ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }
        foreach ($keys as [$key]) {
            RateLimiter::hit($key, 86400);
        }

        // Its Undo puts back what was there; an Undo itself can't be undone for free.
        $undo
            ? Cache::forget('place-answers:last:'.$place)
            : Cache::put('place-answers:last:'.$place, json_encode(['restores' => $before]), now()->addMinutes(self::UNDO_MINUTES));
    }

    /** @return array{laptop: string, wifi: ?string, sockets: ?string, quiet: ?string}|null */
    private function answerArray(?PlaceWorkAnswer $answer): ?array
    {
        return $answer === null ? null : ['laptop' => $answer->laptop, 'wifi' => $answer->wifi, 'sockets' => $answer->sockets, 'quiet' => $answer->quiet];
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Spot $spot): array
    {
        $own = $this->answers->answerOf($request->user(), $spot);
        $totals = $this->answers->totals((int) ($own?->spot_id ?? $spot->id));

        return [
            'can_answer' => $this->answers->canAnswer($request->user(), $spot),
            'answer' => $own === null ? null : ['laptop' => $own->laptop, 'wifi' => $own->wifi, 'sockets' => $own->sockets, 'quiet' => $own->quiet, 'answered_at' => $own->answered_at->toIso8601String()],
            // Shown only from 3 people: below that, null ("Not listed yet").
            'totals' => array_map(fn (?array $t): ?array => $t === null ? null : ['value' => $t['value'], 'yes' => $t['yes'], 'total' => $t['total']], $totals),
        ];
    }
}
