<?php

namespace App\Http\Controllers\Bureaucracy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\UpdateAiConsentRequest;
use App\Models\BureaucracyCase;
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
        $case->update($consented
            ? ['ai_consent_at' => now(), 'ai_consent_withdrawn_at' => null]
            : ['ai_consent_withdrawn_at' => now()]);

        return response()->json(['consented' => $consented]);
    }
}
