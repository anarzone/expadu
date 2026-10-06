import type { PlaceEvidence } from '@/components/places/types';

export type Slot = {
    id: string;
    type: string;
    name: string;
    subtitle?: string | null;
    location?: string | null;
    category: string;
    veedel?: string | null;
    lat?: number;
    lng?: number;
    start_at: string;
    end_at: string;
    start_time: string;
    end_time: string;
    swappable: boolean;
    travel_min_from_previous?: number;
    summary?: string | null;
    saved?: boolean;
    available?: boolean;
    recommendation_status?: 'available' | 'unavailable';
    availability_message?: string | null;
    place_facts?: PlaceEvidence | null;
    hours_assumed?: boolean;
    cost_tier?: 'free' | 'low' | 'normal' | 'unknown';
    distance_km_from_origin?: number | null;
    distance_basis?: 'straight_line_to_routing_point' | null;
    destination_group_id?: string | null;
    is_appointment?: boolean;
    closes_at?: string | null;
};
export type Plan = {
    date: string;
    slots: Slot[];
    constraints: Record<string, unknown>;
    feasibility_checked?: boolean;
    origin_label?: string | null;
};
export type Message = {
    id: string;
    role: 'user' | 'assistant';
    text: string;
    created_at: string;
    kind?: string;
    query?: string;
    plan?: Plan;
};
export type Conversation = {
    id: string;
    title: string;
    created_at: string;
    messages: Message[];
};
export type Version = {
    id: string;
    date: string;
    before: Plan | null;
    after: Plan | null;
    created_at: string;
    undone: boolean;
};
export type Workspace = {
    revision: number;
    approval: 'ask' | 'routine';
    active: string;
    focus: string | null;
    draft: Plan | null;
    plans: Record<string, Plan>;
    versions: Version[];
    conversations: Conversation[];
};
export type Command = {
    action: string;
    [key: string]: string | number | boolean | undefined;
};
export function dateLabel(date: string) {
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${date}T12:00:00`));
}
export function today() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Berlin',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}
export class ApiError extends Error {
    public status: number;

    constructor(message: string, status: number) {
        super(message);
        this.status = status;
    }
}
export async function api<T>(
    url: string,
    body?: object,
    signal?: AbortSignal,
): Promise<T> {
    const controller = new AbortController();
    const cancel = () => controller.abort(signal?.reason);
    const timeout = setTimeout(
        () =>
            controller.abort(
                new DOMException('Request timed out', 'TimeoutError'),
            ),
        30_000,
    );
    signal?.addEventListener('abort', cancel, { once: true });

    if (signal?.aborted) {
        cancel();
    }

    const unconfirmedWrite =
        'We couldn’t confirm whether that change was saved. Refresh to check your plan before trying again.';

    try {
        const response = await fetch(url, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            redirect: 'error',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') ?? '',
            },
            body: body ? JSON.stringify(body) : undefined,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));

            throw new ApiError(
                response.status >= 500
                    ? body
                        ? unconfirmedWrite
                        : 'Could not load this information. Please try again.'
                    : response.status === 401 || response.status === 419
                      ? 'Your session expired. Refresh the page to continue.'
                      : data.errors
                        ? String(Object.values(data.errors).flat()[0])
                        : (data.message ??
                          'Could not connect. Please try again.'),
                response.status,
            );
        }

        if (
            !response.headers.get('content-type')?.includes('application/json')
        ) {
            throw new Error(
                'The server did not return a JSON acknowledgement.',
            );
        }

        return await response.json();
    } catch (error) {
        if (signal?.aborted) {
            throw signal.reason;
        }

        if (error instanceof ApiError) {
            throw error;
        }

        throw new Error(
            body
                ? unconfirmedWrite
                : controller.signal.aborted
                  ? 'This is taking longer than expected. Check your connection and try again.'
                  : 'We couldn’t load this information. Check your connection and try again.',
        );
    } finally {
        clearTimeout(timeout);
        signal?.removeEventListener('abort', cancel);
    }
}
