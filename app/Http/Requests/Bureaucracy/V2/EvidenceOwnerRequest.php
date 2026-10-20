<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\PersonAccess;
use Illuminate\Foundation\Http\FormRequest;

class EvidenceOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(PersonAccess::class)->canManage($this->user(), $this->route('evidence')->person);
    }

    public function rules(): array
    {
        return [];
    }
}
