<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\PlanCache;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->freezeTime();
    // Tests run inside one transaction; the cache counts that one as none.
    app()->instance(PlanCache::class, new PlanCache(outside: 1));
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->task = Task::factory()->approvedFixture()->create(['key' => 'fixture.cache.address', 'title' => 'Synthetic address preparation',
        'type' => 'task', 'deadline_type' => 'none', 'applies_if' => [], 'depends_on' => [],
        'documents_required' => [['id' => 'identity', 'label' => 'Synthetic identity document', 'evidence_kind' => 'identity', 'requirement_version' => '1']],
        'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$this->task], [$this->task->key => ['process_id' => $this->task->key, 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial']]));
    $store->activate($release->id, null);
    $this->plan = fn (?User $actor = null): array => app(PlanReadModel::class)->for($actor ?? $this->actor, $this->case->person, 'de-nrw-cologne');
});

/** Plan reads ($run) that were kept (hits) or written, and the key last written. */
function planCacheUse(Closure $run): array
{
    $seen = ['hits' => 0, 'writes' => 0, 'key' => null];
    Event::listen(CacheHit::class, function (CacheHit $event) use (&$seen): void {
        $seen['hits'] += (int) str_starts_with($event->key, 'bureaucracy.plan.');
    });
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$seen): void {
        if (str_starts_with($event->key, 'bureaucracy.plan.')) {
            $seen['writes']++;
            $seen['key'] = $event->key;
        }
    });
    try {
        $run();
    } finally {
        Event::forget(CacheHit::class);
        Event::forget(KeyWritten::class);
    }

    return $seen;
}

test('🔒 a kept plan is the plan as built, and any write to what it reads builds it afresh', function () {
    $first = null;
    expect(planCacheUse(function () use (&$first): void {
        $first = ($this->plan)();
    }))->toMatchArray(['hits' => 0, 'writes' => 1]);
    expect(planCacheUse(fn () => expect(($this->plan)())->toEqual($first)))->toMatchArray(['hits' => 1, 'writes' => 0]);

    // Withdrawn behind the app's back (raw SQL, no model events): the card leaves the plan at once.
    DB::table('tasks')->where('id', $this->task->id)->update(['is_published' => false]);
    $after = null;
    expect(planCacheUse(function () use (&$after): void {
        $after = ($this->plan)();
    }))->toMatchArray(['hits' => 0, 'writes' => 1]);
    expect($after['coverage']['withdrawn'])->toContain($this->task->key)->and($after['guidance'])->not->toEqual($first['guidance']);
});

test('🔒 every table a plan reads bumps the data version in the writing transaction', function () {
    $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'bureaucracy\\_%'"), 'tablename');
    $guarded = array_column(DB::select("SELECT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname = 'bump_plan_data_version'"), 'relname');
    expect(array_values(array_diff([...$tables, 'tasks', 'users', 'user_tasks'], $guarded)))->toBe([]);

    $version = fn (): int => (int) DB::table('plan_data_version')->value('version');
    $before = $version();
    DB::table('bureaucracy_cases')->where('id', $this->case->id)->update(['updated_at' => now()]);
    expect($version())->toBeGreaterThan($before);
});

test('🔒 inside another transaction a plan is built but never kept', function () {
    expect(planCacheUse(fn () => DB::transaction(fn () => ($this->plan)())))->toMatchArray(['hits' => 0, 'writes' => 0]);
});

test('🔒 someone without access gets no plan and nothing is kept', function () {
    $stranger = User::factory()->create();
    expect(planCacheUse(fn () => expect(fn () => ($this->plan)($stranger))->toThrow(AuthorizationException::class)))
        ->toMatchArray(['hits' => 0, 'writes' => 0]);
});

test('🔒 a kept plan the app did not sign is ignored', function () {
    $key = planCacheUse(fn () => ($this->plan)())['key'];
    $forged = json_encode(['schema_version' => 'forged', 'next_reassessment_at' => now()->addDay()->toIso8601String()]);
    foreach ([$forged, str_repeat('0', 64).'|'.$forged] as $entry) {
        Cache::put($key, $entry);
        expect(($this->plan)()['schema_version'])->toBe('bureaucracy.plan.1');
    }
});

test('🔒 a kept plan is never used past its own next_reassessment_at', function () {
    $key = planCacheUse(fn () => ($this->plan)())['key'];
    // Signed as the app signs, but its window has closed (an hour boundary within the same day).
    $json = json_encode([...json_decode(explode('|', Cache::get($key), 2)[1], true), 'schema_version' => 'stale', 'next_reassessment_at' => now()->subMinute()->toIso8601String()]);
    Cache::put($key, hash_hmac('sha256', $key."\n".$json, (string) config('app.key')).'|'.$json);

    expect(($this->plan)()['schema_version'])->toBe('bureaucracy.plan.1');
});
