<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** A way of the Leave card's, as Today sent it: on foot, by bike, or which ride. */
class TodayWayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The way, by what it is: on foot, by bike, or a ride and when that ride leaves (a walk's
            // leave time moves with the clock; its place in the list can change as the day is worked out again).
            'mode' => ['required', 'string', 'in:transit,walk,bike'],
            'departs' => ['nullable', 'required_if:mode,transit', 'date_format:H:i'],
            // Where the phone is: the trip starts there, as on Today.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            // Starting while another trip runs replaces it only when the person said so.
            'replace' => ['sometimes', 'boolean'],
        ];
    }
}
