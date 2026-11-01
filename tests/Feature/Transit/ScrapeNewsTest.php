<?php

use App\Models\CityNews;
use App\Services\DisruptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Fakes KVB: the news page (headings) and the service page (SpecialAnnouncements); everything else 404. */
function fakeKvb(?array &$headings, ?array &$announcements, int $newsStatus = 200): void
{
    Http::fake(function ($request) use (&$headings, &$announcements, $newsStatus) {
        $url = $request->url();
        if (str_contains($url, 'kvb.koeln/aktuelles')) {
            return Http::response('<main>'.implode('', array_map(fn ($h) => "<h3>{$h}</h3>", $headings ?? [])).'</main>', $newsStatus);
        }
        if (str_contains($url, 'kvb.koeln/fahrtinfo/betriebslage')) {
            $blocks = array_map(fn ($a) => '<script type="application/ld+json">'.json_encode([
                '@type' => 'SpecialAnnouncement', 'name' => "Linie {$a[0]}: {$a[1]}", 'description' => $a[2] ?? '',
                'additionalProperty' => [['name' => 'Linie', 'value' => $a[0]]],
            ]).'</script>', $announcements ?? []);

            return Http::response('<html>'.implode('', $blocks).'</html>');
        }

        return Http::response('', 404);
    });
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Europe/Berlin')));

it('ends a KVB news notice once the page no longer lists it', function () {
    $headings = ['17.10. - 02.11.2026: Trennung Linie 16 und 17', 'Bis 23.11.2026: Trennung der Linie 18'];
    $announcements = [];
    fakeKvb($headings, $announcements);
    $this->artisan('news:scrape')->assertSuccessful();

    $headings = ['Bis 23.11.2026: Trennung der Linie 18'];
    $this->artisan('news:scrape')->assertSuccessful();

    $open = app(DisruptionService::class)->openNotices()->where('source', 'kvb');
    expect($open->pluck('title')->all())->toBe(['Bis 23.11.2026: Trennung der Linie 18']);
});

it('closes nothing when the news page fails or lists nothing', function (int $status, array $after) {
    $headings = ['Bis 23.11.2026: Trennung der Linie 18'];
    $announcements = [];
    fakeKvb($headings, $announcements);
    $this->artisan('news:scrape')->assertSuccessful();

    $headings = $after;
    fakeKvb($headings, $announcements, $status);
    $this->artisan('news:scrape')->assertSuccessful();

    expect(app(DisruptionService::class)->openNotices()->where('source', 'kvb'))->toHaveCount(1);
})->with([
    'site down (404)' => [404, []],
    'page with no notices' => [200, []],
]);

it('keeps long works open and in place, not deleted and re-added each run', function () {
    // Started two months ago, ends next month.
    $headings = ['01.08. - 30.11.2026: Sanierung der Linie 5'];
    $announcements = [];
    fakeKvb($headings, $announcements);
    $this->artisan('news:scrape')->assertSuccessful();
    $first = CityNews::query()->where('source', 'kvb')->sole();

    $this->artisan('news:scrape')->assertSuccessful();

    $again = CityNews::query()->where('source', 'kvb')->sole();
    expect($again->id)->toBe($first->id)
        ->and(app(DisruptionService::class)->openNotices()->pluck('id'))->toContain($first->id);
});

it('keys a KVB news item on its own link when the heading carries one', function () {
    $headings = ['<a href="/aktuelles/meldung-123.html">Bis 23.11.2026: Trennung der Linie 18</a>'];
    $announcements = [];
    fakeKvb($headings, $announcements);
    $this->artisan('news:scrape')->assertSuccessful();

    expect(CityNews::query()->where('source', 'kvb')->sole()->source_url)->toBe('https://www.kvb.koeln/aktuelles/meldung-123.html');
});

it('starts a Betriebslage notice afresh when it returns after a real gap, not after a missed scrape', function (int $hoursAway, bool $fresh) {
    $headings = [];
    $announcements = [['5', 'Umleitung wegen Bauarbeiten', 'Ersatzhaltestelle Rathaus']];
    fakeKvb($headings, $announcements);
    $this->artisan('news:scrape')->assertSuccessful();
    $row = CityNews::query()->where('source', 'kvb-betriebslage')->sole();
    $startedAt = $row->created_at;

    // It leaves the page, then comes back after a while.
    $announcements = [];
    $this->artisan('news:scrape')->assertSuccessful();
    $this->travel($hoursAway)->hours();
    $announcements = [['5', 'Umleitung wegen Bauarbeiten', 'Ersatzhaltestelle Rathaus']];
    $this->artisan('news:scrape')->assertSuccessful();

    $row->refresh();
    expect($row->expires_at->greaterThan(now()))->toBeTrue()
        ->and($row->created_at->equalTo($startedAt))->toBe(! $fresh);
})->with([
    'missed for an hour' => [1, false],
    'back two days later' => [48, true],
]);
