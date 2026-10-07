<?php

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function deadlineFixtureUser(): User
{
    $user = new User;
    $user->setDateFormat('Y-m-d H:i:s');
    $user->setRawAttributes(['arrival_date' => '2026-08-01 00:00:00']);

    return $user;
}

function deadlineFixtureTask(string $type = 'permit_window', ?int $days = 90): Task
{
    $task = new Task;
    $task->setRawAttributes([
        'deadline_type' => $type,
        'deadline_days' => $days,
        'deadline_fact_key' => 'residence_title_expires_at',
    ]);

    return $task;
}

test('an unanswered or unsupported entry mode never creates a visa-free deadline', function (array $attributes) {
    expect(deadlineFixtureTask()->computeDeadlineFor(deadlineFixtureUser(), $attributes))->toBeNull();
})->with([
    'omitted' => [[]],
    'explicit unknown' => [['entry_mode' => null]],
    'empty answer' => [['entry_mode' => '']],
    'existing permit' => [['entry_mode' => 'has_permit']],
    'unsupported answer' => [['entry_mode' => 'unsupported']],
]);

test('an explicit visa-free entry retains its authored fixture window', function () {
    expect(deadlineFixtureTask()->computeDeadlineFor(deadlineFixtureUser(), ['entry_mode' => 'visa_free'])?->toDateString())
        ->toBe('2026-10-30');
});

test('a national visa uses its recorded expiry rather than the arrival window', function () {
    expect(deadlineFixtureTask()->computeDeadlineFor(deadlineFixtureUser(), [
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-09-10',
    ])?->toDateString())->toBe('2026-09-10');
});

test('a national visa with unknown expiry has no computed deadline', function () {
    expect(deadlineFixtureTask()->computeDeadlineFor(deadlineFixtureUser(), ['entry_mode' => 'd_visa']))->toBeNull();
});

test('an invalid visa expiry is never normalised into another deadline', function (mixed $expiry) {
    expect(deadlineFixtureTask()->computeDeadlineFor(deadlineFixtureUser(), [
        'entry_mode' => 'd_visa',
        'visa_expires_at' => $expiry,
    ]))->toBeNull();
})->with([
    'non-leap February' => '2026-02-29',
    'impossible day' => '2026-02-30',
    'relative date' => 'tomorrow',
    'partial day' => '2026-09-1',
    'timestamp instead of a calendar date' => '2026-09-10T12:00:00Z',
    'boolean' => true,
]);

test('a valid leap-day expiry remains exact', function () {
    expect(deadlineFixtureTask()->computeDeadlineFor(deadlineFixtureUser(), [
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2028-02-29',
    ])?->toDateString())->toBe('2028-02-29');
});

test('malformed move-in anchors never become calculated deadlines', function (mixed $anchor) {
    expect(deadlineFixtureTask('days_since_move_in', 14)->computeDeadlineFor(deadlineFixtureUser(), [
        'moved_in_at' => $anchor,
    ]))->toBeNull();
})->with(['2026-02-30', 'next Monday', '2026-09-1', true]);

test('a valid move-in deadline crosses the month boundary correctly', function () {
    expect(deadlineFixtureTask('days_since_move_in', 14)->computeDeadlineFor(deadlineFixtureUser(), [
        'moved_in_at' => '2026-08-31',
    ])?->toDateString())->toBe('2026-09-14');
});

test('an impossible explicit fact date has no deadline', function () {
    expect(deadlineFixtureTask('fact_date', null)->computeDeadlineFor(deadlineFixtureUser(), [
        'residence_title_expires_at' => '2026-02-30',
    ]))->toBeNull();
});

test('an explicit visa expiry does not need an unrelated day offset', function () {
    expect(deadlineFixtureTask('permit_window', null)->computeDeadlineFor(deadlineFixtureUser(), [
        'entry_mode' => 'd_visa', 'visa_expires_at' => '2026-09-10',
    ])?->toDateString())->toBe('2026-09-10');
});

test('event anchors require a real date instead of relative or impossible input', function (mixed $anchor) {
    $task = deadlineFixtureTask('days_since_event', 14);
    $task->trigger_event = 'child_born';

    expect($task->computeDeadlineFor(deadlineFixtureUser(), ['child_born_at' => $anchor]))->toBeNull();
})->with(['2026-02-30', 'tomorrow', '2026-09-1', true]);

test('a zero-day fixture offset remains a real deadline on its anchor date', function () {
    expect(deadlineFixtureTask('days_since_move_in', 0)->computeDeadlineFor(deadlineFixtureUser(), [
        'moved_in_at' => '2026-09-01',
    ])?->toDateString())->toBe('2026-09-01');
});

test('a future move or completed-event date is not an actual historical anchor', function (string $type, string $key) {
    $task = deadlineFixtureTask($type, 14);
    $task->trigger_event = 'child_born';
    expect($task->computeDeadlineFor(deadlineFixtureUser(), [$key => '2026-09-08']))->toBeNull();
})->with([['days_since_move_in', 'moved_in_at'], ['days_since_event', 'child_born_at']]);

test('a planned arrival cannot start a deadline for an actual arrival', function () {
    $user = deadlineFixtureUser();
    $user->arrival_date = '2026-10-01';
    expect(deadlineFixtureTask('days_since_arrival', 14)->computeDeadlineFor($user, []))->toBeNull()
        ->and(deadlineFixtureTask()->computeDeadlineFor($user, ['entry_mode' => 'visa_free']))->toBeNull();
});

test('a card expiry cannot become expiry of an unlimited legal title', function (string $title) {
    expect(deadlineFixtureTask('fact_date', null)->computeDeadlineFor(deadlineFixtureUser(), [
        'current_residence_title' => $title, 'residence_title_expires_at' => '2026-09-10',
    ]))->toBeNull();
})->with(['settlement_permit_9', 'settlement_permit_18c', 'settlement_permit_unknown']);
