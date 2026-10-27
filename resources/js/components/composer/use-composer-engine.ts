import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    act as actRoute,
    applyAction,
    ask,
    undoAction,
} from '@/actions/App/Http/Controllers/ComposerController';
import {
    show,
    update,
} from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { aiNoticeUrl, aiPermit } from './ai-notice';
import type { AiNotice } from './ai-notice';
import { readAiNotice } from './ai-notice-read';
import type { PlanOrigin } from './origin-store';
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
    today: 'Today',
    timetable: 'Travel',
    events: 'Events',
    'around-here': 'Places',
    paperwork: 'Paperwork',
    composer: 'Composer',
};

/** The assistant's answer: its own budget (App\Composer\Assistant) and the tools after it. */
const ASK_TIMEOUT = 60_000;

/** Whether the person's last message in the open conversation has a reply after it. */
export function replied(state: Workspace): boolean {
    const messages =
        state.conversations.find((item) => item.id === state.active)
            ?.messages ?? [];
    const asked = messages.findLastIndex((message) => message.role === 'user');

    return (
        asked >= 0 &&
        messages
            .slice(asked + 1)
            .some((message) => message.role === 'assistant')
    );
}

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
};

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
    // The last request's message is in but unanswered: Retry asks again.
    const againRef = useRef(false);
    // The messages this page sent and saw through (answered, or handled here:
    // a plan opened, a swap), so only one left behind (a reload mid-answer)
    // reads as unanswered.
    const handled = useRef(new Set<string>());
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
    /**
     * Composer's assistant answers the person's last message: the server reads it
     * from the conversation, looks things up from where they start and answers; a
     * plan it shows goes into their plans. Only a refused say-so (an outdated
     * notice) is asked again without the AI, and the answer says so: a timeout or
     * a server error isn't asked twice, since the first may still be answering.
     */
    async function answer(notice: AiNotice | null, extra: object = {}) {
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

        try {
            setState(
                await api<Workspace>(
                    ask.url(),
                    { ...where, ...permit, ...extra },
                    undefined,
                    ASK_TIMEOUT,
                ).catch((error: unknown) =>
                    notice &&
                    error instanceof ApiError &&
                    (error.status === 403 || error.status === 422)
                        ? api<Workspace>(
                              ask.url(),
                              where,
                              undefined,
                              ASK_TIMEOUT,
                          )
                        : Promise.reject(error),
                ),
            );
            on.current.onAnswered?.();
        } finally {
            stop();
        }
    }

    /**
     * The answer didn't come: a late one may be in the conversation after all, so
     * that's read first; otherwise the conversation says it couldn't answer, with
     * Try again (`again`). Only when even that can't be saved does the bar say so.
     */
    async function unanswered() {
        const latest = await api<Workspace>(show.url()).catch(() => null);

        if (latest && replied(latest)) {
            setState(latest);
            on.current.onAnswered?.();

            return;
        }

        try {
            await sendCommand({ action: 'reply', kind: 'failed' });
            on.current.onAnswered?.();
        } catch {
            againRef.current = true;
            setError('Composer couldn’t answer that, and couldn’t save why.');
            setRetryMessage('again');
        }
    }

    /**
     * The notice a request goes under: the one in view. Without it, it's read now
     * (once, NOTICE_TIMEOUT): off, the request goes without the AI (and the answer
     * says it's off); on but not yet seen, it waits for them to send again with the
     * line in view (sending is the consent); unreadable, it waits for Try again.
     * Never sent without a permit while the AI could answer it.
     */
    async function noticeFor(shown: AiNotice | null): Promise<AiNotice | null> {
        if (shown) {
            return shown;
        }

        const read = await readAiNotice(aiNoticeUrl());

        if (read.state === 'off') {
            return null;
        }

        throw new Error(
            read.state === 'on'
                ? 'Composer answers with an AI service: the line under the box says which. Send again to go ahead.'
                : 'Couldn’t reach the AI service. Try again.',
        );
    }

    /** Try again: the same message, asked again, not sent again. */
    async function again(
        shown: AiNotice | null = aiNotice,
        extra: object = {},
    ) {
        if (busyRef.current) {
            return;
        }

        busyRef.current = true;
        againRef.current = false;
        setError('');
        setRetryMessage(null);

        let notice: AiNotice | null;

        try {
            notice = await noticeFor(shown);
        } catch (err) {
            // Still in the conversation, unanswered: Try again asks it once the notice is in view.
            againRef.current = true;
            setError(err instanceof Error ? err.message : 'Try again.');
            setRetryMessage('again');
            busyRef.current = false;
            on.current.onSettled?.();

            return;
        }

        try {
            await answer(notice, extra);
        } catch {
            await unanswered();
        } finally {
            setBusy('');
            busyRef.current = false;
            on.current.onSettled?.();
        }
    }

    /**
     * A change Composer made or proposed (the "Composer does things" canvas):
     * Undo (24 hours), Apply under Ask first, or a record row changed in place.
     * The server makes it through the app's own action and answers with the
     * workspace, the change's line in it; a refusal says why in the bar.
     */
    async function act(url: string, body: object) {
        if (busyRef.current) {
            return;
        }

        busyRef.current = true;
        setBusy('Updating…');
        setError('');

        try {
            setState(await api<Workspace>(url, body));
            on.current.onAnswered?.();
        } catch (err) {
            if (err instanceof ApiError && err.data.workspace) {
                setState(err.data.workspace as Workspace);
            }

            setError(
                err instanceof Error ? err.message : 'That didn’t go through.',
            );
        } finally {
            setBusy('');
            busyRef.current = false;
        }
    }
    const undo = (token: string) => void act(undoAction.url(), { token });
    const apply = (token: string) => void act(applyAction.url(), { token });
    const change = (key: string, value: string) =>
        void act(actRoute.url(), { action: 'paperwork_fact', key, value });
    /** Paperwork's say-so for this message: the same message, asked again with it. */
    const allow = (disclosure: {
        notice_version: string;
        provider_version: string;
    }) =>
        void again(aiNotice, {
            paperwork_processing: {
                consent: true,
                request_id: crypto.randomUUID(),
                notice_version: disclosure.notice_version,
                provider_version: disclosure.provider_version,
            },
        });

    /** The bar's Retry: asks again when the message is in, else sends it. */
    function retry() {
        if (againRef.current) {
            void again();
        } else if (retryMessage) {
            void send(retryMessage);
        }
    }

    /** Asks a request; `shown` is the AI notice in view when they sent it. */
    async function send(text: string, shown: AiNotice | null = aiNotice) {
        if (!text.trim() || busyRef.current) {
            return;
        }

        busyRef.current = true;
        againRef.current = false;
        setBusy('Working on your request…');
        setError('');
        setRetryMessage(null);
        setPendingText(text);
        on.current.onAsking?.(text);

        // Once the message is in the conversation, Composer answers it even when the
        // request fails: the reply says so, and the bar says why.
        let asked = false;

        try {
            // Before the message goes in: without its notice it stays in the box.
            const notice = await noticeFor(shown);
            await sendCommand({ action: 'message', text });
            asked = true;
            setPendingText('');
            // Every message goes to the one AI loop: its tools show, move, swap and remove plans.
            await answer(notice);

            on.current.onAnswered?.();
        } catch (err) {
            // Stored, it's answered or says it couldn't be: never resent, so never twice.
            if (asked) {
                await unanswered();
            } else {
                setError(
                    err instanceof Error
                        ? err.message
                        : 'Could not finish that request.',
                );
                setRetryMessage(text);
                on.current.onFailed?.(text);
            }
        } finally {
            const messages =
                stateRef.current.conversations.find(
                    (item) => item.id === stateRef.current.active,
                )?.messages ?? [];
            const last = messages.findLast(
                (message) => message.role === 'user',
            );

            if (last) {
                handled.current.add(last.id);
            }

            setPendingText('');
            setBusy('');
            busyRef.current = false;
            on.current.onSettled?.();
        }
    }

    /** Whether the person's newest message was left without a reply, not handled here. */
    function leftUnanswered(): boolean {
        const messages =
            workspace.conversations.find((item) => item.id === workspace.active)
                ?.messages ?? [];
        const last = messages.at(-1);

        return (
            !busy &&
            !pendingText &&
            last?.role === 'user' &&
            !handled.current.has(last.id)
        );
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
        again,
        retry,
        leftUnanswered,
        undo,
        apply,
        change,
        allow,
    };
}
