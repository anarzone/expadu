<?php

namespace App\Http\Requests;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Http\Requests\Concerns\StoredJourney;
use App\Models\ActiveTrip;
use App\Transit\DepartureTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartTripRequest extends FormRequest
{
    private ?ActiveTrip $resume = null;

    private ?ActiveTrip $other = null;

    private ?DayItem $plan = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            ...StoredJourney::rules(),
            'destination' => ['required', 'array'],
            'destination.name' => ['required', 'string', 'max:200'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.emoji' => ['nullable', 'string', 'max:16'],
            'origin' => ['nullable', 'array'],
            'origin.name' => ['nullable', 'string', 'max:200'],
            'origin.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin.lng' => ['nullable', 'numeric', 'between:-180,180'],
            // Starting this trip while another runs replaces it only when the
            // person said so ("Replace the trip you're on?").
            'replace' => ['sometimes', 'boolean'],
            // The plan on the person's own day this trip is for (Leave now,
            // Plan a trip): checked against their day, never taken as sent.
            'plan' => ['sometimes', 'nullable', 'array'],
            'plan.date' => ['required_with:plan', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'plan.target_id' => ['required_with:plan', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $journey = $this->input('journey');
            if (strlen((string) json_encode($journey)) > StoredJourney::MAX_BYTES) {
                $validator->errors()->add('journey', 'This route is too large to start. Find a fresh route.');

                return;
            }
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
            if ($this->filled('plan')) {
                $this->plan = collect(app(DayProjection::class)->itemsOn($this->user(), $this->input('plan.date')))
                    ->first(fn (DayItem $item) => $item->id === $this->input('plan.target_id') && $item->startAt !== null);
                if ($this->plan === null) {
                    $validator->errors()->add('plan', 'That plan isn’t on your day.');

                    return;
                }
            }
            $this->resume = $same ? $active : null;
            $this->other = ! $same && $active !== null && ! $active->isOver() ? $active : null;
        }];
    }

    /** Another trip is running and this one would replace it. */
    public function replacesTrip(): ?ActiveTrip
    {
        return $this->other;
    }

    public function resumableTrip(): ?ActiveTrip
    {
        return $this->resume;
    }

    /**
     * The plan as the server knows it, stored with the trip.
     *
     * @return array{id: string, title: string, label: ?string, kind: string, official: bool, start_at: string}|null
     */
    public function target(): ?array
    {
        return $this->plan === null ? null : [
            'id' => $this->plan->id,
            'title' => mb_substr($this->plan->title, 0, 200),
            'label' => $this->plan->locationLabel === null ? null : mb_substr($this->plan->locationLabel, 0, 200),
            'kind' => $this->plan->kind,
            'official' => $this->plan->official,
            'start_at' => $this->plan->startAt->setTimezone('Europe/Berlin')->toIso8601String(),
        ];
    }
}
