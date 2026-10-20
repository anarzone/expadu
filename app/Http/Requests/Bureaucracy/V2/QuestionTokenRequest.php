<?php

namespace App\Http\Requests\Bureaucracy\V2;

class QuestionTokenRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64']];
    }
}
