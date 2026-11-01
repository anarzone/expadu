<?php

namespace App\Http\Controllers\Settings;

use App\Accessibility\SetStepFreeNeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUT /settings/needs/step-free {step_free: bool}: the person's own step-free need, set
 * through SetStepFreeNeed, its one writer (the account menu's setting and Composer's
 * set_needs). Only ever their own; it's never in the user's serialisation.
 */
class StepFreeNeedController extends Controller
{
    public function __invoke(Request $request, SetStepFreeNeed $need): JsonResponse
    {
        $validated = $request->validate(['step_free' => ['required', 'boolean']]);
        $need->execute($request->user(), (bool) $validated['step_free']);

        return response()->json(['step_free' => (bool) $request->user()->needs_step_free]);
    }
}
