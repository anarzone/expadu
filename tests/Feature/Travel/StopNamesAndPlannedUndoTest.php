<?php

use App\Composer\Actions\ComposerActions;
use App\Day\PlannedTrips;
use App\Models\FavouriteStop;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Services\KvbApiService;
use App\Transit\FavouriteStops;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
 * Which KVB stop a name means (session 3's review, 24 Oct 2026): as written, with umlauts
 * spelt out or not, else the only one a part of at least four letters fits; several that fit
 * are asked about, never one guessed. And Undo puts a planned way back as it was.
 */

/** KVB's stop list as the app reads it: these names, or none when it can't be read. */
function kvbStopsAre(array $names): void
{
    app()->instance(KvbApiService::class, new class($names) extends KvbApiService
    {
        public function __construct(private array $names) {}

        public function getStops(): array
        {
            return array_map(fn (string $name, int $i): array => ['id' => $i + 1, 'name' => $name, 'short' => '', 'area' => 'STRAB', 'lines' => ['1'],
                'lat' => 50.93, 'lng' => 6.95], $this->names, array_keys($this->names));
        }
    });
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00', 'Europe/Berlin'));
    kvbStopsAre(['Neumarkt', 'Mülheim Wiener Platz', 'Mülheim Berliner Str.', 'Ebertplatz', 'Rudolfplatz', 'Barbarossaplatz', 'Gürzenich']);
});

test('a stop is the one written that way, umlauts spelt out or not, else the only one a part fits', function () {
    $stops = app(FavouriteStops::class);

    expect($stops->stations('neumarkt'))->toBe(['Neumarkt'])
        ->and($stops->stations('Muelheim Wiener Platz'))->toBe(['Mülheim Wiener Platz'])
        ->and($stops->stations('Gurzenich'))->toBe(['Gürzenich'])
        ->and($stops->stations('Ebertpl'))->toBe(['Ebertplatz'])
        // Too short to mean one stop; several that fit are all offered.
        ->and($stops->stations('str'))->toBe([])
        ->and($stops->stations('Muelheim'))->toBe(['Mülheim Wiener Platz', 'Mülheim Berliner Str.'])
        ->and(count($stops->stations('platz')))->toBeGreaterThan(1);
});

test('Composer stars only the stop meant: several that fit are asked about, an unreadable list is said so', function () {
    $user = User::factory()->onboarded()->create();
    $actions = app(ComposerActions::class);

    $several = $actions->request($user, 'save_stop', ['stop' => 'platz'], CarbonImmutable::now());
    $one = $actions->request($user, 'save_stop', ['stop' => 'Muelheim Wiener Platz'], CarbonImmutable::now());
    expect($several['blocks'])->toBe([])->and($several['said'])->toContain('Several KVB stops fit')
        ->and($one['blocks'][0]['text'])->toBe('Mülheim Wiener Platz is a favourite stop.')
        ->and(FavouriteStop::query()->where('user_id', $user->id)->pluck('name')->all())->toBe(['Mülheim Wiener Platz']);

    kvbStopsAre([]);
    $unread = $actions->request($user, 'save_stop', ['stop' => 'Neumarkt'], CarbonImmutable::now());
    expect($unread['said'])->toContain('can’t be read right now')
        ->and(fn () => app(FavouriteStops::class)->add($user->id, 'Neumarkt'))->toThrow(ValidationException::class, FavouriteStops::UNREADABLE);
});

test('Undo puts a planned way back as it was, a reminder already sent included, so it isn’t sent again', function () {
    $user = User::factory()->onboarded()->create();
    $journey = fn (string $leave, string $there): array => ['depart_at' => "2026-11-05T{$leave}:00+01:00", 'arrive_at' => "2026-11-05T{$there}:00+01:00",
        'legs' => [['mode' => 'walk', 'depart_at' => "2026-11-05T{$leave}:00+01:00", 'arrive_at' => "2026-11-05T{$there}:00+01:00"]]];
    $trip = PlannedTrip::query()->create(['user_id' => $user->id, 'date' => '2026-11-05', 'target_id' => 'plan:1', 'title' => 'Coffee',
        'journey' => $journey('16:40', '17:00'), 'depart_at' => CarbonImmutable::parse('2026-11-05 16:40', 'Europe/Berlin'),
        'arrive_at' => CarbonImmutable::parse('2026-11-05 17:00', 'Europe/Berlin'), 'remind' => true,
        'reminded_at' => CarbonImmutable::parse('2026-11-05 16:35', 'Europe/Berlin'), 'alerted_kind' => 'late', 'alerted_at' => CarbonImmutable::parse('2026-11-05 16:20', 'Europe/Berlin')]);
    $planned = app(PlannedTrips::class);
    $before = $planned->snapshot($trip);

    // A new way, as keep makes it: a fresh reminder, watched afresh.
    $trip->update(['journey' => $journey('16:50', '17:10'), 'depart_at' => CarbonImmutable::parse('2026-11-05 16:50', 'Europe/Berlin'),
        'reminded_at' => null, 'alerted_kind' => null, 'alerted_at' => null]);
    $planned->restore($user, '2026-11-05', 'plan:1', $before);

    $back = PlannedTrip::query()->where('user_id', $user->id)->sole();
    expect($back->depart_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('16:40')
        ->and($back->journey['depart_at'])->toBe('2026-11-05T16:40:00+01:00')
        ->and($back->reminded_at?->setTimezone('Europe/Berlin')->format('H:i'))->toBe('16:35')
        ->and($back->alerted_kind)->toBe('late');
});
