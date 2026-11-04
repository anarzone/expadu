<?php

use App\Models\Event;
use App\Support\EventAttractions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** A listing on each day from $first to $last, 10:00–18:00; days before today are over. */
function dailyListing(string $title, string $first, string $last): array
{
    $ids = [];
    for ($day = CarbonImmutable::parse($first); $day <= CarbonImmutable::parse($last); $day = $day->addDay()) {
        $ids[] = Event::factory()->create(['title' => $title, 'source' => 'koeln.de', 'recurrence' => null, 'venue_id' => null, 'location_name' => 'Museum',
            'starts_at' => $day->setTime(10, 0), 'ends_at' => $day->setTime(18, 0),
            'status' => $day->isPast() ? 'expired' : 'active', 'tags' => []])->id;
    }

    return $ids;
}

test('an exhibition running into winter stays an attraction, and a Christmas market keeps its days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Europe/Berlin'));
    Cache::forget('events:attractions');
    $exhibition = dailyListing('Kollwitz exhibition', '2026-10-20', '2026-11-30');
    $market = dailyListing('Christmas market', '2026-11-05', '2026-12-23');

    $ids = app(EventAttractions::class)->ids();

    expect(array_intersect($exhibition, $ids))->not->toBeEmpty()
        ->and(array_intersect($market, $ids))->toBeEmpty();
});
