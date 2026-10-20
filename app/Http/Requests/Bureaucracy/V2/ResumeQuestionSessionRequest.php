<?php

namespace App\Http\Requests\Bureaucracy\V2;

class ResumeQuestionSessionRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return ['revisit_deferred' => ['required', 'boolean']];
    }
}
