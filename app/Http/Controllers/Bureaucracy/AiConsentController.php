<?php

namespace App\Http\Controllers\Bureaucracy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\UpdateAiConsentRequest;
use App\Models\BureaucracyCase;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class AiConsentController extends Controller
{
    public function __invoke(UpdateAiConsentRequest $request): JsonResponse
    {
        $case = $request->user()?->bureaucracyCase()->where('status', 'active')->first();

        if (! $case instanceof BureaucracyCase) {
            throw new AuthorizationException;
        }

        $consented = (bool) $request->validated('consent');
        if ($consented) {
            return response()->json(['message' => 'Permission must accompany one specific text request.'], 422);
        }
        app(ProcessingConsentStore::class)->withdraw($request->user(), $case);
        $case->update(['ai_consent_withdrawn_at' => now()]);

        return response()->json(['consented' => $consented]);
    }
}
