<?php

namespace App\Bureaucracy\QA;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Compatibility tombstone: no account is presumed disposable from its admin
 * role, email, QA badge or environment. In-memory previews need no reset.
 */
final class ResetPersonaState
{
    public function execute(User $user): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'qa_mutation_retired',
            'message' => 'Persona testing is read-only. Open a persona preview; your account and family plans have not been changed.',
            'preview_route' => 'bureaucracy.v2.preview',
        ], 410)->header('Cache-Control', 'private, no-store'));
    }
}
