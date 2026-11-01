<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Validation\Rule;

class RecordEvidenceRequest extends EvidencePersonRequest
{
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])], 'details' => ['required', 'array:label,kind,reported_available,expires_on,requirement_refs'],
            'details.label' => ['required', 'string', 'max:160'], 'details.kind' => ['required', 'string', 'max:100'],
            'details.reported_available' => ['required', 'boolean'], 'details.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'details.requirement_refs' => ['sometimes', 'array', 'list', 'max:50'], 'details.requirement_refs.*' => ['string', 'max:200']];
    }
}
