<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Analyser\AskCaseQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\AskCaseQuestionRequest;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;

class CaseQuestionController extends Controller
{
    /** The question travels in the body only; it is never logged or put in a URL. */
    public function __invoke(AskCaseQuestionRequest $request, BureaucracyPerson $person, AskCaseQuestion $ask): JsonResponse
    {
        return response()->json($ask->execute($request->user(), $person, $request->validated('question'),
            $request->validated('jurisdiction'), $request->validated('processing')))->header('Cache-Control', 'private, no-store');
    }
}
