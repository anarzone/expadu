<?php

namespace App\Http\Requests;

use App\Models\BureaucracyFactConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveBureaucracyCaseConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conflict = $this->route('conflict');
        $user = $this->user();

        if (! $conflict instanceof BureaucracyFactConflict || $user === null) {
            return false;
        }

        return $user->bureaucracyCase()
            ->whereKey($conflict->case_id)
            ->exists();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'choice' => ['required', 'string', Rule::in(['existing', 'candidate'])],
        ];
    }
}
