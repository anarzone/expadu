<?php

namespace App\Http\Requests;

use App\Models\ActiveTrip;
use App\Transit\DepartureTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartTripRequest extends FormRequest
{
    private ?ActiveTrip $resume = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'journey' => ['required', 'array'],
            'journey.depart_at' => ['required', 'string', 'max:40'],
            'journey.arrive_at' => ['required', 'string', 'max:40'],
            'journey.legs' => ['required', 'array', 'min:1', 'max:40'],
            'journey.legs.*' => ['required', 'array'],
            'journey.legs.*.mode' => ['required', 'string', 'max:40'],
            'journey.legs.*.depart_at' => ['required', 'string', 'max:40'],
            'journey.legs.*.arrive_at' => ['required', 'string', 'max:40'],
            'journey.legs.*.to.name' => ['sometimes', 'nullable', 'string', 'max:300'],
            'destination' => ['required', 'array'],
            'destination.name' => ['required', 'string', 'max:200'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.emoji' => ['nullable', 'string', 'max:16'],
            'origin' => ['nullable', 'array'],
            'origin.name' => ['nullable', 'string', 'max:200'],
            'origin.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $journey = $this->input('journey');
            $depart = DepartureTime::parse($journey['depart_at']);
            $arrive = DepartureTime::parse($journey['arrive_at']);
            if ($depart === null || $arrive === null || $arrive->lessThanOrEqualTo($depart)) {
                $validator->errors()->add('journey', 'This route has invalid times. Find a fresh route.');

                return;
            }
            if ($arrive->lessThanOrEqualTo(now())) {
                $validator->errors()->add('journey', 'This route has already ended. Find a fresh route.');

                return;
            }
            $active = $this->user()->activeTrip()->first();
            $same = $active !== null && $active->journey == $journey
                && $active->destination == $this->input('destination')
                && $active->origin == $this->input('origin');
            $previous = $depart;
            $boardingChecked = false;
            foreach ($journey['legs'] as $index => $leg) {
                $legDepart = DepartureTime::parse($leg['depart_at']);
                $legArrive = DepartureTime::parse($leg['arrive_at']);
                if ($legDepart === null || $legArrive === null || $legDepart->lessThan($previous)
                    || $legArrive->lessThan($legDepart) || $legArrive->greaterThan($arrive)) {
                    $validator->errors()->add("journey.legs.{$index}", 'A journey step has invalid times. Find a fresh route.');

                    return;
                }
                if (! $boardingChecked && ! in_array($leg['mode'], ['walk', 'bike'], true)) {
                    $boardingChecked = true;
                    if (! $same && $legDepart->lessThanOrEqualTo(now())) {
                        $validator->errors()->add('journey', 'This departure has passed. Find a fresh route before starting.');

                        return;
                    }
                }
                $previous = $legArrive;
            }
            $this->resume = $same ? $active : null;
        }];
    }

    public function resumableTrip(): ?ActiveTrip
    {
        return $this->resume;
    }
}
