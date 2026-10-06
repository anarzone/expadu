<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\SaveJourneyRequest;
use App\Models\SavedJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedJourneyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => SavedJourney::query()->where('user_id', $request->user()->id)
            ->latest('updated_at')->get()->map->toShared()->all()]);
    }

    public function store(SaveJourneyRequest $request): JsonResponse
    {
        $input = $request->validated();
        $point = fn (array $value) => ['name' => trim($value['name']), 'lat' => (float) $value['lat'], 'lng' => (float) $value['lng'], 'id' => $value['id'] ?? null];
        $savedRequest = ['mode' => $input['mode'], 'origin' => empty($input['origin']) ? null : $point($input['origin']), 'destination' => $point($input['destination'])];
        $identity = fn (?array $value) => $value === null ? null : [$value['id'], round($value['lat'], 6), round($value['lng'], 6)];
        $fingerprint = hash('sha256', json_encode([$savedRequest['mode'], $identity($savedRequest['origin']), $identity($savedRequest['destination'])], JSON_THROW_ON_ERROR));
        SavedJourney::query()->upsert([
            'user_id' => $request->user()->id,
            'fingerprint' => $fingerprint,
            'title' => trim($input['title']),
            'request' => json_encode($savedRequest, JSON_THROW_ON_ERROR),
        ], ['user_id', 'fingerprint'], ['title', 'request', 'updated_at']);
        $saved = SavedJourney::query()->where('user_id', $request->user()->id)->where('fingerprint', $fingerprint)->firstOrFail();

        return response()->json(['data' => $saved->toShared()]);
    }

    public function destroy(Request $request, int $savedJourney): JsonResponse
    {
        SavedJourney::query()->where('user_id', $request->user()->id)->whereKey($savedJourney)->firstOrFail()->delete();

        return response()->json(['removed' => true]);
    }
}
