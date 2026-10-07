<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Questions\AnswerQuestion;
use App\Bureaucracy\Questions\DeferQuestion;
use App\Bureaucracy\Questions\OfferNextQuestion;
use App\Bureaucracy\Questions\QuestionSessions;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\NextQuestionRequest;
use App\Http\Requests\Bureaucracy\V2\QuestionPreviewRequest;
use App\Http\Requests\Bureaucracy\V2\QuestionTokenRequest;
use App\Http\Requests\Bureaucracy\V2\ResumeQuestionSessionRequest;
use App\Http\Requests\Bureaucracy\V2\StartQuestionSessionRequest;
use App\Http\Requests\Bureaucracy\V2\SubmitQuestionAnswerRequest;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyQuestionSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class QuestionSessionController extends Controller
{
    public function preview(QuestionPreviewRequest $request, BureaucracyPerson $person, PlanReadModel $plans): JsonResponse
    {
        $plan = $plans->for($request->user(), $person, $request->validated('jurisdiction'));

        return response()->json([...$plan['questions'], 'assessment_revision' => $plan['assessment_revision']])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StartQuestionSessionRequest $request, BureaucracyPerson $person, QuestionSessions $sessions): JsonResponse
    {
        $session = $sessions->start($request->user(), $person, $request->validated('jurisdiction'), $request->validated('request_id'));

        return response()->json(['session_id' => $session->id, 'status' => $session->status, 'expires_at' => $session->expires_at->toIso8601String()], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function next(NextQuestionRequest $request, BureaucracyQuestionSession $session, OfferNextQuestion $next): JsonResponse
    {
        return response()->json($next->execute($request->user(), $session, $request->validated('request_id')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function answer(SubmitQuestionAnswerRequest $request, BureaucracyQuestionSession $session, int $question, AnswerQuestion $answers): JsonResponse
    {
        $data = $request->validated();

        return response()->json($answers->execute($request->user(), $session, $question, $data['token'], $data['value'],
            $data['answer_state'] ?? 'value', $data['operation'] ?? 'assert', $data['effective_from'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function defer(QuestionTokenRequest $request, BureaucracyQuestionSession $session, int $question, DeferQuestion $defer): Response
    {
        $defer->execute($request->user(), $session, $question, $request->validated('token'));

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function resume(ResumeQuestionSessionRequest $request, BureaucracyQuestionSession $session, QuestionSessions $sessions): Response
    {
        $sessions->resume($request->user(), $session, (bool) $request->validated('revisit_deferred'));

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
