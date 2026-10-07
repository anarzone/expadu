<?php

namespace App\Http\Requests\Bureaucracy\V2;

class ResolveFactConflictRequest extends RecordFactChangeRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('viewFacts', $this->route('person'));
    }

    public function rules(): array
    {
        return [...parent::rules(), 'confirmed' => ['required', 'accepted'],
            'review_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'request_id' => ['required', 'uuid'],
            'effective_from' => ['prohibited']];
    }
}
