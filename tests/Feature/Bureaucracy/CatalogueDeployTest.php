<?php

use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

test('a deploy activates the compiled catalogue the first time and is a no-op when unchanged', function () {
    expect(app(CatalogueReleaseStore::class)->current())->toBeNull();

    $this->artisan('bureaucracy:compile-catalogue', ['--deploy' => true])->assertSuccessful();
    $first = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->first();
    expect($first->release_id)->not->toBeNull()
        ->and(app(CatalogueReleaseStore::class)->current())->not->toBeNull();

    $this->artisan('bureaucracy:compile-catalogue', ['--deploy' => true])->assertSuccessful();
    $second = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->first();
    expect($second->release_id)->toBe($first->release_id)->and($second->version)->toBe($first->version);
});

test('a deploy never re-activates a catalogue that was deliberately suspended', function () {
    $this->artisan('bureaucracy:compile-catalogue', ['--deploy' => true])->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $store->suspend($store->current()['release_hash']);

    $this->artisan('bureaucracy:compile-catalogue', ['--deploy' => true])
        ->expectsOutputToContain('suspended')
        ->assertSuccessful();

    expect($store->current())->toBeNull();
});

test('deploy cannot be combined with an explicit activation or a dry run', function (array $options) {
    $this->artisan('bureaucracy:compile-catalogue', ['--deploy' => true, ...$options])->assertFailed();
})->with([[['--activate' => true, '--expected-current' => 'none']], [['--dry-run' => true]]]);

test('dossier attachment can run every batch in one deploy step', function () {
    $users = User::factory()->onboarded()->count(3)->create();
    foreach ($users as $user) {
        BureaucracyCase::factory()->for($user)->create();
    }

    $this->artisan('bureaucracy:migrate-dossiers', ['--apply' => true, '--all' => true, '--limit' => 1])->assertSuccessful();

    expect(BureaucracyPerson::query()->whereIn('account_user_id', $users->modelKeys())->count())->toBe(3);
});
