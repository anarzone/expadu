<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

class SaveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'min:1'],
            'occurrence_start' => ['required', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'saved' => ['required', 'boolean'],
        ];
    }
}
