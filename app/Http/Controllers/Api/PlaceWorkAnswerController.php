<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlaceWorkAnswer;
use App\Models\Spot;
use App\Places\PlaceWorkAnswers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Good for laptop work?" on a place's sheet (docs/places/design/laptop-answers.md).
 *
 *   GET    api/places/{spot}/work-answer   their own answer, whether they may answer, the place's totals
 *   PUT    api/places/{spot}/work-answer   answer or change it (Been there or Saved only)
 *   DELETE api/places/{spot}/work-answer   withdraw it
 *   GET    api/places/work-answers         all their own answers (their data, for the download)
 *
 * Totals carry counts only; no one else's answers are ever returned.
 */
class PlaceWorkAnswerController extends Controller
{
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
        $this->answers->save($request->user(), $spot, $validated);

        return response()->json($this->state($request, $spot));
    }

    public function destroy(Request $request, Spot $spot): JsonResponse
    {
        $this->answers->withdraw($request->user(), $spot);

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
