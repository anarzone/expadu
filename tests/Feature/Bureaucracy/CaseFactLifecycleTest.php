<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Test helpers
|--------------------------------------------------------------------------
|
| The Task 2 persistence layer (LegacyFactBootstrapper, CaseFactStore and
| the BureaucracyCase/BureaucracyCaseFact/BureaucracyFactConflict models plus
| their factories and migrations) does not exist yet. These tests pin down the
| exact service contract first so the upcoming implementation can be driven
| to GREEN without renegotiating behaviour. Only the User factory (already
| present) is used directly; cases are always obtained through bootstrap().
|
*/

/**
 * Build an onboarded legacy user whose ProfileEngine attributes resolve to
 * deterministic confirmed facts.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $profileAttributes
 */
function bureaucracyUser(array $attributes = [], array $profileAttributes = []): User
{
    return User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'german_level' => 'b1',
        'profile_attributes' => [
            'visa_expires_at' => '2027-06-01',
            'entry_mode' => 'd_visa',
            ...$profileAttributes,
        ],
        ...$attributes,
    ]);
}

/** Build the real, container-resolved store under test. */
function caseFactStore(): CaseFactStore
{
    return app(CaseFactStore::class);
}

/** Build the real, container-resolved bootstrapper under test. */
function legacyFactBootstrapper(): LegacyFactBootstrapper
{
    return app(LegacyFactBootstrapper::class);
}

/*
|--------------------------------------------------------------------------
| Legacy bootstrap: create/reuse the single active case
|--------------------------------------------------------------------------
*/

test('bootstrap creates confirmed legacy_profile facts for deterministically resolvable values', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());

    expect($case)->toBeInstanceOf(BureaucracyCase::class);
    expect($case->user_id)->toBeInt();

    $citizenship = caseFactStore()->confirmedFact($case, 'citizenship_group');
    $purpose = caseFactStore()->confirmedFact($case, 'purpose');
    $permitTrack = caseFactStore()->confirmedFact($case, 'permit_track');

    expect($citizenship)->not->toBeNull();
    expect($citizenship->state)->toBe('confirmed');
    expect($citizenship->source)->toBe('legacy_profile');
    expect($citizenship->value)->toBe('non_eu');

    expect($purpose)->not->toBeNull();
    expect($purpose->value)->toBe('employment');

    expect($permitTrack)->not->toBeNull();
    expect($permitTrack->value)->toBe('standard');
});

test('bootstrap confirms german_level and a non-null visa_expires_at from legacy data', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());

    $germanLevel = caseFactStore()->confirmedFact($case, 'german_level');
    expect($germanLevel)->not->toBeNull();
    expect($germanLevel->state)->toBe('confirmed');
    expect($germanLevel->source)->toBe('legacy_profile');
    expect($germanLevel->value)->toBe('b1');

    $visaExpires = caseFactStore()->confirmedFact($case, 'visa_expires_at');
    expect($visaExpires)->not->toBeNull();
    expect($visaExpires->state)->toBe('confirmed');
    expect($visaExpires->value)->toBe('2027-06-01');
});

test('bootstrap confirms an explicit legacy entry_mode', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser([], ['entry_mode' => 'd_visa']));

    $entryMode = caseFactStore()->confirmedFact($case, 'entry_mode');
    expect($entryMode)->not->toBeNull();
    expect($entryMode->state)->toBe('confirmed');
    expect($entryMode->source)->toBe('legacy_profile');
    expect($entryMode->value)->toBe('d_visa');
});

test('an absent legacy entry_mode stays unknown instead of confirming the ProfileEngine visa_free fallback', function () {
    $user = bureaucracyUser([], []);
    $profileAttributes = $user->profile_attributes ?? [];
    unset($profileAttributes['entry_mode']);
    $user->update(['profile_attributes' => $profileAttributes]);

    $case = legacyFactBootstrapper()->bootstrap($user);

    expect(caseFactStore()->confirmedFact($case, 'entry_mode'))->toBeNull();
});

test('bootstrap is idempotent: same case id, fact count and version on a second run', function () {
    $user = bureaucracyUser();

    $first = legacyFactBootstrapper()->bootstrap($user);
    $firstId = $first->id;
    $firstFactCount = BureaucracyCaseFact::where('case_id', $first->id)->count();
    $firstVersion = $first->fresh()->fact_version;

    $second = legacyFactBootstrapper()->bootstrap($user);

    expect($second->id)->toBe($firstId);
    expect(BureaucracyCaseFact::where('case_id', $second->id)->count())->toBe($firstFactCount);
    expect($second->fresh()->fact_version)->toBe($firstVersion);
});

test('bootstrap enforces one active case per user', function () {
    $user = bureaucracyUser();

    legacyFactBootstrapper()->bootstrap($user);
    legacyFactBootstrapper()->bootstrap($user);

    expect(BureaucracyCase::where('user_id', $user->id)->count())->toBe(1);
});

test('the database unique user_id constraint rejects a second BureaucracyCase row for the same user', function () {
    $user = bureaucracyUser();
    $case = legacyFactBootstrapper()->bootstrap($user);

    // Bootstrap is idempotent, so a duplicate row can only be introduced by
    // mutating the raw table. Prove the *database* constraint — not the
    // service guard — permits exactly one BureaucracyCase per user by trying
    // to insert a second row that reuses the same user_id (mirroring the
    // bootstrapped row's status so the unique user_id is the only violation).
    expect(function () use ($user, $case) {
        DB::transaction(function () use ($user, $case) {
            BureaucracyCase::create([
                'user_id' => $user->id,
                'status' => $case->status,
            ]);
        });
    })->toThrow(UniqueConstraintViolationException::class);

    expect(BureaucracyCase::where('user_id', $user->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Candidate recording and confirmation
|--------------------------------------------------------------------------
*/

test('a candidate never replaces a confirmed fact while a differing value is pending', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    expect($case->fresh()->fact_version)->toBe(1);

    $before = caseFactStore()->confirmedFact($case, 'citizenship_group');
    expect($before->value)->toBe('non_eu');

    $candidate = caseFactStore()->recordCandidate($case, 'citizenship_group', 'eu', 'onboarding_checklist');

    expect($candidate)->toBeInstanceOf(BureaucracyCaseFact::class);
    expect($candidate->state)->toBe('candidate');

    $conflict = caseFactStore()->confirmCandidate($candidate);

    expect($conflict)->toBeInstanceOf(BureaucracyFactConflict::class);
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group')->value)->toBe('non_eu');
    expect($candidate->fresh()->state)->toBe('candidate');
    expect($case->fresh()->fact_version)->toBe(1);
});

test('an equal candidate confirms without a conflict and becomes the sole confirmed row', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());

    $candidate = caseFactStore()->recordCandidate($case, 'citizenship_group', 'non_eu', 'checklist_reconfirm');

    $conflict = caseFactStore()->confirmCandidate($candidate);

    expect($conflict)->toBeNull();
    expect($candidate->fresh()->state)->toBe('confirmed');
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group')->id)->toBe($candidate->id);
});

test('a differing candidate creates one unresolved conflict leaving the old value authoritative', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());

    $candidate = caseFactStore()->recordCandidate($case, 'citizenship_group', 'eu', 'onboarding_checklist');
    $conflict = caseFactStore()->confirmCandidate($candidate);

    expect($conflict)->toBeInstanceOf(BureaucracyFactConflict::class);
    expect($conflict->fact_key)->toBe('citizenship_group');
    expect($conflict->status)->toBe('unresolved');
    expect($conflict->resolved_fact_id)->toBeNull();
    expect($candidate->fresh()->state)->toBe('candidate');
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group')->value)->toBe('non_eu');
    expect(BureaucracyFactConflict::where('case_id', $case->id)->where('status', 'unresolved')->count())
        ->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Resolving a conflict
|--------------------------------------------------------------------------
*/

test('resolving a conflict toward the candidate confirms it, supersedes the loser, and increments fact_version exactly once', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    expect($case->fresh()->fact_version)->toBe(1);

    $existing = caseFactStore()->confirmedFact($case, 'citizenship_group');
    $candidate = caseFactStore()->recordCandidate($case, 'citizenship_group', 'eu', 'onboarding_checklist');
    $conflict = caseFactStore()->confirmCandidate($candidate);
    expect($conflict)->not->toBeNull();

    $resolved = caseFactStore()->resolveConflict($conflict, $candidate);

    expect($resolved)->toBeInstanceOf(BureaucracyCaseFact::class);
    expect($resolved->id)->toBe($candidate->id);
    expect($candidate->fresh()->state)->toBe('confirmed');
    expect($existing->fresh()->state)->toBe('superseded');
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group')->id)->toBe($candidate->id);

    expect($conflict->fresh()->status)->toBe('resolved');
    expect($conflict->fresh()->resolved_fact_id)->toBe($candidate->id);

    expect($case->fresh()->fact_version)->toBe(2);
});

test('resolving a conflict toward the existing confirmed fact keeps it authoritative and supersedes the candidate', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    expect($case->fresh()->fact_version)->toBe(1);

    $existing = caseFactStore()->confirmedFact($case, 'citizenship_group');
    $candidate = caseFactStore()->recordCandidate($case, 'citizenship_group', 'eu', 'onboarding_checklist');
    $conflict = caseFactStore()->confirmCandidate($candidate);
    expect($conflict)->not->toBeNull();

    $resolved = caseFactStore()->resolveConflict($conflict, $existing);

    expect($resolved->id)->toBe($existing->id);
    expect($existing->fresh()->state)->toBe('confirmed');
    expect($candidate->fresh()->state)->toBe('superseded');
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group')->id)->toBe($existing->id);

    expect($conflict->fresh()->status)->toBe('resolved');
    expect($conflict->fresh()->resolved_fact_id)->toBe($existing->id);

    expect($case->fresh()->fact_version)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Raw fact history is append-only
|--------------------------------------------------------------------------
*/

test('raw fact history stays append-only after resolving a differing candidate conflict', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());

    // Record the exact raw rows that exist before the candidate is introduced.
    $startingFactIds = BureaucracyCaseFact::where('case_id', $case->id)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
    $startingFactCount = count($startingFactIds);
    $startingConflictCount = BureaucracyFactConflict::where('case_id', $case->id)->count();

    $candidate = caseFactStore()->recordCandidate($case, 'citizenship_group', 'eu', 'onboarding_checklist');
    $conflict = caseFactStore()->confirmCandidate($candidate);
    expect($conflict)->toBeInstanceOf(BureaucracyFactConflict::class);

    caseFactStore()->resolveConflict($conflict, $candidate);

    // Resolving supersedes the loser rather than deleting it: every starting
    // fact row must still exist.
    $remainingFactIds = BureaucracyCaseFact::where('case_id', $case->id)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($remainingFactIds)->toBe(array_values(array_merge($startingFactIds, [$candidate->id])));

    // The fact count grew by exactly one (candidate), never by delete-and-
    // reinsert churn.
    expect(BureaucracyCaseFact::where('case_id', $case->id)->count())
        ->toBe($startingFactCount + 1);

    // The conflict is retained in its resolved state, not removed.
    expect(BureaucracyFactConflict::where('case_id', $case->id)->count())
        ->toBe($startingConflictCount + 1);
    expect($conflict->fresh()->status)->toBe('resolved');

    // Explicitly prove nothing was deleted: every starting id and the conflict
    // id are still queryable.
    expect($startingFactIds)->each->toBeIn(BureaucracyCaseFact::where('case_id', $case->id)->pluck('id')->all());
    expect(BureaucracyFactConflict::where('id', $conflict->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reconfirmation expiry
|--------------------------------------------------------------------------
*/

test('a reconfirm_at in the past makes a fact unavailable for high-impact matching only', function () {
    $case = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    $fact = caseFactStore()->confirmedFact($case, 'citizenship_group');

    $fact->update(['reconfirm_at' => now()->subDay()]);

    expect(caseFactStore()->confirmedFact($case, 'citizenship_group', true))->toBeNull();
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group', false))->not->toBeNull();
    expect(caseFactStore()->confirmedFact($case, 'citizenship_group'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Task 2 integrity amendments
|--------------------------------------------------------------------------
*/

test('an invalid explicit legacy enum throws DomainException and atomically leaves no case or fact rows', function () {
    $user = bureaucracyUser([], ['entry_mode' => 'invalid_value']);
    $factCount = BureaucracyCaseFact::count();

    expect(fn () => legacyFactBootstrapper()->bootstrap($user))
        ->toThrow(DomainException::class);

    expect(BureaucracyCase::where('user_id', $user->id)->count())->toBe(0);
    expect(BureaucracyCaseFact::count())->toBe($factCount);
});

test('bootstrap does not re-create or resurrect a legacy fact whose row has been lifecycle-expired to stale', function () {
    $user = bureaucracyUser();
    $case = legacyFactBootstrapper()->bootstrap($user);
    $legacy = caseFactStore()->confirmedFact($case, 'citizenship_group');
    expect($legacy)->not->toBeNull();

    // Later lifecycle expiry: the confirmed legacy row is transitioned out of
    // confirmed without being removed.
    $legacy->update(['state' => 'stale']);

    $again = legacyFactBootstrapper()->bootstrap($user);
    expect($again->id)->toBe($case->id);

    $rows = BureaucracyCaseFact::where('case_id', $case->id)
        ->where('key', 'citizenship_group')
        ->get();

    // No second row is created and no confirmed legacy value is resurrected.
    expect($rows->count())->toBe(1);
    expect($rows->first()->state)->toBe('stale');
    expect($rows->pluck('state'))->not->toContain('confirmed');
});

test('confirmCandidate uses the persisted candidate case, ignoring an unsaved case_id mutation to another case', function () {
    $caseA = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    $caseB = legacyFactBootstrapper()->bootstrap(bureaucracyUser());

    $candidate = caseFactStore()->recordCandidate($caseA, 'citizenship_group', 'eu', 'onboarding_checklist');
    $candidate->case_id = $caseB->id; // unsaved in-memory mutation only

    $conflict = caseFactStore()->confirmCandidate($candidate);

    expect($conflict)->toBeInstanceOf(BureaucracyFactConflict::class);
    expect($conflict->case_id)->toBe($caseA->id);
    expect($candidate->fresh()->case_id)->toBe($caseA->id);

    // Both facts referenced by the conflict belong to persisted case A, and no
    // conflict is attached to B.
    expect(BureaucracyCaseFact::find($conflict->candidate_fact_id)->case_id)->toBe($caseA->id);
    expect(BureaucracyCaseFact::find($conflict->existing_fact_id)->case_id)->toBe($caseA->id);
    expect(BureaucracyFactConflict::where('case_id', $caseB->id)->count())->toBe(0);
});

test('resolveConflict uses the persisted conflict case, leaving a mutated unsaved case untouched', function () {
    $caseA = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    $caseB = legacyFactBootstrapper()->bootstrap(bureaucracyUser());
    expect($caseA->fresh()->fact_version)->toBe(1);
    expect($caseB->fresh()->fact_version)->toBe(1);

    $existing = caseFactStore()->confirmedFact($caseA, 'citizenship_group');
    $candidate = caseFactStore()->recordCandidate($caseA, 'citizenship_group', 'eu', 'onboarding_checklist');
    $conflict = caseFactStore()->confirmCandidate($candidate);
    expect($conflict)->not->toBeNull();

    $conflict->case_id = $caseB->id; // unsaved in-memory mutation only

    $resolved = caseFactStore()->resolveConflict($conflict, $candidate);

    expect($resolved->id)->toBe($candidate->id);
    expect($candidate->fresh()->state)->toBe('confirmed');
    expect($existing->fresh()->state)->toBe('superseded');

    // Only persisted case A resolves and bumps its version; B stays untouched.
    expect(BureaucracyFactConflict::find($conflict->id)->case_id)->toBe($caseA->id);
    expect($caseA->fresh()->fact_version)->toBe(2);
    expect($caseB->fresh()->fact_version)->toBe(1);
});
