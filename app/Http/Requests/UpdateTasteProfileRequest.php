<?php

namespace App\Http\Requests;

use App\Models\TasteProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The person's own wording of their taste profile (plain text, at most five
 * sentences) and the tags they don't want shown. Null or an empty list of
 * sentences goes back to the written ones.
 */
class UpdateTasteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->personalises();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tags = $this->user() !== null ? (TasteProfile::for($this->user())?->tags ?? []) : [];

        return [
            'sentences' => ['sometimes', 'nullable', 'array', 'max:5'],
            // Plain text: no markup and no control characters.
            'sentences.*' => ['required', 'string', 'max:200', 'not_regex:/[<>]/', 'not_regex:/\p{Cc}/u'],
            'hidden_tags' => ['sometimes', 'array', 'max:20'],
            'hidden_tags.*' => ['required', 'string', 'distinct', Rule::in($tags)],
        ];
    }
}
