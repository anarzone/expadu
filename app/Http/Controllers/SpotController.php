<?php

namespace App\Http\Controllers;

use App\Models\Spot;
use App\Models\SpotCheckin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpotController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Spot::query();

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($noise = $request->query('noise_level')) {
            $query->where('noise_level', $noise);
        }

        // Sort by distance to user's location — from query params or user's home place
        $userLat = $request->query('lat');
        $userLng = $request->query('lng');

        if (! $userLat || ! $userLng) {
            $home = $request->user()->places()->orderBy('sort_order')->first();
            if ($home?->lat && $home?->lng) {
                $userLat = $home->lat;
                $userLng = $home->lng;
            }
        }

        if ($userLat && $userLng) {
            $query->whereNotNull('lat')
                ->whereNotNull('lng')
                ->selectRaw('*, (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) as distance_km', [(float) $userLat, (float) $userLng, (float) $userLat])
                ->orderBy('distance_km');
        } else {
            $sort = $request->query('sort', 'rating');
            $query = match ($sort) {
                'rating' => $query->orderByDesc('rating'),
                'crowd' => $query->withCount(['activeCheckins'])->orderBy('active_checkins_count'),
                default => $query->orderByDesc('rating'),
            };
        }

        // Initial load: 20 nearest. Map fetches more via GET /api/spots as user pans
        $spots = $query->withCount('activeCheckins')->paginate(20);

        return Inertia::render('explore', [
            'spots' => $spots,
            'filters' => [
                'category' => $request->query('category'),
                'noise_level' => $request->query('noise_level'),
                'sort' => $request->query('sort', $userLat ? 'distance' : 'rating'),
            ],
            'personalPlaces' => $request->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
        ]);
    }

    public function show(Spot $spot): Response
    {
        $spot->loadCount('activeCheckins');

        return Inertia::render('explore', [
            'spot' => $spot,
            'spots' => Spot::withCount('activeCheckins')->orderByDesc('rating')->paginate(50),
            'filters' => [],
            'personalPlaces' => request()->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
        ]);
    }

    public function checkin(Request $request, Spot $spot): RedirectResponse
    {
        $existing = SpotCheckin::where('user_id', $request->user()->id)
            ->where('spot_id', $spot->id)
            ->whereNull('checked_out_at')
            ->first();

        if (! $existing) {
            SpotCheckin::create([
                'spot_id' => $spot->id,
                'user_id' => $request->user()->id,
                'checked_in_at' => now(),
            ]);
        }

        return back();
    }

    public function checkout(Request $request, Spot $spot): RedirectResponse
    {
        SpotCheckin::where('user_id', $request->user()->id)
            ->where('spot_id', $spot->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        return back();
    }
}
