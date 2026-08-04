<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBureaucracyCaseTaskDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'documents_checked' => ['required', 'array', 'max:50'],
            'documents_checked.*' => ['string', 'max:500'],
        ];
    }
}
