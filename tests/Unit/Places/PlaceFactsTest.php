<?php

use App\Places\SourceTagFacts as PlaceFacts;

test('place fees require explicit unconditional source evidence', function (array $tags, ?string $price, string $expected) {
    expect((new PlaceFacts($tags))->costTier($price))->toBe($expected);
})->with([
    'unknown fee' => [[], null, 'unknown'],
    'explicit free' => [['fee' => 'no'], null, 'free'],
    'paid' => [['fee' => 'yes'], null, 'normal'],
    'conditional fee' => [['fee' => 'no', 'fee:conditional' => 'yes @ (Sa-Su)'], null, 'unknown'],
    'conditional charge' => [['fee' => 'no', 'charge:conditional' => '5 EUR @ (Sa)'], null, 'unknown'],
    'conflicting charge' => [['fee' => 'no', 'charge' => '5 EUR'], null, 'unknown'],
    'conflicting zero charge' => [['fee' => 'yes', 'charge' => '0'], null, 'unknown'],
    'conflicting price' => [['fee' => 'no'], '€€', 'unknown'],
    'malformed fee' => [['fee' => ['no']], null, 'unknown'],
    'malformed conditional fee' => [['fee' => 'no', 'fee:conditional' => ['yes']], null, 'unknown'],
    'known low price' => [[], '€', 'low'],
    'known normal price' => [[], '€€', 'normal'],
]);

test('access and arrangements remain separate from fee', function (array $tags, string $access, bool $arrangement) {
    $facts = new PlaceFacts($tags);
    expect($facts->accessStatus())->toBe($access)
        ->and($facts->requiresArrangement())->toBe($arrangement);
})->with([
    'free is not public' => [['fee' => 'no'], 'unknown', false],
    'public is not free' => [['access' => 'yes'], 'public', false],
    'permission may be withdrawn' => [['access' => 'permissive'], 'permissive', false],
    'private' => [['access' => 'private', 'fee' => 'no'], 'restricted', false],
    'members only' => [['access' => 'members'], 'restricted', false],
    'conditional access' => [['access' => 'yes', 'access:conditional' => 'private @ (Mo-Fr)'], 'conditional', false],
    'book ahead' => [['access' => 'yes', 'reservation' => 'required'], 'public', true],
    'optional reservations' => [['access' => 'yes', 'reservation' => 'yes'], 'public', false],
    'recommended reservations' => [['access' => 'yes', 'reservation' => 'recommended'], 'public', false],
    'member-only reservations do not restrict visiting' => [['access' => 'yes', 'reservation' => 'members_only'], 'public', false],
]);
