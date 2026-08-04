<?php

namespace App\Http\Controllers\Bureaucracy;

use App\Bureaucracy\Ai\ExtractCaseFactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\StoreCaseMessageRequest;
use Illuminate\Http\JsonResponse;

class CaseMessageController extends Controller
{
    public function __invoke(
        StoreCaseMessageRequest $request,
        ExtractCaseFactAction $extractCaseFact,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $extractCaseFact->execute(
            $request->user(),
            (int) $validated['question_id'],
            $validated['message'],
        );

        return response()->json($result, $result['outcome'] === 'limited' ? 429 : 200);
    }
}
