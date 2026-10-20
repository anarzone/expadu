<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\PersonDataLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\ErasePersonRequest;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersonDataController extends Controller
{
    public function __construct(private PersonDataLifecycle $lifecycle) {}

    public function export(Request $request, BureaucracyPerson $person): JsonResponse
    {
        return response()->json($this->lifecycle->export($request->user(), $person))
            ->header('Cache-Control', 'private, no-store')->header('Content-Disposition', 'attachment; filename="bureaucracy-export.json"');
    }

    public function destroy(ErasePersonRequest $request, BureaucracyPerson $person): Response
    {
        $this->lifecycle->erase($request->user(), $person);

        return response()->noContent();
    }
}
