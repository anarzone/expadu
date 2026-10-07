<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\ReadModel\AccountPlanEntry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountPlanController extends Controller
{
    public function __invoke(Request $request, AccountPlanEntry $entry): JsonResponse
    {
        return response()->json($entry->for($request->user()))->header('Cache-Control', 'private, no-store');
    }
}
