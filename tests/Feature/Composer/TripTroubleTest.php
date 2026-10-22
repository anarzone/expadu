<?php

use App\Composer\Answers\TripTrouble;
use App\Models\CityNews;
use App\Models\NoticeBrief;
use Illuminate\Support\Facades\Http;

// No live boards and no KVB stop list here: the trip's own lines are what's checked (TripDisruptions has its own tests).
beforeEach(fn () => Http::fake());

/*
 * A major problem on a trip's way leads Composer's trip answer, in the classified data's
 * own words (the delays canvas, 22 Oct 2026): a notice by its checked brief, never the
 * model's words; an everyday one stays out of the headline.
 */

/** A trip from Neumarkt on line 4, as the trip answer carries it. */
function troubleJourney(): array
{
    $at = now()->toIso8601String();
    $leg = fn (string $mode, ?string $line, string $from, string $to): array => ['mode' => $mode, 'line' => $line,
        'from' => ['name' => $from, 'lat' => 50.93, 'lng' => 6.94], 'to' => ['name' => $to, 'lat' => 50.96, 'lng' => 7.0],
        'depart_at' => $at, 'arrive_at' => $at, 'duration_min' => 5];

    return ['depart_at' => $at, 'arrive_at' => $at, 'duration_min' => 20, 'transfers' => 0,
        'legs' => [$leg('walk', null, 'Start', 'Köln Neumarkt'), $leg('tram', '4', 'Köln Neumarkt', 'Köln Wiener Platz'), $leg('walk', null, 'Köln Wiener Platz', 'There')]];
}

function troubleNotice(string $title, ?string $summary, array $lines, string $severity = 'moderate'): CityNews
{
    return CityNews::query()->create(['title' => $title, 'summary' => $summary, 'source' => 'kvb-betriebslage', 'source_url' => 'https://www.kvb.koeln/#'.uniqid(),
        'category' => 'transit', 'relevance' => 'transit', 'affected_lines' => $lines, 'severity' => $severity, 'published_at' => now(), 'expires_at' => now()->addHours(3)]);
}

test('a major notice on a line the trip rides leads it, in its checked brief, with the German behind it', function () {
    $notice = troubleNotice('Linie 4: Unterbrechung zwischen Neumarkt und Wiener Platz', 'Ersatzbusse sind eingerichtet.', ['4']);
    NoticeBrief::query()->create(['input_hash' => NoticeBrief::hashFor($notice->title, $notice->summary), 'locale' => 'en', 'kind' => 'brief',
        'headline' => 'Line 4 is interrupted between Neumarkt and Wiener Platz', 'body' => 'Replacement buses run.', 'impact' => 'suspended',
        'model' => 'claude-haiku-5-5', 'prompt_version' => 'test']);

    expect(app(TripTrouble::class)->major(troubleJourney(), 'Köln'))->toBe([
        'title' => 'Line 4 is interrupted between Neumarkt and Wiener Platz', 'text' => 'Replacement buses run.', 'todo' => null, 'source' => 'notice',
        'details' => "Linie 4: Unterbrechung zwischen Neumarkt und Wiener Platz\nErsatzbusse sind eingerichtet.",
    ]);
});

test('an everyday notice, or a major one on another line, is no headline', function () {
    troubleNotice('Linie 4: Haltestelle Neumarkt verlegt', null, ['4']);
    troubleNotice('Linie 16: Unterbrechung', 'Ersatzbusse.', ['16']);

    expect(app(TripTrouble::class)->major(troubleJourney(), 'Köln'))->toBeNull();
});
