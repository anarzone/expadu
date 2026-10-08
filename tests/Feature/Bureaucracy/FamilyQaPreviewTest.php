<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->freezeTime();
    $this->admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->admin);
    app(RecordFactChange::class)->execute($this->admin, $this->case->person, 'current_residence_title', 'blue_card', null, $this->case->fact_version);
    $this->actingAs($this->admin);
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.preview', 'title' => 'Synthetic family preparation', 'description' => 'Fixture content, not legal advice.',
        'applies_if' => [['current_residence_title' => 'family_reunification', 'marital_household_continues' => true]],
        'deadline_type' => 'none', 'depends_on' => [], 'links' => [], 'how_to_steps' => [], 'documents_required' => [],
    ])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => 'fixture.family', 'topic' => 'family', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]))->id, null);
});

function qaPreviewUrl(string $persona): string
{
    return '/bureaucracy/v2/preview/'.rawurlencode($persona).'?jurisdiction=de-nrw-cologne';
}

test('canonical persona preview is read only across A B A and leaves family records unchanged', function () {
    $relative = User::factory()->onboarded()->create();
    $theirCase = app(EnsureAccountHolder::class)->dossier($relative);
    $invite = app(ManageDelegation::class)->invite($this->admin, $this->case->person->workspace, $relative->email, ['view_plan']);
    app(ManageDelegation::class)->accept($relative, $invite['token'], ['view_plan']);
    $tables = ['users', 'bureaucracy_people', 'bureaucracy_cases', 'bureaucracy_case_facts', 'bureaucracy_access_grants',
        'bureaucracy_processes', 'bureaucracy_evidence_items', 'bureaucracy_outbox_events', 'bureaucracy_question_sessions', 'bureaucracy_case_questions'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $a = $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('read_only', true)->assertJsonPath('sample_content', true)->assertJsonPath('schema_version', 'bureaucracy.qa-assessment.1')->json();
    $this->getJson(qaPreviewUrl('case-settlement-unknown-holder'))->assertOk();
    $again = $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertOk()->json();
    expect($again)->toBe($a)->and($snapshot())->toBe($before)
        ->and($a['assessment']['processes'][0]['variants'][0]['assessment'])->toBe('supported_preparation')
        ->and($a['facts']['values']['current_residence_title'])->toBe('family_reunification');
    Http::assertNothingSent();
});

test('preview never substitutes another persona or grants admin access', function () {
    $this->getJson(qaPreviewUrl('not-a-persona'))->assertNotFound();
    $ordinary = User::factory()->onboarded()->create(['is_admin' => false]);
    $this->actingAs($ordinary)->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertForbidden();
    expect($ordinary->fresh()->is_admin)->toBeFalse();
});

test('preview rejects a revoked admin role even when authentication holds an older model', function () {
    User::query()->whereKey($this->admin->id)->update(['is_admin' => false]);
    $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertForbidden();
});

test('preview reports no active catalogue and outside coverage without publishing anything', function () {
    DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->update(['release_id' => null]);
    $this->getJson(qaPreviewUrl('planning'))->assertOk()->assertJsonPath('coverage.state', 'not_activated')
        ->assertJsonPath('assessment.processes', []);
    $this->getJson('/bureaucracy/v2/preview/planning?jurisdiction=outside_coverage')->assertOk()
        ->assertJsonPath('coverage.state', 'outside_coverage');
    expect(DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->value('release_id'))->toBeNull();
});

test('every roster persona uses registered facts and retains uncertainty without inferred title or move in', function () {
    foreach (BureaucracyPersonas::demo() as $persona) {
        $data = $this->getJson(qaPreviewUrl($persona['key']))->assertOk()->json();
        expect($data['persona']['key'])->toBe($persona['key'])->and($data['facts']['values'])->not->toHaveKey('moved_in_at');
        foreach ($data['question_candidates'] as $question) {
            expect($question)->not->toHaveKeys(['id', 'token', 'dependency_token', 'deferral_token']);
        }
        if (($persona['planned'] ?? false) === true) {
            expect($data['facts']['values']['arrival_planned'])->toBeTrue()
                ->and($data['facts']['values'])->not->toHaveKey('arrival_date');
        }
    }
    $unknown = $this->getJson(qaPreviewUrl('case-settlement-unknown-holder'))->json();
    expect($unknown['facts']['values']['current_residence_title'])->toBe('settlement_permit_unknown')
        ->and($unknown['facts']['values'])->not->toHaveKey('permit_track');
});

test('sample and actual confirmed answers produce the same reviewed rule decisions', function () {
    $preview = $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertOk()->json();
    $subject = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($subject);
    foreach ($preview['facts']['values'] as $key => $value) {
        app(RecordFactChange::class)->execute($subject, $case->person, $key, $value, null, $case->fresh()->fact_version);
    }
    $plan = app(PlanReadModel::class)->for($subject, $case->person, 'de-nrw-cologne');
    $previewVariants = array_merge(...array_column($preview['assessment']['processes'], 'variants'));
    expect(array_column($previewVariants, 'id'))->toBe(['fixture.preview'])
        ->and(array_column($plan['guidance'], 'id'))->toBe(array_column($previewVariants, 'id'));
    foreach ($previewVariants as $variant) {
        $actual = collect($plan['guidance'])->firstWhere('id', $variant['id']);
        expect($actual)->not->toBeNull()->and($actual['assessment'])->toBe($variant['assessment'])
            ->and($actual['criteria'])->toBe($variant['criteria'])->and($actual['missing_facts'])->toBe($variant['missing_facts']);
    }
});

test('outside coverage with an active catalogue cannot leak local rule decisions or dependencies', function () {
    $data = $this->getJson('/bureaucracy/v2/preview/case-family-renewal-four-years?jurisdiction=outside_coverage')->assertOk()->json();
    expect($data['catalogue_hash'])->not->toBeNull()->and($data['coverage']['state'])->toBe('outside_coverage')
        ->and($data['assessment']['jurisdiction'])->toBe('outside_coverage')
        ->and(array_merge(...array_column($data['assessment']['processes'], 'variants')))->toBe([])
        ->and($data['assessment']['question_dependencies'])->toBe([]);
});

test('the preview request never queries personal answer or family record tables', function () {
    $queries = [];
    $capture = true;
    DB::listen(function ($query) use (&$queries, &$capture) {
        if ($capture) {
            $queries[] = $query->sql;
        }
    });
    try {
        $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertOk();
    } finally {
        $capture = false;
    }
    $personalTables = ['bureaucracy_case_facts', 'bureaucracy_cases', 'bureaucracy_people', 'bureaucracy_access_grants',
        'bureaucracy_relationships', 'bureaucracy_processes', 'bureaucracy_evidence_items'];
    foreach ($queries as $query) {
        foreach ($personalTables as $table) {
            expect(strtolower($query))->not->toContain('"'.$table.'"');
        }
    }
    expect($queries)->not->toBeEmpty();
});

test('source withdrawal reaches the preview immediately without a replacement catalogue', function () {
    $before = $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertOk()->json();
    expect($before['assessment']['processes'][0]['variants'])->toHaveCount(1);
    Task::query()->where('key', 'fixture.preview')->update(['review_status' => 'legacy']);
    $after = $this->getJson(qaPreviewUrl('case-family-renewal-four-years'))->assertOk()->json();
    expect($after['assessment']['processes'][0]['variants'])->toBe([])
        ->and($after['assessment']['withdrawn'])->toContain('fixture.preview')
        ->and($after['catalogue_hash'])->toBe($before['catalogue_hash']);
});
