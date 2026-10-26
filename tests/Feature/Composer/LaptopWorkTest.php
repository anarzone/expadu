<?php

use App\Composer\Answers\AroundCheck;
use App\Composer\Assistant\AssistantTools;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Models\Spot;
use App\Models\User;
use App\Places\PlaceWorkAnswers;
use Carbon\CarbonImmutable;

/*
 * "Good for laptop work?" in Composer (owner, 23 Oct 2026: build it): people's answers reach the
 * AI only as their anonymous counts, cited as theirs; a website rule as the website's; nothing
 * listed, said so.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Europe/Berlin'));
    $this->spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'veedel' => 'Mülheim', 'is_active' => true, 'lat' => 50.96, 'lng' => 7.0]);
});

function laptopCandidate(Spot $spot)
{
    return app(CandidateRepository::class)->byIds(["spot:{$spot->id}"], CarbonImmutable::now())[0];
}

function peopleAnswer(Spot $spot, array $laptops): array
{
    return array_map(function (string $laptop) use ($spot): User {
        $user = User::factory()->onboarded()->create(['name' => 'Answerer '.Str::random(6), 'created_at' => now()->subWeek()]);
        app(PlaceWorkAnswers::class)->save($user, $spot, ['laptop' => $laptop]);

        return $user;
    }, $laptops);
}

test('🔒 people’s answers are said as theirs, with counts only, never who', function () {
    $people = peopleAnswer($this->spot, ['yes', 'yes', 'yes', 'no']);

    $said = AssistantTools::laptopSaid(laptopCandidate($this->spot));

    expect($said)->toBe("People who've been there say it's good for laptop work (3 of 4).");
    foreach ($people as $person) {
        // Small ids read like counts ("3 of 4"); names and emails never appear.
        expect($said)->not->toContain($person->name)->not->toContain($person->email);
    }
});

test('mixed answers are said as mixed and kept, after places that are good for work', function () {
    peopleAnswer($this->spot, ['yes', 'no', 'yes', 'no']);
    $candidate = laptopCandidate($this->spot);

    expect(AssistantTools::laptopSaid($candidate))->toBe("People who've been there are mixed on whether it's good for laptop work (2 of 4 said yes).")
        ->and(AssistantTools::laptopsWelcome($candidate, CarbonImmutable::now()))->toBeNull();
});

test('below three people, or with nothing listed, laptop work isn’t listed yet', function () {
    peopleAnswer($this->spot, ['yes', 'yes']);

    expect(AssistantTools::laptopSaid(laptopCandidate($this->spot)))->toBe("Laptop work isn't listed yet.");
});

test('a website’s weekend rule is a ban (no @) or some areas only (limited @), on its days alone', function () {
    $website = ['provider' => 'website', 'source_url' => 'https://example.test/', 'status' => 'known'];
    $rule = fn (string $condition) => new Candidate(id: 'spot:1', type: 'spot', name: 'Treibgut', lat: 50.9, lng: 6.9, veedel: null,
        category: 'cafe', outdoor: false, typicalDurationMin: 60, costTier: 'unknown', opensAt: null, closesAt: null,
        placeFacts: ['practical' => ['laptops' => [...$website, 'value' => 'limited'], 'laptops:conditional' => [...$website, 'value' => $condition]]]);
    $saturday = CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Berlin');
    $wednesday = CarbonImmutable::parse('2026-10-21 12:00', 'Europe/Berlin');

    expect(AssistantTools::laptopsWelcome($rule('no @ (Sa-Su)'), $saturday))->toBeFalse()
        ->and(AssistantTools::laptopsWelcome($rule('no @ (Sa-Su)'), $wednesday))->toBeTrue()
        ->and(AssistantTools::laptopSaid($rule('no @ (Sa-Su)')))->toBe("Its website says laptops aren't allowed at weekends.")
        ->and(AssistantTools::laptopsWelcome($rule('limited @ (Sa-Su)'), $saturday))->toBeNull()
        ->and(AssistantTools::laptopSaid($rule('limited @ (Sa-Su)')))->toBe('Its website says laptops are only allowed in some areas at weekends.');
});

test('a website rule\'s English note is said as being of laptops, and backs only what it says', function () {
    $website = ['provider' => 'website', 'source_url' => 'https://example.test/', 'status' => 'known'];
    $wohnraum = new Candidate(id: 'spot:1', type: 'spot', name: 'Café Wohnraum', lat: 50.96, lng: 6.95, veedel: 'Nippes',
        category: 'cafe', outdoor: false, typicalDurationMin: 60, costTier: 'unknown', opensAt: null, closesAt: null,
        placeFacts: ['practical' => ['laptops' => [...$website, 'value' => 'limited', 'note' => 'Weekdays are welcome, with a €10 minimum spend.'],
            'laptops:conditional' => [...$website, 'value' => 'no @ (Sa-Su)']]]);
    $said = AssistantTools::laptopSaid($wohnraum);
    $check = app(AroundCheck::class);

    expect($said)->toBe('Its website says laptops aren\'t allowed at weekends. Of laptops, it also says: "Weekdays are welcome, with a €10 minimum spend."')
        ->and($check->holds('Its website says laptops are welcome on weekdays, with a €10 minimum spend.', $said))->toBeTrue()
        ->and($check->holds('Its website says laptops are welcome every day.', $said))->toBeFalse();
});
