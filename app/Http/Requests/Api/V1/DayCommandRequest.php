<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Composer\WorkspaceCommandRequest;
use Illuminate\Validation\Rule;

/**
 * A change to the day from the iPhone's Today: the same commands the web's Today sends to the
 * Composer workspace, only those Today offers (done, remove, add, a personal plan, undo).
 */
class DayCommandRequest extends WorkspaceCommandRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'action' => ['required', Rule::in(['complete', 'uncomplete', 'remove', 'add', 'appointment', 'undo'])],
        ];
    }
}
