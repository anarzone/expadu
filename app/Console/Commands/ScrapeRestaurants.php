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

    // Cologne split into quadrants to avoid Overpass timeouts
    private const BBOXES = [
        '50.86,6.82,50.97,6.97',  // SW
        '50.86,6.97,50.97,7.12',  // SE
        '50.97,6.82,51.08,6.97',  // NW
        '50.97,6.97,51.08,7.12',  // NE
    ];

    private const CATEGORY_MAP = [
        'restaurant' => 'restaurant',
        'fast_food' => 'fast_food',
        'cafe' => 'cafe',
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

            // Query each quadrant separately to avoid Overpass timeouts
            $elements = [];
            foreach (self::BBOXES as $bbox) {
                $query = $this->buildQuery($osmType, $bbox);
                $response = Http::asForm()->timeout(60)->post(self::OVERPASS_URL, ['data' => $query]);

                if ($response->successful()) {
                    $elements = array_merge($elements, $response->json('elements') ?? []);
                } else {
                    $this->warn("  Quadrant {$bbox} failed: HTTP {$response->status()}");
                }

                usleep(500000); // 0.5s between quadrant requests
            }

            if (empty($elements)) {
                $this->error("  No data for {$osmType}");

                continue;
            }

            $this->info('  Found '.count($elements).' elements across '.count(self::BBOXES).' quadrants');

            if ($limit > 0) {
                $elements = array_slice($elements, 0, $limit);
            }

            $totalFiltered = 0;

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

                $tags = $el['tags'] ?? [];

                // Quality scoring: count filled fields out of key fields
                // Key fields: name, address, cuisine, phone, website, opening_hours, outdoor_seating
                $fields = [
                    'name' => true, // always true at this point
                    'address' => isset($tags['addr:street']),
                    'cuisine' => isset($tags['cuisine']),
                    'phone' => isset($tags['phone']) || isset($tags['contact:phone']),
                    'website' => isset($tags['website']) || isset($tags['contact:website']),
                    'opening_hours' => isset($tags['opening_hours']),
                    'outdoor_seating' => isset($tags['outdoor_seating']),
                ];

                $filled = count(array_filter($fields));
                $total = count($fields);
                $fillRate = $filled / $total;

                // Skip if less than 60% fields filled
                if ($fillRate < 0.6) {
                    $totalFiltered++;

                    continue;
                }

                $sourceId = 'osm_'.$el['id'];

                if (Spot::where('source', 'osm')->where('source_id', $sourceId)->exists()) {
                    $totalSkipped++;

                    continue;
                }

                // Auto-verify if 80%+ fields filled
                $isVerified = $fillRate >= 0.8;

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
                    'is_verified' => $isVerified,
                ]);

                $totalCreated++;
            }

            // Rate limit between categories to be nice to the Overpass API
            sleep(2);
        }

        $verified = Spot::where('source', 'osm')->where('is_verified', true)->count();
        $this->info("Done: {$totalCreated} imported, {$verified} auto-verified, {$totalSkipped} skipped (exist), {$totalFiltered} filtered (low quality).");
        Log::info('Restaurant scrape completed', ['created' => $totalCreated, 'skipped' => $totalSkipped]);

        return self::SUCCESS;
    }

    private function buildQuery(string $amenityType, string $bbox): string
    {
        return "[out:json][timeout:30];(node[\"amenity\"=\"{$amenityType}\"][\"name\"]({$bbox});way[\"amenity\"=\"{$amenityType}\"][\"name\"]({$bbox}););out center;";

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
