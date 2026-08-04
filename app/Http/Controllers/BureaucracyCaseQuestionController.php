<?php

namespace App\Http\Controllers;

use App\Bureaucracy\Cases\AnswerCaseQuestion;
use App\Http\Requests\AnswerBureaucracyCaseQuestionRequest;
use App\Models\BureaucracyCaseQuestion;
use Illuminate\Http\RedirectResponse;

class BureaucracyCaseQuestionController extends Controller
{
    public function __invoke(
        AnswerBureaucracyCaseQuestionRequest $request,
        BureaucracyCaseQuestion $question,
        AnswerCaseQuestion $answerCaseQuestion,
    ): RedirectResponse {
        $conflict = $answerCaseQuestion->answer(
            $request->user(),
            $question,
            $request->validated('value'),
        );

        if ($conflict !== null) {
            return back()->withErrors([
                'value' => 'This answer differs from information you previously confirmed. Review both values before changing your case.',
            ]);
        }

        return back();
    }
}
