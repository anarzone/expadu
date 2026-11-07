<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** I'm on my way, on one of the ways Getting there planned: named by its plan and place in it. */
class JourneyStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'uuid'],
            'way' => ['required', 'integer', 'min:0', 'max:20'],
            // Starting while another trip runs replaces it only when the person said so.
            'replace' => ['sometimes', 'boolean'],
        ];
    }
}
