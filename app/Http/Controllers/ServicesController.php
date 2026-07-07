<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServicesController extends Controller
{
    public function index(Request $request): Response
    {
        // Services directory isn't launch-ready yet — render a coming-soon screen
        // and skip the provider query below. Delete this guard to re-enable it.
        return Inertia::render('services', ['comingSoon' => true]);

        $user = $request->user();
        $home = $user->places()->orderBy('sort_order')->first();
        $lat = $home?->lat ?? 50.9375;
        $lng = $home?->lng ?? 6.9603;

        $category = $request->query('category');

        $query = Service::query()
            ->selectRaw('*, ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_meters', [$lng, $lat])
            ->when($category && $category !== 'all', fn ($q) => $q->where('category', $category))
            ->orderBy('distance_meters')
            ->limit(50);

        $services = $query->get()->map(fn (Service $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'cat' => $s->category,
            'emoji' => $s->emoji ?? '📍',
            'type' => ucfirst($s->category),
            'desc' => $s->description,
            'address' => $s->address,
            'distance' => $s->distance_meters
                ? ($s->distance_meters < 1000
                    ? round($s->distance_meters).' m'
                    : round($s->distance_meters / 1000, 1).' km')
                : null,
            'phone' => $s->phone,
            'website' => $s->website,
            'languages' => $s->languages ?? [],
            'insurance' => $s->insurance_accepted ?? [],
            'hours' => $s->opening_hours,
            'verified' => $s->is_verified,
            'acceptingNew' => $s->accepts_new,
            'rating' => $s->rating,
            'reviews' => $s->review_count,
        ]);

        // Get category counts
        $categoryCounts = Service::selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->all();

        return Inertia::render('services', [
            'dbServices' => $services,
            'categoryCounts' => $categoryCounts,
            'activeCategory' => $category ?? 'all',
        ]);
    }
}
