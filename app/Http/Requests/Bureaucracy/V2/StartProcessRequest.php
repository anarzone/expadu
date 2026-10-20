<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;

class StartProcessRequest extends QuestionPreviewRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && app(PersonAccess::class)->allows($this->user(), $this->route('person'), AccessScope::ManageProcess);
    }

    public function rules(): array
    {
        return [...parent::rules(), 'occurrence_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'review_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'request_id' => ['required', 'uuid']];
    }
}
