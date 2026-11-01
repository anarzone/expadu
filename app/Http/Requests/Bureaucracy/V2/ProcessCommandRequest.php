<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use Illuminate\Foundation\Http\FormRequest;

class ProcessCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        $person = $this->route('process')->dossier->person;
        app(PersonAccess::class)->authorize($this->user(), $person, AccessScope::ManageProcess);
        app(PersonAccess::class)->authorize($this->user(), $person, AccessScope::ViewPlan);

        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']];
    }
}
