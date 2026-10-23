import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ask } from '@/actions/App/Http/Controllers/ComposerController';
import {
    show,
    update,
} from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { aiPermit } from './ai-notice';
import type { AiNotice } from './ai-notice';
import type { PlanOrigin } from './origin-picker';
import { watchProgress } from './progress';
import { api, ApiError } from './types';
import type { Command, Slot, Workspace } from './types';

/*
 * Composer's conversation engine, shared by its full page and the answer under
 * the tray's box (direction B, owner 22 Oct 2026): the workspace, sending a
 * request (the message, then Composer's one answer to it) and each change to a
 * plan. One copy, so both surfaces behave the same. What a surface does around
 * it (scrolling, its box, its panels) comes in as `on`.
 */

/** The pages Composer can be opened on, by the name the assistant is told. */
const PAGES: Record<string, string> = {
    dashboard: 'Today',
    timetable: 'Travel',
    events: 'Events',
    'around-here': 'Places',
    paperwork: 'Paperwork',
    composer: 'Composer',
};

export type EngineSwap = { slot: Slot; date: string; revision: number };

export type EngineHooks = {
    /** The plan the request starts from (the page's From); none in the tray. */
    origin?: PlanOrigin | null;
    pins?: string[];
    /** A request went out: clear the box, show it. */
    onAsking?: (text: string) => void;
    /** Its answer (or the reply that it failed) is in the conversation. */
    onAnswered?: () => void;
    /** It failed: put the words back for another go. */
    onFailed?: (text: string) => void;
    /** Done either way (focus back to the box). */
    onSettled?: () => void;
    /** The plan changed elsewhere: the newest is loaded. */
    onConflict?: () => void;
    /** "Swap …" picks an item to replace. */
    onSwap?: (swap: EngineSwap) => void;
    /** "Show my plan" with no single day: open My Plans. */
    onPlans?: () => void;
};

export const normal = (text: string) =>
    text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ue/g, 'u')
        .replace(/oe/g, 'o')
        .replace(/ae/g, 'a');

export function useComposerEngine(
    initial: Workspace,
    aiNotice: AiNotice | null,
    hooks: EngineHooks = {},
) {
    const page = PAGES[usePage().component] ?? null;
    const [workspace, setWorkspace] = useState(initial);
    const stateRef = useRef(initial);
    const [busy, setBusy] = useState('');
    const busyRef = useRef(false);
    const [error, setError] = useState('');
    const [retryMessage, setRetryMessage] = useState<string | null>(null);
    const [pendingText, setPendingText] = useState('');
    const on = useRef(hooks);
    useEffect(() => {
        on.current = hooks;
    });

    function setState(state: Workspace) {
        stateRef.current = state;
        setWorkspace(state);
    }
    async function sendCommand(command: Command) {
        try {
            const result = await api<Workspace>(update.url(), {
                revision: stateRef.current.revision,
                ...command,
            });
            setState(result);

            return result;
        } catch (err) {
            // Never automatically replay a mutation against a different plan version.
            if (err instanceof ApiError && err.status === 409) {
                setState(await api<Workspace>(show.url()));
                on.current.onConflict?.();
            }

            // A change that couldn't go in left the plan as it was; the reply
            // saying why is in the workspace that came back.
            if (
                err instanceof ApiError &&
                err.status === 422 &&
                err.data.workspace
            ) {
                setState(err.data.workspace as Workspace);
            }

            throw err;
        }
    }
    async function command(command: Command): Promise<boolean> {
        if (busyRef.current) {
            return false;
        }

        busyRef.current = true;
        setBusy('Updating your plan…');
        setError('');

        try {
            await sendCommand(command);

            return true;
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not save. Try again.',
            );

            return false;
        } finally {
            busyRef.current = false;
            setBusy('');
        }
    }
    /** Asks a request; `notice` is the AI notice the person saw when they sent it. */
    async function send(text: string, notice: AiNotice | null = aiNotice) {
        if (!text.trim() || busyRef.current) {
            return;
        }

        busyRef.current = true;
        setBusy('Working on your request…');
        setError('');
        setRetryMessage(null);
        setPendingText(text);
        on.current.onAsking?.(text);

        // Once the message is in the conversation, Composer answers it even when the
        // request fails: the reply says so, and the bar says why.
        let asked = false;

        try {
            await sendCommand({ action: 'message', text });
            asked = true;
            setPendingText('');
            const state = stateRef.current;
            const current = state.focus
                ? (state.plans[state.focus] ?? null)
                : null;
            const clean = normal(text);

            if (/^(show|view|open)( me)?( my| the)? plan\b/.test(clean)) {
                const dates = Object.keys(state.plans);
                const date =
                    state.focus ?? (dates.length === 1 ? dates[0] : null);

                if (date) {
                    await sendCommand({ action: 'open', date });
                } else {
                    on.current.onPlans?.();
                }
            } else if (
                current &&
                /^(move|change|rename|remove|swap)\b/.test(clean)
            ) {
                const matched = current.slots.filter((slot) =>
                    clean.includes(normal(slot.name)),
                );

                if (matched.length !== 1) {
                    throw new Error(
                        'Choose an activity in the plan below, or include its full name so I know which one to change.',
                    );
                }

                const slot = matched[0];
                const times = text.match(/\b(?:[01]?\d|2[0-3]):[0-5]\d\b/g);

                if (clean.startsWith('swap')) {
                    on.current.onSwap?.({
                        slot,
                        date: current.date,
                        revision: stateRef.current.revision,
                    });
                } else if (clean.startsWith('remove')) {
                    await sendCommand({
                        action: 'remove',
                        id: slot.id,
                        date: current.date,
                    });
                } else if (
                    /^(rename|change.*name)/.test(clean) &&
                    /\s+to\s+/i.test(text)
                ) {
                    await sendCommand({
                        action: 'edit',
                        id: slot.id,
                        date: current.date,
                        name: text
                            .split(/\s+to\s+/i)
                            .slice(1)
                            .join(' to '),
                    });
                } else if (times?.length === 2) {
                    await sendCommand({
                        action: 'edit',
                        id: slot.id,
                        date: current.date,
                        start: times[0].padStart(5, '0'),
                        end: times[1].padStart(5, '0'),
                    });
                } else {
                    throw new Error(
                        'Include both times, for example “move ' +
                            slot.name +
                            ' to 14:00–15:00”, or use Edit time.',
                    );
                }
            } else {
                // Composer's assistant answers it: the server reads the message from the
                // conversation, looks things up from where you start and answers; a plan it
                // shows goes into your plans. If the say-so is refused (an outdated notice),
                // it's answered without the AI, and the answer says so.
                setBusy('Looking into it…');
                const origin = on.current.origin ?? null;
                const where = {
                    ...(page ? { page } : {}),
                    ...(origin
                        ? {
                              lat: origin.lat,
                              lng: origin.lng,
                              from_place: origin.from_place,
                              from_area: origin.from_area,
                              from_label: origin.label.slice(0, 80),
                          }
                        : {}),
                };
                // The say-so for this request; while the assistant works, the line says how far.
                const permit = aiPermit(notice) as {
                    processing?: { request_id: string };
                };
                const stop = permit.processing
                    ? watchProgress(permit.processing.request_id, setBusy)
                    : () => undefined;
                const answered = await api<Workspace>(ask.url(), {
                    ...where,
                    ...permit,
                })
                    .catch((error: unknown) =>
                        notice
                            ? api<Workspace>(ask.url(), where)
                            : Promise.reject(error),
                    )
                    .finally(stop);
                setState(answered);
            }

            on.current.onAnswered?.();
        } catch (err) {
            if (asked) {
                await sendCommand({ action: 'reply', kind: 'failed' }).catch(
                    () => undefined,
                );
                on.current.onAnswered?.();
            }

            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not finish that request.',
            );
            setRetryMessage(text);
            on.current.onFailed?.(text);
        } finally {
            setPendingText('');
            setBusy('');
            busyRef.current = false;
            on.current.onSettled?.();
        }
    }

    return {
        workspace,
        stateRef,
        setState,
        busy,
        setBusy,
        busyRef,
        error,
        setError,
        retryMessage,
        setRetryMessage,
        pendingText,
        setPendingText,
        sendCommand,
        command,
        send,
    };
}
