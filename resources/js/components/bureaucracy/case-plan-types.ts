export type CasePlanCoverageState =
    | 'matched'
    | 'needs_information'
    | 'not_covered'
    | 'conflict';

export type CasePlanSectionKey =
    | 'current_status'
    | 'do_now'
    | 'next'
    | 'coming_up'
    | 'options'
    | 'waiting'
    | 'information_needed'
    | 'not_covered';

export type CasePlanTaskStatus =
    | 'not_started'
    | 'in_progress'
    | 'submitted'
    | 'done';

export type CasePlanDocument =
    | string
    | {
          label: string;
          note?: string | null;
          tone?: 'warn' | string;
      };

export type CasePlanStep =
    | string
    | {
          title: string;
          body?: string | null;
          link?: string | null;
      };

export type CasePlanDecision = {
    label: string;
    body?: string | null;
};

export type CasePlanSource = {
    kind: 'primary' | 'implementation' | string;
    label: string;
    url: string;
};

export type CasePlanQuestionOption = {
    value: string;
    label: string;
};

export type CasePlanQuestion = {
    id: number;
    type: 'enum' | 'date' | 'integer' | 'boolean';
    question: string;
    why: string;
    sensitivity: string | null;
    attempt: number;
    options: CasePlanQuestionOption[];
};

export type CasePlanConflict = {
    id: number;
    question: string;
    options: Array<{
        choice: 'existing' | 'candidate';
        label: string;
        context: string;
    }>;
};

export type CasePlanAi = {
    available: boolean;
    consented: boolean;
    processor_name: string | null;
    processor_privacy_url: string | null;
    remaining_quota: number;
};

export type CasePlanItem = {
    kind?: 'coverage_notice' | string;
    key?: string;
    content_version?: string | null;
    title?: string;
    description?: string | null;
    type?: 'task' | 'info' | string;
    phase?: string | null;
    urgency?: string | null;
    depends_on?: string[];
    deadline?: string | null;
    documents_required?: CasePlanDocument[];
    documents_checked?: string[];
    decision_options?: CasePlanDecision[];
    how_to_steps?: CasePlanStep[];
    links?: string[];
    legal_sources?: CasePlanSource[];
    verified_at?: string | null;
    high_impact?: boolean;
    status?: CasePlanTaskStatus;
    status_label?: string;
    completed_at?: string | null;
    questions?: Array<{
        question: string;
        why: string;
    }>;
    coverage_state?: CasePlanCoverageState;
};

export type CasePlanSections = Record<CasePlanSectionKey, CasePlanItem[]>;

export type CasePlan = {
    coverage_state: CasePlanCoverageState;
    generated_at: string | null;
    reassessment_at: string | null;
    sections: CasePlanSections;
    active_conflict: CasePlanConflict | null;
    next_question: CasePlanQuestion | null;
    ai: CasePlanAi;
};

export function documentLabel(document: CasePlanDocument): string {
    return typeof document === 'string' ? document : document.label;
}
