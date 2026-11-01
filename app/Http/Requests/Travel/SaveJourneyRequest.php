<?php

namespace App\Http\Requests\Travel;

use Illuminate\Foundation\Http\FormRequest;

class SaveJourneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'mode' => ['required', 'in:all,transit,bike,walk'],
            'origin' => ['nullable', 'array:name,lat,lng,id'],
            'origin.name' => ['required_with:origin', 'string', 'max:255'],
            'origin.lat' => ['required_with:origin', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required_with:origin', 'numeric', 'between:-180,180'],
            'origin.id' => ['nullable', 'string', 'max:255'],
            'destination' => ['required', 'array:name,lat,lng,id'],
            'destination.name' => ['required', 'string', 'max:255'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.id' => ['nullable', 'string', 'max:255'],
            'journey' => ['prohibited'],
            'depart_at' => ['prohibited'],
            'arrive_by' => ['prohibited'],
        ];
    }
}
