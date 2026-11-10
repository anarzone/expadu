<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\StationSearchController as Web;
use App\Services\KvbApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Travel's stop picker: KVB stations by name, for the iPhone app's token, each line in KVB's colour. */
class AppStationSearchController extends Web
{
    public function __invoke(Request $request, KvbApiService $kvb): JsonResponse
    {
        return LineColours::add(parent::__invoke($request, $kvb), 'stations');
    }
}
