<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Ai\ConfirmExtractedFacts;
use App\Bureaucracy\Ai\ExtractForQuestion;
use App\Bureaucracy\Ai\RejectExtractedFacts;
use App\Bureaucracy\Ai\WithdrawPersonProcessing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\ConfirmExtractedAnswerRequest;
use App\Http\Requests\Bureaucracy\V2\ExtractQuestionAnswerRequest;
use App\Http\Requests\Bureaucracy\V2\QuestionTokenRequest;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyQuestionSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FactExtractionController extends Controller
{
    public function extract(ExtractQuestionAnswerRequest $request, BureaucracyQuestionSession $session, int $question, ExtractForQuestion $extract): JsonResponse
    {
        return response()->json($extract->execute($request->user(), $session, $question, $request->validated('token'),
            $request->validated('message'), $request->validated('processing')))->header('Cache-Control', 'private, no-store');
    }

    public function confirm(ConfirmExtractedAnswerRequest $request, BureaucracyQuestionSession $session, string $candidate, ConfirmExtractedFacts $confirm): JsonResponse
    {
        return response()->json($confirm->execute($request->user(), $session, $candidate, $request->validated('token'), $request->validated('value'),
            $request->validated('request_id'), $request->validated('operation', 'assert'), $request->validated('effective_from')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function reject(QuestionTokenRequest $request, BureaucracyQuestionSession $session, string $candidate, RejectExtractedFacts $reject): Response
    {
        $reject->execute($request->user(), $session, $candidate, $request->validated('token'));

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function withdraw(Request $request, BureaucracyPerson $person, WithdrawPersonProcessing $withdraw): JsonResponse
    {
        return response()->json(['withdrawn' => true, 'requests_already_in_flight' => $withdraw->execute($request->user(), $person)])
            ->header('Cache-Control', 'private, no-store');
    }
}
