<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Getting there: where to, from where the phone is, and when it starts. */
class JourneyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_name' => ['required', 'string', 'max:200'],
            // Where the phone is; without it, where the server last knew them to be.
            'from_lat' => ['nullable', 'required_with:from_lng', 'numeric', 'between:-90,90'],
            'from_lng' => ['nullable', 'required_with:from_lat', 'numeric', 'between:-180,180'],
            // When it starts: the ways are planned to be there five minutes before.
            'starts_at' => ['nullable', 'date'],
        ];
    }
}
