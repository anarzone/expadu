<?php

namespace App\Http\Requests\Bureaucracy\V2;

class CorrectProcessEventRequest extends ProcessCommandRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'payload' => ['present', 'array', 'max:10'], 'confirmed' => ['required', 'accepted']];
    }
}
