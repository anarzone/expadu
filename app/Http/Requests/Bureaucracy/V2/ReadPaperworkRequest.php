<?php

namespace App\Http\Requests\Bureaucracy\V2;

class ReadPaperworkRequest extends EvidencePersonRequest
{
    public function rules(): array
    {
        return ['jurisdiction' => ['required', 'string', 'max:80']];
    }
}
