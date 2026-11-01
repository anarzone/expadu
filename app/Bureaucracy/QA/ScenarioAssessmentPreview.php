<?php

namespace App\Bureaucracy\QA;

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\TemporalFactValidator;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Enums\Situation;
use Carbon\CarbonImmutable;

/** Synthetic inputs only. Never reads an account, grants, saved progress or personal answers. */
final class ScenarioAssessmentPreview
{
    public function __construct(private FactRegistry $registry, private TemporalFactValidator $dates,
        private CatalogueReleaseStore $catalogues, private QuestionProtocol $questions) {}

    public function forKey(string $key, string $jurisdiction): ?array
    {
        $persona = collect(BureaucracyPersonas::demo())->firstWhere('key', $key);
        if ($persona === null) {
            return null;
        }
        $values = [
            'citizenship_group' => $persona['is_eu'] ? 'eu' : 'non_eu',
            'purpose' => match ($persona['situation']) {
                Situation::EuEmployee, Situation::NonEuEmployee => 'employment',
                Situation::Student => 'study', Situation::Freelancer => 'freelance',
                Situation::FamilyReunification => 'family', Situation::DigitalNomad => 'digital_nomad',
                Situation::Other => 'other',
            },
            'arrival_planned' => (bool) ($persona['planned'] ?? false),
            ...($persona['facts'] ?? []),
        ];
        // An intended entry mode is not an event that has already happened.
        if (! $values['arrival_planned']) {
            $values['entry_mode'] ??= $persona['entry_mode'];
            $values['arrival_date'] ??= BureaucracyPersonas::arrivalFor($persona);
        }
        foreach ($values as $fact => $value) {
            $values[$fact] = $this->registry->definition($fact)->normalize($value);
        }
        $this->dates->validateContext($values);
        $facts = ['revision' => 0, 'values' => $values, 'states' => array_fill_keys(array_keys($values), 'value'),
            'evidence' => array_fill_keys(array_keys($values), ['source' => 'synthetic_qa_fixture'])];
        $catalogue = $this->catalogues->current() ?? ['release_hash' => null, 'definitions' => [], 'withdrawn' => []];
        $input = new AssessmentInput($facts, [], [], $catalogue, $jurisdiction,
            CarbonImmutable::now(config('bureaucracy_catalogue.jurisdictions.'.$jurisdiction.'.timezone', 'UTC')), $values['case_goal'] ?? null);
        $assessment = (new AssessPerson)->assess($input)->toArray();

        return ['schema_version' => 'bureaucracy.qa-assessment.1', 'read_only' => true, 'sample_content' => true,
            'persona' => ['key' => $persona['key'], 'label' => $persona['label']],
            // Old labels/context are visible to reviewers but cannot masquerade as registered facts.
            'unmapped_fixture_context' => array_intersect_key($persona, array_flip(['path', 'housing', 'license', 'life'])),
            'facts' => $facts, 'assessment' => $assessment, 'catalogue_hash' => $catalogue['release_hash'],
            'coverage' => ['state' => ! array_key_exists($jurisdiction, config('bureaucracy_catalogue.jurisdictions'))
                ? 'outside_coverage' : ($catalogue['release_hash'] === null ? 'not_activated' : 'partial')],
            'question_candidates' => array_map(fn ($candidate) => array_diff_key($candidate, array_flip(['dependency_token', 'deferral_token'])), $this->questions->candidates($input)),
            'ai' => ['available' => false, 'reason' => 'read_only_preview']];
    }
}
