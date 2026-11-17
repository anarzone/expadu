<?php

namespace App\Http\Requests;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What changed in their notifications, from the web's Settings or the iPhone: toggles merge into the
 * saved ones (one toggle never resets the rest), quiet hours start and end at different times, and a
 * set brief time falls outside them, or it would wait until they end.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'preferences' => ['sometimes', 'array'],
            'preferences.*' => ['boolean'],
            'quiet_from' => ['sometimes', 'date_format:H:i'],
            'quiet_to' => ['sometimes', 'date_format:H:i'],
            'brief_at' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $current = NotificationPreference::stateFor($this->user());
            $from = $this->input('quiet_from', $current['quiet_from']);
            $to = $this->input('quiet_to', $current['quiet_to']);
            $brief = $this->exists('brief_at') ? $this->input('brief_at') : $current['brief_at'];
            if ($from === $to) {
                $validator->errors()->add('quiet_from', 'Quiet hours need to start and end at different times.');
            } elseif ($brief !== null && NotificationPreference::inQuietHours($brief, $from, $to)) {
                $validator->errors()->add('brief_at', "Your brief would arrive in quiet hours ({$from}–{$to}). Pick a time outside them.");
            }
        }];
    }
}
