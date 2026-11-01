<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTasteProfileRequest;
use App\Models\TasteProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /api/taste-profile: the person's nightly taste profile, which they can read,
 * reword, trim tags from or delete. Only with the personalisation opt-in.
 *
 * GET    → {data: {sentences, tags, hidden_tags, edited, generated_at} | null}
 * PUT    {sentences?: string[] | null, hidden_tags?: string[]} → same shape
 * DELETE → 204
 */
class TasteProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->personalises(), 403);

        return response()->json(['data' => TasteProfile::for($request->user())?->toApi()]);
    }

    public function update(UpdateTasteProfileRequest $request): JsonResponse
    {
        $profile = TasteProfile::for($request->user());
        abort_if($profile === null, 404);

        if ($request->has('sentences')) {
            $sentences = array_values(array_map(fn (string $sentence) => trim($sentence), (array) $request->validated('sentences')));
            $profile->edited_sentences = $sentences === [] ? null : $sentences;
        }
        if ($request->has('hidden_tags')) {
            $profile->hidden_tags = array_values((array) $request->validated('hidden_tags'));
        }
        $profile->save();

        return response()->json(['data' => $profile->toApi()]);
    }

    public function destroy(Request $request): Response
    {
        abort_unless($request->user()->personalises(), 403);

        TasteProfile::for($request->user())?->delete();

        return response()->noContent();
    }
}
