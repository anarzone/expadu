<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\Evidence\ShareEvidence;
use Illuminate\Validation\Rule;

class ShareEvidenceRequest extends EvidenceOwnerRequest
{
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'evidence_version' => ['required', 'integer', 'min:1'],
            'process_id' => ['required', 'integer', 'min:1'], 'requirement_id' => ['required', 'string', 'max:200'],
            'requirement_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'expires_on' => ['required', 'date_format:Y-m-d'],
            'notice_version' => ['required', Rule::in([ShareEvidence::NoticeVersion])], 'confirmed' => ['required', 'accepted']];
    }
}
