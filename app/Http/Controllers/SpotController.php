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

        $sort = $request->query('sort', 'rating');
        $query = match ($sort) {
            'rating' => $query->orderByDesc('rating'),
            'crowd' => $query->withCount(['activeCheckins'])->orderBy('active_checkins_count'),
            default => $query->orderByDesc('rating'),
        };

        $spots = $query->withCount('activeCheckins')->paginate(20);

        return Inertia::render('explore', [
            'spots' => $spots,
            'filters' => [
                'category' => $request->query('category'),
                'noise_level' => $request->query('noise_level'),
                'sort' => $sort,
            ],
        ]);
    }

    public function show(Spot $spot): Response
    {
        $spot->loadCount('activeCheckins');

        return Inertia::render('explore', [
            'spot' => $spot,
            'spots' => Spot::withCount('activeCheckins')->orderByDesc('rating')->paginate(20),
            'filters' => [],
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
