<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordSignalsRequest;
use App\Personalisation\SignalRecorder;
use Illuminate\Http\Response;

/**
 * POST /api/signals — what only the client sees: impressions (with their
 * position and surface) and detail views of 10 seconds or more. Every other
 * signal is recorded server-side where it happens. Without the
 * personalisation opt-in a valid batch is accepted and dropped, the same 202.
 */
class SignalController extends Controller
{
    public function __invoke(RecordSignalsRequest $request, SignalRecorder $signals): Response
    {
        $signals->fromClient($request->user(), $request->signals());

        return response()->noContent(202);
    }
}
