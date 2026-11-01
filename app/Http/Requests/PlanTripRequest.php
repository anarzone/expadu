<?php

namespace App\Http\Requests;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Http\Requests\Concerns\StoredJourney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "Add to plan": the way picked for one of the person's own plans on a day.
 * The plan must be theirs and on that day; the way stays a journey, bounded
 * like a started trip.
 */
class PlanTripRequest extends FormRequest
{
    private ?DayItem $item = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'target_id' => ['required', 'string', 'max:120'],
            'remind' => ['sometimes', 'boolean'],
            ...StoredJourney::rules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $problem = StoredJourney::problem($this->input('journey'));
            if ($problem !== null) {
                $validator->errors()->add('journey', $problem);

                return;
            }

            // Only the person's own plans on that day take a way.
            $this->item = collect(app(DayProjection::class)->itemsOn($this->user(), $this->input('date')))
                ->first(fn (DayItem $item) => $item->id === $this->input('target_id'));
            if ($this->item === null) {
                $validator->errors()->add('target_id', 'That plan isn’t on your day.');
            }
        }];
    }

    /** The plan the way leads to, as the server knows it. */
    public function plan(): DayItem
    {
        return $this->item;
    }
}
