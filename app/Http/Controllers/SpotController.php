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
        $userLat = $request->query('lat');
        $userLng = $request->query('lng');

        if (! $userLat || ! $userLng) {
            $home = $request->user()->places()->orderBy('sort_order')->first();
            if ($home?->lat && $home?->lng) {
                $userLat = $home->lat;
                $userLng = $home->lng;
            }
        }

        $category = $request->query('category');
        $noise = $request->query('noise_level');
        $sort = $request->query('sort', 'rating');

        return Inertia::render('explore', [
            'spots' => Inertia::defer(function () use ($category, $noise, $userLat, $userLng, $sort) {
                $query = Spot::query();
                if ($category) {
                    $query->where('category', $category);
                }
                if ($noise) {
                    $query->where('noise_level', $noise);
                }

                if ($userLat && $userLng) {
                    $query->nearby((float) $userLat, (float) $userLng);
                } else {
                    $query = match ($sort) {
                        'crowd' => $query->withCount(['activeCheckins'])->orderBy('active_checkins_count'),
                        default => $query->orderByDesc('rating'),
                    };
                }

                return $query->withCount('activeCheckins')->paginate(20);
            }),
            'filters' => [
                'category' => $category,
                'noise_level' => $noise,
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
            'spots' => Inertia::defer(fn () => Spot::withCount('activeCheckins')->orderByDesc('rating')->paginate(50)),
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
