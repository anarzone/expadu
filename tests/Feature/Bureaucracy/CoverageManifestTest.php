<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Catalogue\CoverageManifest;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function coverageManifestFixture(string $coverage = 'partial'): Task
{
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.coverage', 'depends_on' => [],
        'description' => 'Synthetic private review text, never part of the diagnostic report.',
        'applies_if' => [['citizenship_group' => 'non_eu']], 'deadline_type' => 'none',
        'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $map = [$task->key => ['process_id' => 'fixture.process', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => $coverage]];
    if ($coverage === 'complete') {
        $map[$task->key]['coverage_review'] = ['criterion_keys' => ['citizenship_group'],
            'content_version' => $task->content_version, 'reviewed_by' => $task->reviewed_by,
            'source_review_reference' => 'synthetic-test-review-not-legal-advice'];
    }
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], $map));
    $store->activate($release->id, null);

    return $task;
}

test('coverage diagnostics distinguish partial criteria from a reviewed complete criterion set', function (string $coverage, string $status) {
    $task = coverageManifestFixture($coverage);
    $report = app(CoverageManifest::class)->current();
    expect($report['scope'])->toBe('catalogue_units_not_all_legal_cases')
        ->and($report['units'])->toHaveCount(1)->and($report['units'][0]['coverage'])->toBe($status)
        ->and($report['units'][0]['key'])->toBe($task->key)
        ->and($report['units'][0]['process_id'])->toBe('fixture.process')
        ->and($report['units'][0]['criterion_keys'])->toBe(['citizenship_group'])
        ->and($report['counts'][$status])->toBe(1)
        ->and($report)->not->toHaveKey('coverage_percent')
        ->and(json_encode($report))->not->toContain('Synthetic private review text');
    if ($coverage === 'partial') {
        expect(array_column($report['units'][0]['gaps'], 'code'))->toContain('complete_criterion_review_missing');
    } else {
        expect($report['units'][0]['gaps'])->toBe([]);
    }
})->with([['partial', 'partial'], ['complete', 'covered']]);

test('a source withdrawal immediately invalidates the coverage report', function (string $change) {
    $task = coverageManifestFixture('complete');
    match ($change) {
        'expired' => $task->update(['review_due_at' => now()->subDay()]),
        'content' => $task->update(['description' => 'Changed after approval']),
        'sources' => $task->update(['legal_sources' => []]),
    };
    $report = app(CoverageManifest::class)->current();
    expect($report['units'][0]['coverage'])->toBe('review_required')
        ->and(array_column($report['units'][0]['gaps'], 'code'))->toContain('active_release_unit_withdrawn')
        ->and($report['counts']['covered'])->toBe(0);
})->with(['expired', 'content', 'sources']);

test('an approved but unactivated unit is not counted as available guidance', function () {
    Task::factory()->approvedFixture()->create(['key' => 'fixture.not-active']);
    $report = app(CoverageManifest::class)->current();
    expect($report['release_hash'])->toBeNull()->and($report['units'][0]['coverage'])->toBe('unsupported')
        ->and(array_column($report['units'][0]['gaps'], 'code'))->toContain('active_release_mapping_missing');
});

test('unreviewed new and retired units remain visible without being compiled into recommendations', function () {
    coverageManifestFixture();
    Task::factory()->create(['key' => 'fixture.unreviewed', 'review_status' => 'legacy', 'is_published' => true]);
    Task::factory()->create(['key' => 'fixture.retired', 'review_status' => 'legacy', 'is_published' => false]);
    $report = app(CoverageManifest::class)->current();
    $units = collect($report['units'])->keyBy('key');
    expect($units['fixture.unreviewed']['coverage'])->toBe('review_required')
        ->and(array_column($units['fixture.unreviewed']['gaps'], 'code'))->toContain('content_source_review_required')
        ->and($units['fixture.retired']['coverage'])->toBe('unsupported')
        ->and(array_column($units['fixture.retired']['gaps'], 'code'))->toContain('unit_not_published')
        ->and($report['counts']['total'])->toBe(3);
});

test('a removed source record remains in the active release audit as a gap', function () {
    $task = coverageManifestFixture();
    $task->delete();
    $report = app(CoverageManifest::class)->current();
    expect($report['units'])->toHaveCount(1)->and($report['units'][0]['key'])->toBe('fixture.coverage')
        ->and($report['units'][0]['coverage'])->toBe('review_required')
        ->and(array_column($report['units'][0]['gaps'], 'code'))->toContain('source_record_missing');
});

test('the manifest command is read only and its strict gate fails on partial coverage', function () {
    coverageManifestFixture();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->artisan('bureaucracy:coverage', ['--manifest' => true])->assertSuccessful();
    $this->artisan('bureaucracy:coverage', ['--manifest' => true, '--fail-on-gap' => true])->assertFailed();
    expect(collect($queries)->filter(fn ($query) => preg_match('/^\s*(insert|update|delete|truncate|alter|create)\b/i', $query))->all())->toBe([]);
});

test('a deliberately empty catalogue cannot pass the strict coverage gate', function () {
    $this->artisan('bureaucracy:coverage', ['--manifest' => true, '--fail-on-gap' => true])->assertFailed();
});

test('a record without a stable key cannot silently pass the strict inventory gate', function () {
    coverageManifestFixture('complete');
    $record = Task::factory()->create(['key' => null, 'review_status' => 'legacy', 'is_published' => true]);
    $report = app(CoverageManifest::class)->current();
    expect($report['unidentified_units'] ?? [])->toBe([['record_id' => $record->id, 'code' => 'stable_key_missing',
        'review_action' => 'Assign a reviewed stable catalogue key or explicitly retire this unclassified record.']]);
    $this->artisan('bureaucracy:coverage', ['--manifest' => true, '--fail-on-gap' => true])->assertFailed();
});

test('a source changing between publication verification and inventory reading cannot retain covered status', function () {
    coverageManifestFixture('complete');
    $changed = false;
    Event::listen('eloquent.retrieved: '.Task::class, function ($task) use (&$changed) {
        if (! $changed && $task->key === 'fixture.coverage') {
            $changed = true;
            Task::query()->whereKey($task->id)->update(['description' => 'Concurrent synthetic content change']);
        }
    });
    $report = app(CoverageManifest::class)->current();
    expect($changed)->toBeTrue()->and($report['counts']['covered'])->toBe(0)
        ->and($report['units'][0]['coverage'])->toBe('review_required');
});

test('malformed unreviewed records produce individual gaps instead of aborting the inventory', function (array $attributes, string $code) {
    coverageManifestFixture('complete');
    Task::factory()->create(['key' => 'fixture.malformed', 'review_status' => 'legacy', 'is_published' => true, ...$attributes]);
    $report = app(CoverageManifest::class)->current();
    $unit = collect($report['units'])->firstWhere('key', 'fixture.malformed');
    expect($report['counts']['covered'])->toBe(1)->and($unit['coverage'])->toBe('review_required')
        ->and(array_column($unit['gaps'], 'code'))->toContain($code);
})->with([
    [['applies_if' => ['invalid']], 'malformed_conditions'],
    [['applies_if' => 'invalid'], 'malformed_conditions'],
    [['legal_sources' => 'invalid'], 'malformed_source_inventory'],
    [['legal_sources' => [['url' => 123]]], 'malformed_source_inventory'],
]);

test('strict mode accepts a reviewed complete unit but rejects an unavailable action alone', function () {
    $task = coverageManifestFixture('complete');
    $this->artisan('bureaucracy:coverage', ['--manifest' => true, '--fail-on-gap' => true])->assertSuccessful();
    $task->update(['links' => ['https://www.stadt-koeln.de/service/']]);
    $store = app(CatalogueReleaseStore::class);
    $current = $store->current();
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task->fresh()], $current['mapping']));
    $store->activate($release->id, $current['release_hash']);
    config(['bureaucracy_catalogue.action_hosts' => []]);
    $report = app(CoverageManifest::class)->current();
    expect($report['counts']['covered'])->toBe(1)
        ->and(array_column($report['units'][0]['gaps'], 'code'))->toContain('action_host_review_required');
    $this->artisan('bureaucracy:coverage', ['--manifest' => true, '--fail-on-gap' => true])->assertFailed();
});

test('the local catalogue inventory reports its actual partial and legacy units without claiming all cases are covered', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()));
    $store->activate($release->id, null);
    expect(app(CoverageManifest::class)->current()['counts'])->toBe([
        'total' => 95, 'covered' => 0, 'partial' => 14, 'unsupported' => 1, 'review_required' => 80,
    ]);
});
