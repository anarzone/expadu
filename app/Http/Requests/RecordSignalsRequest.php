<?php

namespace App\Http\Requests;

use App\Personalisation\SignalAction;
use App\Personalisation\SignalSurface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A client batch for the signal ledger: allow-listed actions and surfaces,
 * catalogue item ids that exist, no free text and no coordinates.
 */
class RecordSignalsRequest extends FormRequest
{
    public const MAX_BATCH = 50;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'signals' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_BATCH],
            // Only these keys: anything else (a name, a point, a note) fails.
            'signals.*' => ['required', 'array:action,surface,item_type,item_id,position,dwell_seconds'],
            'signals.*.action' => ['required', 'string', Rule::in(SignalAction::fromClient())],
            'signals.*.surface' => ['required', 'string', Rule::enum(SignalSurface::class)],
            'signals.*.item_type' => ['required', 'string', Rule::in(['spot', 'event'])],
            'signals.*.item_id' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'signals.*.position' => ['required_if:signals.*.action,impression', 'nullable', 'integer', 'min:0', 'max:500'],
            // A detail view counts from 10 seconds; shorter ones aren't sent.
            'signals.*.dwell_seconds' => ['required_if:signals.*.action,detail_viewed', 'prohibited_unless:signals.*.action,detail_viewed', 'integer', 'min:10', 'max:86400'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $signals = $this->input('signals');
            $known = [];
            foreach (['spot' => 'spots', 'event' => 'events'] as $type => $table) {
                $ids = array_values(array_unique(array_map('intval', array_column(array_filter($signals, fn ($signal) => $signal['item_type'] === $type), 'item_id'))));
                $known[$type] = $ids === [] ? [] : array_flip(DB::table($table)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all());
            }

            foreach ($signals as $index => $signal) {
                if (! isset($known[$signal['item_type']][(int) $signal['item_id']])) {
                    $validator->errors()->add("signals.{$index}.item_id", 'This item does not exist.');
                }
                // A place's "not for me" is its feedback (POST api/places/{spot}/feedback), one way only;
                // "less like this" is offered on Today's events only.
                if (in_array($signal['action'], [SignalAction::NotForMe->value, SignalAction::LessLikeThis->value], true) && $signal['item_type'] !== 'event') {
                    $validator->errors()->add("signals.{$index}.action", 'Places take this through their feedback.');
                }
            }
        }];
    }

    /** @return list<array{action: string, surface: string, item_type: string, item_id: int, position?: int|null}> */
    public function signals(): array
    {
        return $this->validated('signals');
    }
}
