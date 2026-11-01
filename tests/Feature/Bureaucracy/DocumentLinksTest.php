<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Evidence\EvidenceRequirements;
use App\Bureaucracy\ReadModel\ReviewedGuidance;
use App\Models\Task;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->insurance = Task::factory()->approvedFixture()->create(['key' => 'fixture.insurance', 'title' => 'Synthetic insurance step',
        'type' => 'task', 'applies_if' => [], 'depends_on' => [], 'deadline_type' => 'none', 'how_to_steps' => [], 'links' => [], 'documents_required' => []])->fresh();
    $this->permit = Task::factory()->approvedFixture()->create(['key' => 'fixture.permit', 'title' => 'Synthetic permit step',
        'type' => 'task', 'applies_if' => [], 'depends_on' => [], 'deadline_type' => 'none', 'how_to_steps' => [], 'links' => [],
        'documents_required' => [
            ['label' => 'Proof of health insurance', 'from' => 'fixture.insurance'],
            ['label' => 'Copy of your rental contract (Mietvertrag)'],
        ]])->fresh();
    $this->mapping = [
        'fixture.insurance' => ['process_id' => 'fixture.health', 'topic' => 'health', 'kind' => 'preparation', 'coverage' => 'partial'],
        'fixture.permit' => ['process_id' => 'fixture.residence', 'topic' => 'residence', 'kind' => 'action', 'coverage' => 'partial'],
    ];
    $this->terms = ['Mietvertrag' => 'Rental contract'];
});

function variantOf(array $artifact, string $id): array
{
    return collect($artifact['definitions'])->flatMap(fn ($definition) => $definition['variants'])->firstWhere('id', $id);
}

it('names German document terms in English and links each document to the step that produces it', function () {
    $artifact = app(CatalogueCompiler::class)->compile([$this->insurance, $this->permit], $this->mapping, [], $this->terms);
    $rows = app(EvidenceRequirements::class)->for([variantOf($artifact, 'fixture.permit')], ['values' => [], 'states' => []], CarbonImmutable::now());

    expect($rows[0]['produced_by'])->toBe(['unit_id' => 'fixture.insurance', 'process_id' => 'fixture.health'])
        ->and($rows[0]['terms'])->toBe([])
        ->and($rows[1]['terms'])->toBe([['german' => 'Mietvertrag', 'english' => 'Rental contract']])
        ->and($rows[1]['produced_by'])->toBeNull()
        ->and(variantOf($artifact, 'fixture.insurance')['produces'])->toBe([[
            'unit_id' => 'fixture.permit', 'process_id' => 'fixture.residence', 'document_id' => 'fixture.permit.document.1', 'label' => 'Proof of health insurance',
        ]]);
});

it('shows what a step produces in its guidance', function () {
    $artifact = app(CatalogueCompiler::class)->compile([$this->insurance, $this->permit], $this->mapping, [], $this->terms);
    $guidance = app(ReviewedGuidance::class)->for([...variantOf($artifact, 'fixture.insurance'), 'assessment' => 'supported_preparation', 'actionable' => true,
        'criteria' => [], 'missing_facts' => []], ['values' => [], 'states' => []], CarbonImmutable::now());

    expect($guidance['produces'][0]['unit_id'])->toBe('fixture.permit');
});

it('drops a link whose producing step is not in the release', function () {
    $artifact = app(CatalogueCompiler::class)->compile([$this->permit, $this->insurance->replicate(['key' => 'fixture.insurance'])->fill(['review_status' => 'legacy'])],
        $this->mapping, [], $this->terms);

    expect(variantOf($artifact, 'fixture.permit')['documents'][0]['produced_by'])->toBeNull();
});

it('keeps requirement identity when names or links change, so confirmations survive', function () {
    $named = app(CatalogueCompiler::class)->compile([$this->insurance, $this->permit], $this->mapping, [], $this->terms);
    $plain = app(CatalogueCompiler::class)->compile([$this->insurance, $this->permit], $this->mapping, [], []);

    expect(variantOf($named, 'fixture.permit')['documents'][1]['semantic_hash'])->toBe(variantOf($plain, 'fixture.permit')['documents'][1]['semantic_hash']);
});

it('keeps English document names plain', function () {
    expect(fn () => app(CatalogueCompiler::class)->compile([$this->insurance, $this->permit], $this->mapping, [], ['Mietvertrag' => 'Rental contract for 12 months']))
        ->toThrow(DomainException::class, 'Document terms are plain names');
});

it('refuses a process whose steps are anchored to different occurrences, which would split it into two tasks', function () {
    $mapping = [...$this->mapping, 'fixture.permit' => [...$this->mapping['fixture.permit'], 'process_id' => 'fixture.health', 'topic' => 'health', 'occurrence_fact' => 'current_residence_title']];

    expect(fn () => app(CatalogueCompiler::class)->compile([$this->insurance, $this->permit], $mapping, [], $this->terms))
        ->toThrow(DomainException::class, 'anchored to different occurrence facts');
});
