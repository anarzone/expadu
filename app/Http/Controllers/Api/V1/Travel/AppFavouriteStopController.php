<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\FavouriteStopController as Web;
use App\Services\KvbApiService;
use App\Transit\FavouriteStops;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Travel's ★ favourite stops, for the iPhone app's token, each line in KVB's colour. */
class AppFavouriteStopController extends Web
{
    public function index(Request $request, KvbApiService $kvb): JsonResponse
    {
        return LineColours::add(parent::index($request, $kvb), 'favourites');
    }

    public function store(Request $request, KvbApiService $kvb, FavouriteStops $stops): JsonResponse
    {
        return LineColours::add(parent::store($request, $kvb, $stops), 'favourites');
    }

    public function destroy(Request $request, KvbApiService $kvb, FavouriteStops $stops): JsonResponse
    {
        return LineColours::add(parent::destroy($request, $kvb, $stops), 'favourites');
    }
}
