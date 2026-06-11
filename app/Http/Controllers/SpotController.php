<?php

namespace App\Http\Controllers;

use App\Models\Spot;
use App\Profile\ProfileEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SpotController extends Controller
{
    public function index(Request $request, ProfileEngine $profiles): Response
    {
        $userLat = $request->query('lat');
        $userLng = $request->query('lng');

        if (! $userLat || ! $userLng) {
            $home = $request->user()->places()->orderBy('sort_order')->first();
            if ($home?->lat && $home?->lng) {
                $userLat = $home->lat;
                $userLng = $home->lng;
            }
        }

        $profile = $profiles->build($request->user());

        // Veedel is the primary navigation: default to the user's home
        // Veedel, 'all' lifts the filter entirely.
        $veedel = $request->query('veedel') ?? $profile->veedel ?? 'all';
        $category = $request->query('category');
        $noise = $request->query('noise_level');

        return Inertia::render('explore', [
            'spots' => Inertia::defer(function () use ($category, $noise, $veedel, $userLat, $userLng) {
                $query = Spot::query();
                if ($veedel !== 'all') {
                    $query->where('veedel', $veedel);
                }
                if ($category) {
                    $query->where('category', $category);
                }
                if ($noise) {
                    $query->where('noise_level', $noise);
                }

                if ($userLat && $userLng) {
                    $query->nearby((float) $userLat, (float) $userLng);
                } else {
                    $query->orderByDesc('rating');
                }

                return $query->paginate(20);
            }),
            'filters' => [
                'veedel' => $veedel,
                'category' => $category,
                'noise_level' => $noise,
                'sort' => $request->query('sort', $userLat ? 'distance' : 'rating'),
            ],
            // Pills: home Veedel first, then the Veedels with the most
            // content, capped so the row stays scrollable.
            'veedelOptions' => Inertia::defer(fn () => collect([$profile->veedel])
                ->filter()
                ->merge(
                    DB::table('spots')
                        ->select('veedel', DB::raw('count(*) as n'))
                        ->whereNotNull('veedel')
                        ->groupBy('veedel')
                        ->orderByDesc('n')
                        ->limit(10)
                        ->pluck('veedel'),
                )
                ->unique()
                ->take(8)
                ->values()
                ->all()),
            'personalPlaces' => $request->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
        ]);
    }

    public function show(Spot $spot): Response
    {
        return Inertia::render('explore', [
            'spot' => $spot,
            'spots' => Inertia::defer(fn () => Spot::orderByDesc('rating')->paginate(50)),
            'filters' => [],
            'personalPlaces' => request()->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
        ]);
    }
}
