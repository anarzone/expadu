<?php

namespace App\Http\Controllers;

use App\Models\UserPlace;
use App\Profile\ProfileEngine;
use App\Services\VeedelDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SpotController extends Controller
{
    /**
     * Places — a list-first discovery feed with a two-level geography:
     * the rail shows Cologne's 9 Bezirke (big cards), the chips show the
     * Stadtteile (Veedels) of the selected Bezirk. The card list itself
     * is fetched client-side from GET /api/places.
     */
    public function index(Request $request, ProfileEngine $profiles, VeedelDirectory $veedels): Response
    {
        $homeVeedel = $profiles->build($request->user())->veedel;
        $homeBezirk = $homeVeedel
            ? DB::table('veedels')->where('name', $homeVeedel)->value('bezirk')
            : null;

        // No geography in the URL → default to the user's home corner.
        if ($request->filled('veedel') || $request->filled('bezirk')) {
            $veedel = $request->query('veedel');
            $bezirk = $request->query('bezirk')
                ?? ($veedel ? DB::table('veedels')->where('name', $veedel)->value('bezirk') : null)
                ?? 'all';
        } else {
            $veedel = $homeVeedel;
            $bezirk = $homeBezirk ?? 'all';
        }

        return Inertia::render('places', [
            'homeVeedel' => $homeVeedel,
            'homeBezirk' => $homeBezirk,
            // Saved origins for the "From" picker (Home / Work / pins). Only the
            // id, label and a sublabel reach the client — the coordinates stay
            // server-side and are resolved by id when one is picked.
            'savedPlaces' => $request->user()->places()
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['id', 'name', 'category', 'address'])
                ->map(fn (UserPlace $place) => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'category' => (string) $place->category,
                    'address' => $place->address,
                ])
                ->all(),
            'filters' => [
                'bezirk' => $bezirk,
                'veedel' => $veedel,
                'category' => $request->query('category'),
            ],
            // Deferred so the page shell paints fast.
            'bezirke' => Inertia::defer(fn () => $veedels->bezirkRail($homeBezirk)),
            'veedelsByBezirk' => Inertia::defer(fn () => $veedels->veedelsByBezirk()),
        ]);
    }
}
