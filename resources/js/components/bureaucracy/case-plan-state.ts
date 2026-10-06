import type { CasePlan } from './case-plan-types';

export type CaseAssistantState =
    | 'empty'
    | 'partial'
    | 'needs_information'
    | 'question'
    | 'conflict'
    | 'ready';

/** Presentation only; the server decides which rules and questions are valid. */
export function caseAssistantState(plan: CasePlan): CaseAssistantState {
    if (plan.active_conflict || plan.coverage_state === 'conflict') {
        return 'conflict';
    }

    if (plan.next_question) {
        return 'question';
    }

    if (
        plan.coverage_state === 'needs_information' ||
        plan.sections.information_needed.length > 0
    ) {
        return 'needs_information';
    }

    const hasReviewedContent = Object.entries(plan.sections).some(
        ([section, items]) =>
            !['information_needed', 'opens_when', 'not_covered'].includes(
                section,
            ) && items.some((item) => !!item.key),
    );

    if (!hasReviewedContent) {
        return 'empty';
    }

    if (
        plan.coverage_state === 'not_covered' ||
        plan.sections.not_covered.length > 0
    ) {
        return 'partial';
    }

    return 'ready';
}
