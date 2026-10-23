<?php

use App\Composer\Assistant\AssistantTools;
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
