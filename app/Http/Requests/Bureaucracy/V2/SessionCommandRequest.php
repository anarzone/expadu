<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Models\BureaucracyQuestionSession;
use Illuminate\Foundation\Http\FormRequest;

abstract class SessionCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        // Commands re-check current person permissions inside their transaction.
        return $session instanceof BureaucracyQuestionSession && $session->actor_id === $this->user()?->id;
    }
}
