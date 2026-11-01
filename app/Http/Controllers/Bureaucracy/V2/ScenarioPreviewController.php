<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\QA\ScenarioAssessmentPreview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\ScenarioPreviewRequest;
use Illuminate\Http\JsonResponse;

class ScenarioPreviewController extends Controller
{
    public function __invoke(ScenarioPreviewRequest $request, string $persona, ScenarioAssessmentPreview $preview): JsonResponse
    {
        $result = $preview->forKey($persona, $request->validated('jurisdiction'));
        abort_if($result === null, 404);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
