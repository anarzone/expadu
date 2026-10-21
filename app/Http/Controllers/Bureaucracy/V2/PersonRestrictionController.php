<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\RestrictPersonProcessing;
use App\Http\Controllers\Controller;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Your record's Stop / Resume processing (GDPR Art. 18): the holder's own switch. */
class PersonRestrictionController extends Controller
{
    public function __construct(private RestrictPersonProcessing $restriction) {}

    public function show(Request $request, BureaucracyPerson $person): JsonResponse
    {
        return $this->state($this->restriction->stopped($request->user(), $person));
    }

    public function store(Request $request, BureaucracyPerson $person): JsonResponse
    {
        $this->restriction->stop($request->user(), $person);

        return $this->state(true);
    }

    public function destroy(Request $request, BureaucracyPerson $person): JsonResponse
    {
        $this->restriction->resume($request->user(), $person);

        return $this->state(false);
    }

    private function state(bool $stopped): JsonResponse
    {
        return response()->json(['stopped' => $stopped])->header('Cache-Control', 'private, no-store');
    }
}
