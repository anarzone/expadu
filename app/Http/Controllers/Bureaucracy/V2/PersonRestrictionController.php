<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\RestrictPersonProcessing;
use App\Http\Controllers\Controller;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Your record's Stop / Resume processing (GDPR Art. 18): the holder's own
 * switch. PUT people/{person}/processing-restriction {stopped} answers with
 * when it was stopped, or null.
 */
class PersonRestrictionController extends Controller
{
    public function __construct(private RestrictPersonProcessing $restriction) {}

    public function update(Request $request, BureaucracyPerson $person): JsonResponse
    {
        $stop = $request->validate(['stopped' => ['required', 'boolean']])['stopped'];

        $stoppedAt = $stop
            ? $this->restriction->stop($request->user(), $person)
            : $this->restriction->resume($request->user(), $person);

        return response()->json(['processing_stopped_at' => $stoppedAt?->toIso8601String()])
            ->header('Cache-Control', 'private, no-store');
    }
}
