<?php

namespace App\Http\Controllers;

use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessingNoticeController extends Controller
{
    public function __invoke(ProcessingPurpose $purpose): JsonResponse
    {
        return response()->json($purpose->disclosure());
    }

    public function withdraw(Request $request, ProcessingPurpose $purpose, ProcessingConsentStore $consents): JsonResponse
    {
        $inFlight = $consents->withdraw($request->user(), purpose: $purpose);

        return response()->json(['withdrawn' => true, 'in_flight' => $inFlight,
            'message' => 'Future processing is stopped and saved responses are removed. A request already sent may finish at the processor, but its response will not be used.']);
    }
}
