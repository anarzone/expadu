import type { PlaceEvidence } from '@/components/place-shared/types';
import { csrfHeaders } from '@/lib/csrf';
import { isSignedOutStatus, SIGNED_OUT } from '@/lib/signed-out';

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
    /** False for an event with no listed end: end_time is only the plan's length. */
    end_listed?: boolean;
    swappable: boolean;
    travel_min_from_previous?: number | null;
    /** False when the way in isn't estimated (a place with no map point). */
    travel_known?: boolean;
    /** The latest to leave the stop before, when there's travel into this one. */
    leave_by?: string | null;
    /** The planner's length of the visit ("~1h"), when known. */
    duration_label?: string | null;
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
    /** In the catalogue: what Around here calls it ("Café"), its kind (colour, icon) and group ("Cafés"). */
    label?: string;
    kind?: string;
    group?: string;
    /** The day's listed hours ("Open 09:00–18:00", "Hours not listed"); events have their time. */
    hours?: string | null;
};
/** A slot's time as said: "13:00–14:00", or the start alone when its end isn't known or listed. */
export const slotSpan = (slot: Slot) =>
    slot.end_time && slot.end_listed !== false
        ? `${slot.start_time}–${slot.end_time}`
        : slot.start_time;
export type Plan = {
    date: string;
    slots: Slot[];
    constraints: Record<string, unknown>;
    feasibility_checked?: boolean;
    origin_label?: string | null;
};
/** What a change did to one item: moved (old → new time), added, removed, or in place of another. */
export type ChangeRow = {
    kind: 'moved' | 'added' | 'removed' | 'replaced';
    id: string;
    title: string;
    type: string;
    from?: string;
    to?: string;
    was?: string;
};
export type Message = {
    id: string;
    role: 'user' | 'assistant';
    text: string;
    created_at: string;
    /** plan · change · undone · earlier_draft · bureaucracy · find · journey · unknown · failed */
    kind?: string;
    query?: string;
    /** A plan card's day, as it is now (null once cancelled); an earlier draft's own copy. */
    plan?: Plan | null;
    date?: string;
    change_id?: string;
    rows?: ChangeRow[];
    code?: string;
    draft_id?: string;
    reapplied_change?: string | null;
};
export type Conversation = {
    id: string;
    title: string;
    created_at: string;
    /** The open conversation's messages; the others come when one is opened (resume). */
    messages: Message[];
    /** How many times they asked in it. */
    asked: number;
    /** When it was last written in: its newest message, else its start. */
    last_at: string;
};
/** One applied change; Undo works newest first per day. */
export type Change = {
    id: string;
    date: string;
    label: string;
    rows: ChangeRow[];
    created_at: string | null;
    undone_at: string | null;
    undoable: boolean;
};
export type Workspace = {
    revision: number;
    schema: number;
    active: string;
    focus: string | null;
    plans: Record<string, Plan>;
    changes: Change[];
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
    /** The response body, e.g. a refusal's `workspace` as it now is. */
    public data: Record<string, unknown>;

    constructor(
        message: string,
        status: number,
        data: Record<string, unknown> = {},
    ) {
        super(message);
        this.status = status;
        this.data = data;
    }
}
export async function api<T>(
    url: string,
    body?: object,
    signal?: AbortSignal,
    /** How long to wait; the assistant's answer gets longer than a command. */
    timeoutMs = 30_000,
): Promise<T> {
    const controller = new AbortController();
    const cancel = () => controller.abort(signal?.reason);
    const timeout = setTimeout(
        () =>
            controller.abort(
                new DOMException('Request timed out', 'TimeoutError'),
            ),
        timeoutMs,
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
                ...csrfHeaders(),
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
                    : isSignedOutStatus(response.status)
                      ? SIGNED_OUT
                      : data.errors
                        ? String(Object.values(data.errors).flat()[0])
                        : (data.message ??
                          'Could not connect. Please try again.'),
                response.status,
                data,
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
