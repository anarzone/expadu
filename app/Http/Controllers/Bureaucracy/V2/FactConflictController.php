<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Facts\ReadFactConflicts;
use App\Bureaucracy\Facts\ResolveFactConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\ResolveFactConflictRequest;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FactConflictController extends Controller
{
    public function index(Request $request, BureaucracyPerson $person, ReadFactConflicts $reviews): JsonResponse
    {
        return response()->json($reviews->for($request->user(), $person))->header('Cache-Control', 'private, no-store');
    }

    public function resolve(ResolveFactConflictRequest $request, BureaucracyPerson $person, ResolveFactConflict $resolve): JsonResponse
    {
        $data = $request->validated();

        return response()->json($resolve->execute($request->user(), $person, $data['key'], $data['value'], $data['answer_state'] ?? 'value',
            (int) $data['expected_revision'], $data['review_token'], $data['request_id']))->header('Cache-Control', 'private, no-store');
    }
}
