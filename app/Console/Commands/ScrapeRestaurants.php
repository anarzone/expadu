<?php

namespace App\Console\Commands;

use App\Models\Spot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrape restaurants, fast food, bars, bakeries from OpenStreetMap Overpass API.
 * Cologne bbox: roughly 50.86-51.08 lat, 6.82-7.12 lng.
 *
 * Run: php artisan restaurants:scrape
 * Schedule: weekly
 */
class ScrapeRestaurants extends Command
{
    protected $signature = 'restaurants:scrape {--limit=0 : Limit results per category (0 = all)}';

    protected $description = 'Scrape restaurants, bars, bakeries from OpenStreetMap for Cologne';

    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

    // Cologne bounding box
    private const BBOX = '50.86,6.82,51.08,7.12';

    private const CATEGORY_MAP = [
        'restaurant' => 'restaurant',
        'fast_food' => 'fast_food',
        'bar' => 'bar',
        'pub' => 'bar',
        'bakery' => 'bakery',
    ];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $totalCreated = 0;
        $totalSkipped = 0;

        foreach (self::CATEGORY_MAP as $osmType => $spotCategory) {
            $this->info("Fetching {$osmType}...");

            $query = $this->buildQuery($osmType);
            $response = Http::timeout(30)->post(self::OVERPASS_URL, ['data' => $query]);

            if (! $response->successful()) {
                $this->error("Overpass API failed for {$osmType}: HTTP {$response->status()}");

                continue;
            }

            $elements = $response->json('elements') ?? [];
            $this->info('  Found '.count($elements).' elements');

            if ($limit > 0) {
                $elements = array_slice($elements, 0, $limit);
            }

            foreach ($elements as $el) {
                $name = $el['tags']['name'] ?? null;
                if (! $name) {
                    continue;
                }

                $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
                $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
                if (! $lat || ! $lng) {
                    continue;
                }

                $sourceId = 'osm_'.$el['id'];

                // Skip if already imported
                if (Spot::where('source', 'osm')->where('source_id', $sourceId)->exists()) {
                    $totalSkipped++;

                    continue;
                }

                $tags = $el['tags'] ?? [];

                Spot::create([
                    'name' => mb_substr($name, 0, 255),
                    'category' => $spotCategory,
                    'cuisine' => $this->normalizeCuisine($tags['cuisine'] ?? null),
                    'price_range' => null,
                    'tags' => $this->extractTags($tags),
                    'description' => null,
                    'address' => $this->buildAddress($tags),
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                    'website' => $tags['website'] ?? $tags['contact:website'] ?? null,
                    'opening_hours' => $tags['opening_hours'] ?? null,
                    'source' => 'osm',
                    'source_id' => $sourceId,
                ]);

                $totalCreated++;
            }

            // Rate limit between categories to be nice to the Overpass API
            sleep(2);
        }

        $this->info("Done: {$totalCreated} created, {$totalSkipped} skipped (already exist).");
        Log::info('Restaurant scrape completed', ['created' => $totalCreated, 'skipped' => $totalSkipped]);

        return self::SUCCESS;
    }

    private function buildQuery(string $amenityType): string
    {
        $bbox = self::BBOX;

        return <<<QUERY
        [out:json][timeout:25];
        (
          node["amenity"="{$amenityType}"]["name"]({$bbox});
          way["amenity"="{$amenityType}"]["name"]({$bbox});
        );
        out center;
        QUERY;
    }

    private function normalizeCuisine(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        // OSM uses semicolons for multiple: "pizza;pasta" → "Italian"
        $first = explode(';', $raw)[0];
        $first = trim(mb_strtolower($first));

        return match ($first) {
            'pizza', 'italian', 'pasta' => 'Italian',
            'burger', 'american' => 'American',
            'kebab', 'turkish', 'döner' => 'Turkish',
            'sushi', 'japanese', 'ramen' => 'Japanese',
            'chinese', 'asian' => 'Chinese',
            'vietnamese', 'pho' => 'Vietnamese',
            'thai' => 'Thai',
            'indian', 'curry' => 'Indian',
            'mexican', 'burrito', 'taco' => 'Mexican',
            'greek', 'gyros' => 'Greek',
            'german', 'bavarian', 'regional' => 'German',
            'french' => 'French',
            'korean' => 'Korean',
            'lebanese', 'falafel', 'arabic', 'middle_eastern' => 'Middle Eastern',
            'vegan', 'vegetarian' => 'Vegan',
            'seafood', 'fish' => 'Seafood',
            'ice_cream', 'ice cream' => 'Ice Cream',
            'coffee' => 'Coffee',
            'bakery', 'bread' => 'Bakery',
            default => ucfirst($first),
        };
    }

    /**
     * @return string[]
     */
    private function extractTags(array $osmTags): array
    {
        $tags = [];

        if (isset($osmTags['outdoor_seating']) && $osmTags['outdoor_seating'] === 'yes') {
            $tags[] = 'outdoor-seating';
        }
        if (isset($osmTags['diet:vegan']) && $osmTags['diet:vegan'] === 'yes') {
            $tags[] = 'vegan';
        }
        if (isset($osmTags['diet:vegetarian']) && $osmTags['diet:vegetarian'] === 'yes') {
            $tags[] = 'vegetarian';
        }
        if (isset($osmTags['wheelchair']) && $osmTags['wheelchair'] === 'yes') {
            $tags[] = 'wheelchair-accessible';
        }
        if (isset($osmTags['internet_access']) && $osmTags['internet_access'] === 'wlan') {
            $tags[] = 'wifi';
        }
        if (isset($osmTags['takeaway']) && $osmTags['takeaway'] === 'yes') {
            $tags[] = 'takeaway';
        }
        if (isset($osmTags['delivery']) && $osmTags['delivery'] === 'yes') {
            $tags[] = 'delivery';
        }

        return $tags;
    }

    private function buildAddress(array $tags): ?string
    {
        $street = $tags['addr:street'] ?? null;
        $number = $tags['addr:housenumber'] ?? null;
        $city = $tags['addr:city'] ?? 'Köln';

        if (! $street) {
            return null;
        }

        return trim("{$street} {$number}, {$city}");
    }
}
