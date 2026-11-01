<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Spot;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    /**
     * Places for a few typed words (Where? in Add a plan): Cologne's own places first, by name
     * ("Brauhaus Sion" finds "Sion Bräues Traditionsbrauhaus"), then the geocoder's addresses.
     */
    public function __invoke(Request $request, GeocodingService $geocoding): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $query = $request->string('q')->toString();

        // What was typed is answered and forgotten: no search log keeps it.
        $places = $this->places($query);
        $addresses = array_filter(
            $geocoding->search($query),
            fn (array $hit) => ! collect($places)->contains(fn (array $place) => abs($place['lat'] - $hit['lat']) < 0.0005 && abs($place['lng'] - $hit['lng']) < 0.0005),
        );

        return response()->json(array_slice([...$places, ...array_values($addresses)], 0, 6));
    }

    /**
     * Our own places whose name holds every typed word (of three letters or more), the
     * shortest names first: the closest matches.
     *
     * @return list<array{name: string, street: string|null, city: string|null, lat: float, lng: float}>
     */
    private function places(string $query): array
    {
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            fn (string $word) => mb_strlen($word) >= 3,
        ));
        if ($words === []) {
            return [];
        }

        return Spot::query()->canonical()
            ->whereNotNull('lat')->whereNotNull('lng')
            ->where(function ($names) use ($words) {
                foreach ($words as $word) {
                    $names->where('name', 'ilike', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->orderByRaw('length(name)')
            ->limit(3)
            ->get(['name', 'address', 'lat', 'lng'])
            ->map(fn (Spot $spot) => [
                'name' => $spot->name,
                'street' => $spot->address,
                'city' => null,
                'lat' => (float) $spot->lat,
                'lng' => (float) $spot->lng,
            ])
            ->all();
    }
}
