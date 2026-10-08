// JSON commands for the bureaucracy/v2 API. Every write carries a fresh request id
// and the version the person last saw; the server refuses stale or replayed writes.

import type {
    Answer,
    FactConflict,
    FactDefinition,
    FactHistory,
    FactView,
    QuestionOffer,
} from './types';

export class CommandError extends Error {
    constructor(
        message: string,
        public status: number,
    ) {
        super(message);
    }
}

function csrf(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

export function requestId(): string {
    return crypto.randomUUID();
}

/** The backend's own message for a refusal, never a generic "ad blocker". */
function messageFor(status: number, body: unknown): string {
    const data = body as {
        message?: string;
        errors?: Record<string, string[]>;
    } | null;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;

    if (first) {
        return first;
    }

    if (status === 409) {
        return (
            data?.message ||
            'This changed elsewhere. The page has the latest version now; try again.'
        );
    }

    if (status === 419 || status === 401) {
        return 'Your session expired. Reload the page and sign in again.';
    }

    if (status === 403 || status === 404) {
        return 'This record is not available to you.';
    }

    if (status === 429) {
        return 'Too many changes at once. Wait a moment and try again.';
    }

    return (
        data?.message ||
        'Something went wrong on our side. Try again in a moment.'
    );
}

/** A private read of the person's own record (never cached). */
export async function read<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const data = await response.json().catch(() => null);

    if (!response.ok) {
        throw new CommandError(
            messageFor(response.status, data),
            response.status,
        );
    }

    return data as T;
}

export async function send<T = unknown>(
    method: 'POST' | 'PUT' | 'DELETE',
    url: string,
    body: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => null);

    if (!response.ok) {
        throw new CommandError(
            messageFor(response.status, data),
            response.status,
        );
    }

    return data as T;
}

const base = '/bureaucracy/v2';

export const reads = {
    facts: (personId: number) =>
        read<FactView>(`${base}/people/${personId}/facts`),
    schema: () => read<{ facts: FactDefinition[] }>(`${base}/facts/schema`),
    history: (personId: number, key: string) =>
        read<FactHistory>(`${base}/people/${personId}/facts/${key}/history`),
    conflicts: (personId: number) =>
        read<{ revision: number; conflicts: FactConflict[] }>(
            `${base}/people/${personId}/fact-conflicts`,
        ),
};

export const commands = {
    startProcess: (
        personId: number,
        jurisdiction: string,
        occurrenceKey: string,
        reviewToken: string,
    ) =>
        send('POST', `${base}/people/${personId}/processes`, {
            jurisdiction,
            occurrence_key: occurrenceKey,
            review_token: reviewToken,
            request_id: requestId(),
        }),
    recordEvent: (
        processId: number,
        version: number,
        event: string,
        payload: Record<string, unknown>,
        reviewToken: string | null,
    ) =>
        send('POST', `${base}/processes/${processId}/events`, {
            request_id: requestId(),
            expected_version: version,
            review_token: reviewToken,
            event,
            payload,
        }),
    recordEvidence: (
        personId: number,
        evidenceId: string,
        version: number,
        details: Record<string, unknown>,
        status: 'active' | 'archived' = 'active',
    ) =>
        send('PUT', `${base}/people/${personId}/evidence/${evidenceId}`, {
            request_id: requestId(),
            expected_version: version,
            details,
            status,
        }),
    confirmRequirement: (
        processId: number,
        requirementId: string,
        version: number,
        evidenceId: string,
        evidenceVersion: number,
        requirementHash: string,
    ) =>
        send(
            'POST',
            `${base}/processes/${processId}/requirements/${encodeURIComponent(requirementId)}/confirm`,
            {
                request_id: requestId(),
                expected_version: version,
                evidence_id: evidenceId,
                evidence_version: evidenceVersion,
                requirement_hash: requirementHash,
                confirmed: true,
            },
        ),
    reviewProcess: (
        processId: number,
        version: number,
        reviewToken: string,
        bindOccurrence: string | null,
    ) =>
        send('POST', `${base}/processes/${processId}/review`, {
            request_id: requestId(),
            expected_version: version,
            review_token: reviewToken,
            bind_occurrence: bindOccurrence,
            confirmed: true,
        }),
    startQuestions: (personId: number, jurisdiction: string) =>
        send<{ session_id: number }>(
            'POST',
            `${base}/people/${personId}/question-sessions`,
            { jurisdiction, request_id: requestId() },
        ),
    nextQuestion: (sessionId: number) =>
        send<QuestionOffer>(
            'POST',
            `${base}/question-sessions/${sessionId}/next`,
            {
                request_id: requestId(),
            },
        ),
    answerQuestion: (
        sessionId: number,
        questionId: number,
        token: string,
        answer: Answer,
    ) =>
        send(
            'POST',
            `${base}/question-sessions/${sessionId}/answers/${questionId}`,
            {
                token,
                value: answer.value,
                answer_state: answer.state,
                operation: 'assert',
            },
        ),
    deferQuestion: (sessionId: number, questionId: number, token: string) =>
        send(
            'POST',
            `${base}/question-sessions/${sessionId}/defer/${questionId}`,
            {
                token,
            },
        ),
    resumeQuestions: (sessionId: number, revisitDeferred: boolean) =>
        send('POST', `${base}/question-sessions/${sessionId}/resume`, {
            revisit_deferred: revisitDeferred,
        }),
    /** A real change in circumstances, or a first answer outside a question. */
    changeFact: (
        personId: number,
        key: string,
        answer: Answer,
        effectiveFrom: string | null,
        revision: number,
    ) =>
        send('PUT', `${base}/people/${personId}/facts/${key}`, {
            value: answer.value,
            answer_state: answer.state,
            effective_from: effectiveFrom,
            expected_revision: revision,
        }),
    /** An earlier answer that was wrong when it was given. */
    correctFact: (
        personId: number,
        factId: number,
        answer: Answer,
        revision: number,
    ) =>
        send('POST', `${base}/people/${personId}/facts/${factId}/corrections`, {
            value: answer.value,
            answer_state: answer.state,
            expected_revision: revision,
        }),
    resolveConflict: (
        personId: number,
        key: string,
        answer: Answer,
        revision: number,
        reviewToken: string,
    ) =>
        send(
            'POST',
            `${base}/people/${personId}/fact-conflicts/${key}/resolve`,
            {
                value: answer.value,
                answer_state: answer.state,
                expected_revision: revision,
                review_token: reviewToken,
                request_id: requestId(),
                confirmed: true,
            },
        ),
    withdrawRequirement: (
        processId: number,
        requirementId: string,
        version: number,
    ) =>
        send(
            'DELETE',
            `${base}/processes/${processId}/requirements/${encodeURIComponent(requirementId)}/confirmation`,
            {
                request_id: requestId(),
                expected_version: version,
            },
        ),
};
