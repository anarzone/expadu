// The bureaucracy.plan.1 read model as the Paperwork page uses it.
// Field meanings: docs/contracts/bureaucracy-v2.md. The page renders these
// decisions; it never derives applicability, legal status or dates itself.

export type Workflow =
    | 'not_started'
    | 'preparing'
    | 'blocked'
    | 'action_required'
    | 'submitted'
    | 'waiting_authority'
    | 'completed'
    | 'cancelled'
    | 'untracked';

export type StepStatus =
    | 'todo'
    | 'blocked'
    | 'waiting'
    | 'completed'
    | 'cancelled';

export type Readiness =
    | 'missing'
    | 'reported_available'
    | 'confirmed_for_use'
    | 'needs_reconfirmation';

export type Applicability =
    | 'required'
    | 'conditional'
    | 'unknown'
    | 'not_required';

export interface SourceLink {
    kind: string | null;
    label: string | null;
    url: string | null;
}

export interface OfficialAction {
    id: string;
    url: string;
    purpose: string;
}

export interface TimelineRow {
    id: string;
    kind:
        | 'document_expiry'
        | 'legal_due'
        | 'preparation_target'
        | 'authority_follow_up'
        | 'appointment'
        | 'submission_recorded';
    date: string | null;
    state?: 'dated' | 'date_unknown' | string;
    needed_fact?: string | null;
    overdue?: boolean;
    conditional?: boolean;
    anchor_fact?: string | null;
    anchor_event_id?: string | null;
    document?: string;
    fact_key?: string;
    occurrence_key?: string;
    process_id?: number | null;
    step_id?: string | null;
    action_id?: string | null;
    starts_at?: string;
    timezone?: string;
    duration_minutes?: number | null;
    location?: { label?: string | null } | null;
    routable?: boolean;
    appointment_id?: string;
    event_id?: number;
    revision_event_id?: number;
}

export interface AttentionRow {
    id: string;
    kind: TimelineRow['kind'];
    date: string;
    days_remaining: number;
    urgency: string;
    title: string;
    label: string;
    occurrence_key?: string | null;
    action_id?: string | null;
    anchor_event_id?: string | null;
    starts_at?: string;
    location?: { label?: string | null } | null;
    document?: string;
}

export interface FirstOpenRequirement {
    id: string;
    label: string;
    readiness: Readiness;
    applicability: Applicability;
    conditional: boolean;
}

export interface Step {
    id: string;
    step_id: string;
    guidance_id: string | null;
    title: string | null;
    description: string | null;
    status: StepStatus;
    step_state: string | null;
    position: number | null;
    depends_on: string[];
    verified_at: string | null;
    review_due_at: string | null;
    content_version: string | null;
    sources: { official: OfficialAction[]; legal: SourceLink[] };
    requirements: { ready: number; total: number; optional: number } | null;
    first_open_requirement: FirstOpenRequirement | null;
    dates: TimelineRow[];
}

export interface Produced {
    unit_id: string;
    process_id: string;
    document_id: string;
    label: string;
}

export interface Guidance {
    id: string;
    step_id: string;
    title: string;
    description: string | null;
    kind: string;
    completion_event: 'submission_recorded' | 'step_completed';
    actionable: boolean;
    produces: Produced[];
}

export interface ProgressOption {
    event: string;
    date: 'required' | 'optional' | null;
    note: boolean;
    allowed: boolean;
    reason: string | null;
}

export interface Process {
    id: number | null;
    person_id: number;
    definition_id: string;
    topic: string | null;
    version: number;
    occurrence_key: string;
    review_token: string | null;
    guidance_state: 'current' | 'review_required' | 'history_only';
    bind_occurrence: string | null;
    state: {
        workflow: Workflow;
        steps: Record<string, string>;
        report: {
            event: string;
            occurred_on: string | null;
            note: string | null;
        } | null;
    };
    progress: { total: number; completed: { count: number } };
    guidance: Guidance[];
    title: string | null;
    topic_label: string | null;
    steps: Step[];
    is_closed: boolean;
    closed_on: string | null;
    blocking_reason: { label: string } | null;
    untrackable: boolean;
    progress_options: ProgressOption[];
}

export interface Requirement {
    id: string;
    label: string;
    note: string | null;
    terms: { german: string; english: string }[];
    produced_by: { unit_id: string; process_id: string } | null;
    applicability: Applicability;
    semantic_hash: string;
    source_rule_id: string;
    process_id: number | null;
    occurrence_key: string;
    readiness: Readiness;
    evidence_id: string | null;
    suggested_evidence_ids: string[];
}

export interface EvidenceItem {
    id: string;
    version: number;
    details: {
        label: string;
        kind: string;
        reported_available: boolean;
        expires_on: string | null;
        requirement_refs?: string[];
    };
}

export interface CoverageUnit {
    definition_id: string | null;
    unit_id: string;
    title: string | null;
    content_version: string | null;
    verified_at: string | null;
    source_urls: { official: string[]; legal: string[] };
    state: 'complete' | 'partial' | 'not_covered' | 'withdrawn' | 'unconfirmed';
}

export interface AnswerSchema {
    type: 'date' | 'enum' | 'boolean' | 'integer' | string;
    options: string[];
    date_semantics?: string | null;
    allows_not_applicable?: boolean;
}

export interface Question {
    fact_key: string;
    process_ids: string[];
    kind: 'answer' | 'resolve_conflict' | 'review_relationship' | string;
    question: string;
    why: string;
    answer_schema: AnswerSchema;
    dependency_token: string;
    deferral_token: string;
    /** Present once this session has offered the question. */
    id?: number;
    token?: string;
    can_skip?: boolean;
    can_answer_unknown?: boolean;
}

export interface QuestionOffer {
    status:
        | 'offered'
        | 'paused'
        | 'answer_limit'
        | 'refresh_required'
        | 'already_handled'
        | 'no_more_questions'
        | string;
    session_id: number;
    question: (Question & { id: number; token: string }) | null;
}

export type AnswerState = 'value' | 'unknown' | 'declined' | 'not_applicable';

/** What the person chose: a value, or an honest "not sure" / "prefer not to say". */
export interface Answer {
    state: AnswerState;
    value: string | number | boolean | null;
}

export interface FactDefinition {
    key: string;
    type: AnswerSchema['type'];
    options: string[];
    question: string;
    why: string;
    date_semantics: string | null;
    allows_not_applicable: boolean;
    subject_scope: string;
}

export type FactState =
    | AnswerState
    | 'conflict'
    | 'needs_reconfirmation'
    | 'invalid';

export interface FactView {
    revision: number;
    values: Record<string, string | number | boolean>;
    states: Record<string, FactState>;
    evidence: Record<
        string,
        {
            fact_id: number;
            effective_from: string | null;
            checked_at: string | null;
        }
    >;
}

export interface FactHistory {
    revision: number;
    entries: {
        fact_id: number;
        operation: 'asserted' | 'corrected' | 'changed' | 'resolved' | string;
        after: { answer_state: AnswerState; value: unknown };
        before: { answer_state: AnswerState; value: unknown } | null;
        effective_from: string | null;
        recorded_at: string | null;
    }[];
}

export interface FactConflict {
    fact_key: string;
    resolution_available: boolean;
    choices?: {
        fact_id: number;
        value: unknown;
        answer_state: AnswerState | 'invalid';
        recorded_at: string | null;
    }[];
    expected_revision?: number;
    review_token?: string;
}

export interface ProgressBucket {
    count: number;
    ids: string[];
}

export interface Plan {
    person_id: number;
    jurisdiction: string;
    evaluated_at: string;
    overview: {
        next_actions: { id: string; occurrence_key: string; step_id: string }[];
        question: Question | null;
        remaining_action_count: number;
    };
    questions: {
        session_id: number | null;
        question: Question | null;
        deferred: string[];
        status:
            | 'preview'
            | 'offered'
            | 'paused'
            | 'answer_limit'
            | 'no_more_questions'
            | string;
        remaining_information_count?: number;
    };
    actions: {
        id: string;
        occurrence_key: string;
        step_id: string;
        title: string;
        process_title: string | null;
    }[];
    processes: Process[];
    history: Process[];
    history_count: number;
    progress: {
        total: number;
        todo: ProgressBucket;
        blocked: ProgressBucket;
        waiting: ProgressBucket;
        completed: ProgressBucket;
    };
    timeline: TimelineRow[];
    coverage: { state: string; units: CoverageUnit[] };
    paperwork:
        | { available: false; reason: string }
        | { requirements: Requirement[]; evidence: EvidenceItem[] };
    scopes: string[];
    attention: AttentionRow[];
    coming_up: AttentionRow[];
}

export interface PlanEntry {
    state: 'ready' | 'record_unavailable' | 'setup_required';
    plan: Plan | null;
}
