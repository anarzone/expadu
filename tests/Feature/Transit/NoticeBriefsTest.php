<?php

use App\Jobs\WriteNoticeBriefJob;
use App\Models\CityNews;
use App\Models\NoticeBrief;
use App\Services\DisruptionService;
use App\Transit\NoticeBriefWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function kvbNotice(string $title, string $details): CityNews
{
    return CityNews::query()->create([
        'title' => $title, 'summary' => $details, 'source' => 'kvb-betriebslage',
        'source_url' => 'https://www.kvb.koeln/fahrtinfo/betriebslage/#'.md5($title.$details), 'category' => 'transit',
        'relevance' => 'transit', 'affected_lines' => [preg_replace('/\D/', '', $title)], 'severity' => 'moderate',
        'published_at' => now(), 'expires_at' => now()->addHours(2),
    ]);
}

function fakeBrief(string $headline, string $body, string $impact = 'partial_service'): void
{
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response([
        'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'name' => 'write_brief', 'input' => compact('headline', 'body', 'impact')]],
        'usage' => ['input_tokens' => 420, 'output_tokens' => 80],
    ])]);
}

beforeEach(function () {
    config(['services.anthropic.key' => 'test-key', 'services.transit_briefs.enabled' => true]);
});

test('a notice gets a grounded English brief, shared by the lines KVB lists it under', function () {
    $details = 'Linie 16 fährt zwischen (H) Niehl Sebastianstr. und (H) Ubierring. Linie 17 - kein Bahnverkehr. Nutzen Sie zwischen (H) Ubierring und (H) Bonn Hauptbahnhof die Ersatzbusse der Linie 116.';
    $sixteen = kvbNotice('Linie 16: Baumaßnahme im Bereich HGK-SWB bis 02.11. ca. 03:00h.', $details);
    kvbNotice('Linie 17: Baumaßnahme im Bereich HGK-SWB bis 02.11. ca. 03:00h.', $details);
    fakeBrief('No trams between Ubierring and Bonn Hauptbahnhof until about 03:00 on 2 Nov',
        'Use replacement bus 116 between Ubierring and Bonn Hauptbahnhof. The notice gives no reason.');

    expect(app(NoticeBriefWriter::class)->write($sixteen))->not->toBeNull();

    $notices = collect(app(DisruptionService::class)->getLineDisruptions());
    expect(NoticeBrief::count())->toBe(1)
        ->and($notices->pluck('brief.headline')->unique()->all())->toBe(['No trams between Ubierring and Bonn Hauptbahnhof until about 03:00 on 2 Nov'])
        ->and($notices->first()['brief']['text'])->toBe('Use replacement bus 116 between Ubierring and Bonn Hauptbahnhof.')
        ->and($notices->first()['title'])->toStartWith('Linie 1'); // the notice itself is unchanged
    Http::assertSent(fn ($request) => $request['model'] === 'claude-haiku-5-5'
        && $request['output_config']['effort'] === 'low'
        && str_contains($request['messages'][0]['content'], '<source_vehicle>tram</source_vehicle>')
        && ! str_contains($request['messages'][0]['content'], 'Linie 16:'));
});

test('a brief that adds what the notice does not say is thrown away and the German notice shows', function (string $headline) {
    $notice = kvbNotice('Bus 173: Baumaßnahme im Bereich der (H) Hans-Böckler-Platz.',
        'Die (H) Hans-Böckler-Platz ist in Richtung (H) Weiden auf die Venloer Straße vor Hausnummer 26-28 verlegt.');
    fakeBrief($headline, 'Look for the stop at its new place.', 'stop_moved');

    expect(app(NoticeBriefWriter::class)->write($notice))->toBeNull()
        ->and(collect(app(DisruptionService::class)->getLineDisruptions())->first()['brief'])->toBeNull();
})->with([
    'a made-up time' => ['Hans-Böckler-Platz stop moved until 18:00'],
    'a misspelt street' => ['Hans-Böckler-Platz stop moved to Venloer Straase 26-28'],
    'trams on a bus notice' => ['Trams no longer stop at Hans-Böckler-Platz'],
]);

test('the brief command queues one job per notice text, and not without a key, the switch, or past the daily cap', function () {
    Queue::fake();
    kvbNotice('Bus 130: Baumaßnahmen im Bereich Sülzgürtel.', 'Die (H) Sülzgürtel in Richtung Universität ist verlegt.');
    kvbNotice('Bus 134: Baumaßnahmen im Bereich Sülzgürtel.', 'Die (H) Sülzgürtel in Richtung Universität ist verlegt.');
    kvbNotice('Bus 141: Baumaßnahmen im Bereich Silbermöwenweg.', 'Die (H) Silbermöwenweg wird in beide Richtungen nicht angefahren.');

    config(['services.anthropic.key' => null]);
    $this->artisan('transit:brief-notices')->assertSuccessful();
    config(['services.anthropic.key' => 'test-key', 'services.transit_briefs.enabled' => false]);
    $this->artisan('transit:brief-notices')->assertSuccessful();
    Queue::assertNothingPushed();

    config(['services.transit_briefs.enabled' => true, 'services.transit_briefs.daily_limit' => 1]);
    $this->artisan('transit:brief-notices')->assertSuccessful();
    Queue::assertPushed(WriteNoticeBriefJob::class, 1);

    config(['services.transit_briefs.daily_limit' => 300]);
    $this->artisan('transit:brief-notices')->assertSuccessful();
    // Two distinct texts in all; the unique lock keeps the first from being queued twice.
    Queue::assertPushed(WriteNoticeBriefJob::class, 2);
});
