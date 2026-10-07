<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessmentRevision;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Evidence\ProjectPaperwork;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Processes\DiscoverProcesses;
use App\Bureaucracy\Processes\ProcessHistory;
use App\Bureaucracy\Processes\ProcessPrerequisites;
use App\Bureaucracy\Processes\ProcessStateMachine;
use App\Bureaucracy\Processes\ProgressSummary;
use App\Bureaucracy\Processes\ProjectProcess;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Bureaucracy\Timeline\BuildTimeline;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use Illuminate\Support\Facades\DB;

final class PlanReadModel
{
    public function __construct(private PrepareAssessmentInput $inputs, private PersonAccess $access,
        private DiscoverProcesses $discover, private ProjectProcess $workflows, private ProjectPaperwork $paperwork,
        private QuestionPreview $questions, private ReviewedGuidance $guidance, private AssessmentRevision $revisions,
        private NextAssessmentBoundary $boundaries) {}

    /** Single authorised scalar projection. Reads never create offers, facts or process records. */
    public function for(User $actor, BureaucracyPerson $person, string $jurisdiction): array
    {
        return DB::transaction(function () use ($actor, $person, $jurisdiction): array {
            $input = $this->inputs->for($actor, $person, $jurisdiction);
            $person = $person->fresh();
            $caseId = $person->dossier()->value('id');
            $scopes = $this->access->scopesFor($actor, $person);
            sort($scopes);
            $assessment = (new AssessPerson)->assess($input)->toArray();
            $facts = (new AssessmentFacts)->combine($input->facts, $input->relationships);
            $proposals = $this->discover->for($input);
            $decisions = array_column($assessment['processes'], null, 'definition_id');
            $processes = [];
            $history = [];
            $consumed = [];
            foreach ($input->processes as $stored) {
                $workflow = $this->workflows->for($stored, $input, $proposals);
                $key = $workflow['bind_occurrence'] ?? $stored['occurrence_key'];
                $proposal = collect($proposals)->firstWhere('occurrence_key', $key);
                $projection = [...$workflow, 'person_id' => $person->id, 'topic' => $decisions[$stored['definition_id']]['topic'] ?? $stored['topic'],
                    'guidance' => array_map(fn ($variant) => $this->guidance->for($variant, $facts, $input->at), $proposal['variants'] ?? [])];
                if ($proposal === null) {
                    $history[] = $projection;
                } else {
                    $consumed[$key] = true;
                    $processes[] = $projection;
                }
            }
            $externalSteps = (new ProcessPrerequisites)->for($proposals, $input->processes);
            foreach ($proposals as $proposal) {
                if (isset($consumed[$proposal['occurrence_key']])) {
                    continue;
                }
                $state = (new ProcessStateMachine)->withPrerequisites((new ProcessStateMachine)->initial($proposal['steps']), $proposal['steps'], $externalSteps);
                $processes[] = ['schema_version' => 'bureaucracy.process.1', 'id' => null, 'person_id' => $person->id,
                    'definition_id' => $proposal['definition_id'], 'topic' => $proposal['topic'], 'jurisdiction' => $jurisdiction, 'version' => 0,
                    'occurrence_key' => $proposal['occurrence_key'], 'state' => $state, 'guidance_state' => 'current',
                    'review_token' => $proposal['review_token'], 'bind_occurrence' => null, 'current_steps' => $proposal['steps'],
                    'progress' => (new ProgressSummary)->for([['id' => $proposal['occurrence_key'], 'state' => $state]]),
                    'guidance' => array_map(fn ($variant) => $this->guidance->for($variant, $facts, $input->at), $proposal['variants'])];
            }
            usort($processes, fn ($a, $b) => ($decisions[$b['definition_id']]['priority'] ?? 0) <=> ($decisions[$a['definition_id']]['priority'] ?? 0)
                ?: strcmp($a['occurrence_key'], $b['occurrence_key']));
            $events = BureaucracyProcessEvent::query()->whereIn('process_id', array_column($input->processes, 'id'))->orderBy('id')->get()
                ->map(fn ($event) => ['id' => $event->id, 'process_id' => $event->process_id, 'type' => $event->type,
                    'payload' => $event->payload, 'corrects_event_id' => $event->corrects_event_id])->all();
            $timeline = [...$this->timeline($input, $facts, $proposals, [...$processes, ...$history], $events),
                ...$this->otherJurisdictionAppointments($caseId, $input)];
            $progress = (new ProgressSummary)->for(array_map(fn ($process) => ['id' => $process['id'] ?? $process['occurrence_key'], 'state' => $process['state']], $processes));
            $paperwork = in_array(AccessScope::ManageEvidence->value, $scopes, true)
                ? $this->paperwork->for($actor, $person, $input) : ['available' => false, 'reason' => 'permission_required'];
            $question = in_array(AccessScope::EditFacts->value, $scopes, true)
                ? $this->questions->for($actor, $caseId, $input)
                : ['status' => 'permission_required', 'question' => null, 'entry_state' => null, 'deferred' => [], 'candidates_count' => null];
            $order = new CatalogueOrder($input->catalogue);
            $details = new ProcessDetails;
            $requirements = $paperwork['requirements'] ?? null;
            $processes = array_map(fn ($process) => $details->for($process, $order, $timeline, $requirements, $events), $processes);
            $history = array_map(fn ($process) => $details->for($process, $order, $timeline, $requirements, $events), $history);
            $guidance = [];
            foreach ($assessment['processes'] as $decision) {
                foreach ($decision['variants'] as $variant) {
                    $guidance[] = [...$this->guidance->for($variant, $facts, $input->at),
                        'definition_id' => $decision['definition_id'], 'topic' => $decision['topic']];
                }
            }
            $next = $this->nextActions($processes, $timeline, $progress);
            $nextTime = $this->boundaries->for($actor, $person, $input, $timeline);
            $coverage = ['state' => ! array_key_exists($jurisdiction, config('bureaucracy_catalogue.jurisdictions')) ? 'outside_coverage'
                : (($input->catalogue['release_hash'] ?? null) === null ? 'not_activated' : 'partial'),
                'processes' => array_map(fn ($row) => array_intersect_key($row, array_flip(['definition_id', 'relevance', 'coverage'])), $assessment['processes']),
                'withdrawn' => $assessment['withdrawn'], 'units' => (new CoverageUnits)->for($input)];
            $revision = $this->revisions->for(['actor_id' => $actor->id, 'person_id' => $person->id,
                'authority' => $this->access->authorityToken($actor, $person), 'person_version' => $person->record_version,
                'assessment' => $assessment['input_revision'], 'protocol' => app(QuestionProtocol::class)->version(),
                'paperwork' => array_diff_key($paperwork, ['evaluated_at' => true]), 'questions' => $question, 'events' => $events, 'timeline' => $timeline, 'scopes' => $scopes,
                'processing_notice' => config('bureaucracy_privacy.notice_version'),
                'provider_version' => ProcessingPurpose::FactExtraction->providerVersion(), 'ai_available' => ProcessingPurpose::FactExtraction->available(),
                'sharing_notice' => config('bureaucracy_family.sharing_notice_version'), 'next_boundary' => $nextTime]);

            $plan = ['schema_version' => 'bureaucracy.plan.1', 'person_id' => $person->id, 'jurisdiction' => $jurisdiction,
                'assessment_revision' => $revision, 'evaluated_at' => $input->at->toIso8601String(), 'next_reassessment_at' => $nextTime,
                'overview' => ['next_actions' => array_slice($next, 0, 3), 'question' => $question['question'], 'remaining_action_count' => max(0, count($next) - 3)],
                'questions' => $question, 'actions' => $next, 'processes' => $processes, 'history' => $history,
                'history_count' => count($history) + count(array_filter($processes, fn ($process) => $process['is_closed'])),
                'topics' => $this->topics($assessment['processes'], $processes, $history), 'guidance' => $guidance,
                'progress' => $progress, 'timeline' => $timeline, 'coverage' => $coverage, 'paperwork' => $paperwork, 'scopes' => $scopes,
                'ai' => ['available' => in_array(AccessScope::RequestAi->value, $scopes, true) && in_array(AccessScope::EditFacts->value, $scopes, true)
                    && ProcessingPurpose::FactExtraction->available(), 'consent_scope' => 'single_request', 'confirmation_required' => true]];
            $attention = app(PlanAttention::class)->for($plan, includeUndated: true);

            return [...$plan, 'attention' => $attention, 'coming_up' => app(PlanAttention::class)->comingUp($attention)];
        });
    }

    private function topics(array $decisions, array $processes, array $history): array
    {
        $topics = [];
        foreach ($decisions as $decision) {
            if ($decision['relevance'] === 'not_relevant') {
                continue;
            }
            $topic = $decision['topic'];
            $topics[$topic] ??= ['id' => $topic, 'label' => (new ProcessDetails)->topicLabel($topic), 'definition_ids' => [], 'occurrence_keys' => [], 'history_ids' => []];
            $topics[$topic]['definition_ids'][] = $decision['definition_id'];
        }
        foreach ($processes as $process) {
            $topics[$process['topic']]['occurrence_keys'][] = $process['occurrence_key'];
        }
        foreach ($history as $process) {
            if ($process['topic'] !== null) {
                $topics[$process['topic']] ??= ['id' => $process['topic'], 'label' => (new ProcessDetails)->topicLabel($process['topic']), 'definition_ids' => [], 'occurrence_keys' => [], 'history_ids' => []];
                $topics[$process['topic']]['history_ids'][] = $process['id'];
            }
        }
        ksort($topics);

        return array_values($topics);
    }

    private function timeline(AssessmentInput $input, array $facts, array $proposals, array $processes, array $events): array
    {
        $timezone = config('bureaucracy_catalogue.jurisdictions.'.$input->jurisdiction.'.timezone', 'UTC');
        $builder = new BuildTimeline;
        $rows = $builder->for([], $facts, [], $input->at, $timezone);
        foreach ($processes as $process) {
            $key = $process['bind_occurrence'] ?? $process['occurrence_key'];
            $proposal = collect($proposals)->firstWhere('occurrence_key', $key);
            $history = (new ProcessHistory)->active(array_values(array_filter($events, fn ($event) => $event['process_id'] === $process['id'])));
            $steps = array_column($proposal['variants'] ?? [], 'step_id', 'id');
            foreach ($builder->for($proposal['variants'] ?? [], $facts, $history, $input->at, $timezone) as $row) {
                if ($row['kind'] !== 'document_expiry') {
                    $step = $steps[$row['source_rule_id'] ?? ''] ?? null;
                    $rows[] = [...$row, 'id' => $process['occurrence_key'].':'.$row['id'],
                        'process_id' => $process['id'], 'occurrence_key' => $process['occurrence_key'], 'workflow' => $process['state']['workflow'],
                        'step_id' => $step, 'action_id' => $step === null ? null : ($process['id'] ?? $process['occurrence_key']).':'.$step];
                }
            }
        }

        // Name the recorded expiry row whose date anchors a step's date, so no consumer has to infer the link.
        $expiries = array_column(array_filter($rows, fn ($row) => $row['kind'] === 'document_expiry'), 'id', 'fact_key');
        foreach ($rows as &$row) {
            if (isset($row['anchor_fact'])) {
                $row['anchor_event_id'] = $expiries[$row['anchor_fact']] ?? null;
            }
        }
        unset($row);

        return $rows;
    }

    private function nextActions(array $processes, array $timeline, array $progress): array
    {
        $actions = [];
        foreach ($processes as $process) {
            if (in_array($process['state']['workflow'], ['completed', 'cancelled'], true)) {
                continue; // Closed work never returns as a next action until it is explicitly reopened.
            }
            foreach ($process['guidance'] as $guidance) {
                $id = ($process['id'] ?? $process['occurrence_key']).':'.$guidance['step_id'];
                if (! in_array($id, $progress['todo']['ids'], true)) {
                    continue;
                }
                $dates = array_values(array_filter($timeline, fn ($event) => ($event['action_id'] ?? null) === $id
                    && in_array($event['kind'], ['legal_due', 'preparation_target', 'authority_follow_up'], true)));
                $actions[] = ['id' => $id, 'type' => 'open_process', 'person_id' => $process['person_id'],
                    'process_id' => $process['id'], 'occurrence_key' => $process['occurrence_key'], 'step_id' => $guidance['step_id'],
                    'title' => $guidance['title'], 'process_title' => $process['title'] ?? null, 'source_rule_id' => $guidance['id'], 'source_hash' => $guidance['source_hash'],
                    'dates' => $dates, 'requires_process_start' => $process['id'] === null];
            }
        }
        usort($actions, fn ($a, $b) => ($a['dates'][0]['date'] ?? '9999-12-31') <=> ($b['dates'][0]['date'] ?? '9999-12-31'));

        return $actions;
    }

    /** Changing guidance jurisdiction does not cancel a person's reported commitments elsewhere. */
    private function otherJurisdictionAppointments(int $caseId, AssessmentInput $input): array
    {
        $rows = [];
        $processes = BureaucracyProcess::query()->where('case_id', $caseId)->where('jurisdiction', '!=', $input->jurisdiction)
            ->with('events')->orderBy('id')->get();
        foreach ($processes as $process) {
            $events = $process->events->map(fn ($event) => ['id' => $event->id, 'type' => $event->type,
                'payload' => $event->payload, 'corrects_event_id' => $event->corrects_event_id])->all();
            foreach ((new BuildTimeline)->for([], ['values' => [], 'states' => []], (new ProcessHistory)->active($events), $input->at, 'UTC') as $row) {
                if ($row['kind'] === 'appointment') {
                    $rows[] = [...$row, 'id' => $process->occurrence_key.':'.$row['id'], 'process_id' => $process->id,
                        'occurrence_key' => $process->occurrence_key, 'workflow' => $process->state['workflow'], 'jurisdiction' => $process->jurisdiction];
                }
            }
        }

        return $rows;
    }
}
