<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\Processes\ProcessStateMachine;
use Illuminate\Validation\Rule;

class RecordProcessEventRequest extends ProcessCommandRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'event' => ['required', Rule::in(ProcessStateMachine::Events)],
            'payload' => ['present', 'array', 'max:10'], 'review_token' => ['required_unless:event,appointment_cancelled,cancellation_reported', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }
}
