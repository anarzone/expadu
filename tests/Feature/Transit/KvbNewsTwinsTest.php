<?php

use App\Models\CityNews;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function kvbAktuellesPage(string $heading): string
{
    return '<html><body><main><h3>'.$heading.'</h3><p>Details</p></main></body></html>';
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Europe/Berlin'));
});

it('updates a reworded KVB heading instead of adding a twin notice', function () {
    $heading = '17.10. - 02.11.2026: Trennung der Linien 1, 7, 9';
    Http::fake(function ($request) use (&$heading) {
        return str_contains($request->url(), 'kvb.koeln/aktuelles')
            ? Http::response(kvbAktuellesPage($heading))
            : Http::response('', 404);
    });
    $this->artisan('news:scrape')->assertSuccessful();

    $heading = '17.10. - 02.11.2026: Trennung der Linien 1, 7 und 9';
    $this->artisan('news:scrape')->assertSuccessful();

    $rows = CityNews::query()->where('source', 'kvb')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->title)->toBe('17.10. - 02.11.2026: Trennung der Linien 1, 7 und 9');
});

it('closes all but the newest of existing twin notices', function () {
    $base = ['source' => 'kvb', 'category' => 'transit', 'relevance' => 'transit', 'severity' => 'minor', 'summary' => 'x',
        'published_at' => now()->subDays(4), 'expires_at' => now()->addDays(12)];
    $older = CityNews::create([...$base, 'title' => '17.10. - 02.11.2026: Trennung der Linien 1, 7, 9', 'source_url' => 'https://www.kvb.koeln/aktuelles/#a']);
    $newer = CityNews::create([...$base, 'title' => '17.10. - 02.11.2026: Trennung der Linien 1, 7 und 9', 'source_url' => 'https://www.kvb.koeln/aktuelles/#b']);
    $other = CityNews::create([...$base, 'title' => 'Bis 23.11.2026: Trennung der Linie 18', 'source_url' => 'https://www.kvb.koeln/aktuelles/#c']);

    (require base_path('database/migrations/2026_10_21_230000_close_reworded_kvb_news_twins.php'))->up();

    expect($older->fresh()->expires_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($newer->fresh()->expires_at->greaterThan(now()))->toBeTrue()
        ->and($other->fresh()->expires_at->greaterThan(now()))->toBeTrue();
});
