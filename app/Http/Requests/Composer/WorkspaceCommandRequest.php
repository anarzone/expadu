<?php

namespace App\Http\Requests\Composer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WorkspaceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            // Every change applies directly with its own Undo; there is no
            // approval, draft or date-move command any more.
            'action' => ['required', Rule::in(['message', 'reply', 'proposal', 'reapply', 'open', 'edit', 'remove', 'add', 'appointment', 'replace', 'undo', 'redo', 'fresh', 'resume', 'delete_history', 'cancel_day', 'complete', 'uncomplete'])],
            // Only a message carries words; its answer is worked out from the stored one (composer/ask).
            'text' => ['required_if:action,message', 'prohibited_unless:action,message', 'string', 'max:500'],
            // A reply is only ever that the page couldn't get an answer.
            'kind' => ['required_if:action,reply', Rule::in(['failed'])],
            'token' => ['required_if:action,proposal', 'string', 'max:80'],
            'id' => ['required_if:action,edit,remove,replace,resume,reapply,complete,uncomplete', 'string', 'max:120'],
            // Which change to undo (without it, the newest one), or to put back.
            'change' => ['required_if:action,redo', 'nullable', 'uuid'],
            'candidate' => ['required_if:action,add,replace', 'string', 'max:120'],
            'date' => ['required_if:action,open,cancel_day,complete,uncomplete', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'start' => ['required_if:action,appointment', 'date_format:H:i', 'required_with:end'],
            'end' => ['required_if:action,appointment', 'date_format:H:i', 'required_with:start'],
            'name' => ['required_if:action,appointment', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('action', 'Unexpected command fields.');
            }
        }];
    }
}
