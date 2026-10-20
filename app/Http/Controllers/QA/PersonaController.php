<?php

namespace App\Http\Controllers\QA;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\QA\ResetPersonaState;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Retired mutation endpoints remain explicit tombstones for stale clients.
 * QA uses the canonical read-only scenario preview, never the acting dossier.
 */
class PersonaController extends Controller
{
    public function become(Request $request, string $persona): never
    {
        abort_unless($request->user()?->fresh()?->is_admin === true, 403);

        $match = collect(BureaucracyPersonas::demo())->firstWhere('key', $persona);
        abort_if($match === null, 404);

        app(ResetPersonaState::class)->execute($request->user());
    }

    public function resetTasks(Request $request): never
    {
        abort_unless($request->user()?->fresh()?->is_admin === true, 403);

        app(ResetPersonaState::class)->execute($request->user());
    }
}
