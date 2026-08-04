<?php

namespace App\Http\Controllers;

use App\Bureaucracy\Cases\ResolveCaseConflict;
use App\Http\Requests\ResolveBureaucracyCaseConflictRequest;
use App\Models\BureaucracyFactConflict;
use Illuminate\Http\RedirectResponse;

class BureaucracyCaseConflictController extends Controller
{
    public function __invoke(
        ResolveBureaucracyCaseConflictRequest $request,
        BureaucracyFactConflict $conflict,
        ResolveCaseConflict $resolveCaseConflict,
    ): RedirectResponse {
        $resolveCaseConflict->resolve(
            $request->user(),
            $conflict,
            $request->validated('choice'),
        );

        return back();
    }
}
