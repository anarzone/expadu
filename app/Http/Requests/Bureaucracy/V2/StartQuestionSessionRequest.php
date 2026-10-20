<?php

namespace App\Http\Requests\Bureaucracy\V2;

class StartQuestionSessionRequest extends QuestionPreviewRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()?->can('update', $this->route('person')) === true;
    }

    public function rules(): array
    {
        return [...parent::rules(), 'request_id' => ['required', 'uuid']];
    }
}
