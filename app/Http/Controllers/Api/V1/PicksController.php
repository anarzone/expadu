<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Personalisation\EventPicks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "What would you go to?": the kinds of event they picked themselves, on Today and on What Expadu
 * knows (EventPicks). Each tap saves the whole set; Done or Not now says they're done picking.
 */
class PicksController extends Controller
{
    /**
     * Their picks as they are now. Refused (422) when personalisation isn't on under the wording
     * that names picks.
     */
    public function update(Request $request, EventPicks $picks): JsonResponse
    {
        $validated = $request->validate([
            'kinds' => ['present', 'array', 'max:'.count(EventPicks::kinds())],
            'kinds.*' => ['string', 'distinct', Rule::in(EventPicks::kinds())],
            'done' => ['required', 'boolean'],
        ]);
        $saved = $picks->save($request->user(), $validated['kinds'], $validated['done']);
        if ($saved === null) {
            return response()->json(['message' => 'Turn on personalisation first.'], 422);
        }

        return response()->json([
            /** @var array{kinds: list<string>, done: bool} */
            'picks' => $saved,
        ]);
    }
}
