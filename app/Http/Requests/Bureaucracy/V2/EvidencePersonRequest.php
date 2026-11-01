<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use Illuminate\Foundation\Http\FormRequest;

class EvidencePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $person = $this->route('person') ?? $this->route('process')?->dossier->person;

        return $this->user() !== null && $person !== null && app(PersonAccess::class)->allows($this->user(), $person, AccessScope::ManageEvidence);
    }

    public function rules(): array
    {
        return [];
    }
}
