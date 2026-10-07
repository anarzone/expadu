<?php

namespace App\Http\Requests;

use App\Privacy\ProcessingAcceptanceRules;
use Illuminate\Foundation\Http\FormRequest;

class ParseComposerPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:500'], ...ProcessingAcceptanceRules::rules()];
    }
}
