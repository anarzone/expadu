<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Bureaucracy\ReadModel\ProcessReassessmentOutbox;
use App\Models\BureaucracyCatalogueRelease;
use App\Models\BureaucracyOutboxEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function recoveryCatalogue(): BureaucracyCatalogueRelease
{
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.recovery', 'title' => 'Synthetic preparation',
        'description' => 'Test fixture, not legal guidance.', 'applies_if' => [],
        'depends_on' => [], 'links' => [], 'documents_required' => [], 'how_to_steps' => [],
        'deadline_type' => 'none',
    ])->fresh();

    return app(CatalogueReleaseStore::class)->stage(app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => 'fixture.recovery', 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]));
}

test('catalogue suspension removes actions without deleting dossiers and is safely repeatable', function () {
    $actor = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->dossier($actor)->person;
    $store = app(CatalogueReleaseStore::class);
    $release = recoveryCatalogue();
    $store->activate($release->id, null);
    $plans = app(PlanReadModel::class);
    expect($plans->for($actor, $person, 'de-nrw-cologne')['actions'])->not->toBeEmpty();
    $before = $person->dossier->getRawOriginal();

    $store->suspend($release->content_hash);
    $store->suspend($release->content_hash);

    $plan = $plans->for($actor, $person, 'de-nrw-cologne');
    expect($plan['actions'])->toBe([])
        ->and($plan['coverage']['state'])->toBe('not_activated')
        ->and($person->dossier->fresh()->getRawOriginal())->toBe($before)
        ->and(BureaucracyCatalogueRelease::query()->count())->toBe(1)
        ->and(BureaucracyOutboxEvent::query()->where('event_type', 'catalogue.suspended')->count())->toBe(1);

    $store->activate($release->id, null);
    expect($plans->for($actor, $person, 'de-nrw-cologne')['actions'])->not->toBeEmpty();
});

test('a stale suspension cannot switch off another release', function () {
    $store = app(CatalogueReleaseStore::class);
    $release = recoveryCatalogue();
    $store->activate($release->id, null);
    expect(fn () => $store->suspend(str_repeat('0', 64)))->toThrow(ConflictHttpException::class)
        ->and($store->current()['release_hash'])->toBe($release->content_hash);
});

test('an already active damaged release is rejected on activation retries', function () {
    $store = app(CatalogueReleaseStore::class);
    $release = recoveryCatalogue();
    $store->activate($release->id, null);
    DB::table('bureaucracy_catalogue_releases')->where('id', $release->id)->update(['artifact' => json_encode([])]);
    expect(fn () => $store->activate($release->id, $release->content_hash))->toThrow(DomainException::class);
});

test('suspension remains possible for a damaged release and requests durable reassessment', function () {
    $actor = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->dossier($actor)->person;
    $store = app(CatalogueReleaseStore::class);
    $release = recoveryCatalogue();
    $store->activate($release->id, null);
    DB::table('bureaucracy_catalogue_releases')->where('id', $release->id)->update(['artifact' => json_encode([])]);
    $store->suspend($release->content_hash);
    expect($store->current())->toBeNull();
    $event = BureaucracyOutboxEvent::query()->where('event_type', 'catalogue.suspended')->sole();
    expect(app(ProcessReassessmentOutbox::class)->process($event))->toBeTrue()
        ->and(BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->sole()->aggregate_id)->toBe($person->id);
});

test('recovery commands require an exact target and preserve source withdrawal on restoration', function () {
    $store = app(CatalogueReleaseStore::class);
    $release = recoveryCatalogue();
    $store->activate($release->id, null);
    $this->artisan('bureaucracy:recover-catalogue', ['--suspend' => true])->assertFailed();
    $this->artisan('bureaucracy:recover-catalogue', ['--suspend' => true, '--release' => (string) $release->id,
        '--expected-current' => $release->content_hash])->assertFailed();
    expect($store->current()['release_hash'])->toBe($release->content_hash);
    $this->artisan('bureaucracy:recover-catalogue', ['--suspend' => true,
        '--expected-current' => $release->content_hash])->assertSuccessful();
    Task::query()->where('key', 'fixture.recovery')->update(['is_published' => false]);
    $this->artisan('bureaucracy:recover-catalogue', ['--release' => (string) $release->id,
        '--expected-current' => 'none'])->assertSuccessful();
    expect($store->current()['definitions'][0]['variants'])->toBe([])
        ->and($store->current()['withdrawn'])->toBe(['fixture.recovery']);
});

test('recovery rejects unknown releases and stale expected state without changing the pointer', function () {
    $release = recoveryCatalogue();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($release->id, null);
    $this->artisan('bureaucracy:recover-catalogue', ['--release' => (string) ($release->id + 1000),
        '--expected-current' => $release->content_hash])->assertFailed();
    $this->artisan('bureaucracy:recover-catalogue', ['--suspend' => true,
        '--expected-current' => str_repeat('0', 64)])->assertFailed();
    expect($store->current()['release_hash'])->toBe($release->content_hash);
});
