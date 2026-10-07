<?php

use App\Bureaucracy\Facts\ConfirmedBureaucracyAttributes;
use App\Models\User;
use App\Profile\ProfileEngine;

function unknownFactUser(array $attributes = []): User
{
    $user = new User;
    $user->setDateFormat('Y-m-d H:i:s');
    $user->forceFill($attributes);

    return $user;
}

test('an empty profile contributes no guessed bureaucracy identity', function () {
    $attributes = (new ConfirmedBureaucracyAttributes(new ProfileEngine))->forUser(unknownFactUser());

    expect($attributes)->toMatchArray([
        'citizenship_group' => null,
        'purpose' => null,
        'business_type' => null,
        'permit_track' => null,
        'sponsor' => null,
        'entry_mode' => null,
        'current_residence_title' => null,
    ]);
});

test('employment and family labels do not replace an unanswered citizenship question', function (string $situation) {
    $attributes = (new ConfirmedBureaucracyAttributes(new ProfileEngine))->forUser(unknownFactUser([
        'situation' => $situation,
        'is_eu' => null,
    ]));

    expect($attributes['citizenship_group'])->toBeNull();
})->with(['student', 'freelancer', 'other', 'family_reunification', 'non_eu_employee', 'eu_employee']);

test('explicit citizenship is retained without changing the general discovery profile', function (bool $isEu, string $group) {
    $user = unknownFactUser(['situation' => 'student', 'is_eu' => $isEu]);
    $attributes = (new ConfirmedBureaucracyAttributes(new ProfileEngine))->forUser($user);

    expect($attributes['citizenship_group'])->toBe($group)
        ->and($attributes['purpose'])->toBe('study');
})->with([[true, 'eu'], [false, 'non_eu']]);

test('a branch choice is not proof of an existing title sponsor or business classification', function (string $situation, string $path) {
    $attributes = (new ConfirmedBureaucracyAttributes(new ProfileEngine))->forUser(unknownFactUser([
        'situation' => $situation,
        'bureaucracy_path' => $path,
    ]));

    expect($attributes['permit_track'])->toBeNull()
        ->and($attributes['sponsor'])->toBeNull()
        ->and($attributes['business_type'])->toBeNull()
        ->and($attributes['current_residence_title'])->toBeNull();
})->with([
    ['non_eu_employee', 'non_eu_employee_blue_card'],
    ['family_reunification', 'family_reunification_of_german'],
    ['freelancer', 'freelancer_gewerbe'],
]);

test('explicit stored answers are reusable without interpreting unrelated profile fields', function () {
    $answers = [
        'business_type' => 'gewerbe',
        'entry_mode' => 'd_visa',
        'current_residence_title' => 'national_d_visa',
        'visa_expires_at' => '2026-09-10',
    ];
    $attributes = (new ConfirmedBureaucracyAttributes(new ProfileEngine))->forUser(unknownFactUser([
        'situation' => 'freelancer',
        'profile_attributes' => $answers + ['unrecognised_key' => 'do not copy'],
    ]));

    expect($attributes)->toMatchArray($answers)->not->toHaveKey('unrecognised_key');
});

test('a general settled declaration is not a confirmed permanent residence title', function () {
    $attributes = (new ConfirmedBureaucracyAttributes(new ProfileEngine))->forUser(unknownFactUser([
        'profile_attributes' => ['settled_at' => '2026-01-01'],
    ]));

    expect($attributes['current_residence_title'])->toBeNull();
});
