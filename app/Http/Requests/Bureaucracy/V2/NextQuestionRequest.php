<?php

namespace App\Http\Requests\Bureaucracy\V2;

class NextQuestionRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid']];
    }
}
