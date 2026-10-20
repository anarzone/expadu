<?php

namespace App\Http\Requests\Bureaucracy\V2;

class ReviewProcessChangesRequest extends ProcessCommandRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'confirmed' => ['required', 'accepted'],
            'review_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'bind_occurrence' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }
}
