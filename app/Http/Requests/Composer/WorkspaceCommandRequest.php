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
            'action' => ['required', Rule::in(['message', 'reply', 'proposal', 'open', 'edit', 'remove', 'add', 'appointment', 'replace', 'approve', 'discard', 'undo', 'approval', 'fresh', 'resume', 'delete_history', 'date', 'cancel_day'])],
            'text' => ['required_if:action,message', 'string', 'max:500'],
            'kind' => ['required_if:action,reply', Rule::in(['bureaucracy', 'find', 'journey', 'unknown', 'failed'])],
            'token' => ['required_if:action,proposal', 'string', 'max:80'],
            'id' => ['required_if:action,edit,remove,replace,resume', 'string', 'max:120'],
            'candidate' => ['required_if:action,add,replace', 'string', 'max:120'],
            'date' => ['required_if:action,open,date,cancel_day', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'start' => ['required_if:action,appointment', 'date_format:H:i', 'required_with:end'],
            'end' => ['required_if:action,appointment', 'date_format:H:i', 'required_with:start'],
            'name' => ['required_if:action,appointment', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'mode' => ['required_if:action,approval', Rule::in(['ask', 'routine'])],
            'cancel' => ['sometimes', 'boolean'],
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
