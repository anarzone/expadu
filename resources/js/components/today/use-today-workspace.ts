import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '@/components/composer/types';
import type { Command, Workspace } from '@/components/composer/types';
import { show, update } from '@/routes/composer/workspace';

/** Read and edit the existing server-owned workspace; no second plan store. */
export function useTodayWorkspace(userId: number) {
    const [owned, setOwned] = useState<{
        userId: number;
        workspace: Workspace | null;
    }>({ userId, workspace: null });
    const current = useRef<Workspace | null>(null);
    const locked = useRef(false);
    const generation = useRef(0);
    const readController = useRef<AbortController | null>(null);
    const refresh = useRef<() => void>(() => {});
    const refreshPending = useRef(false);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        const scope = ++generation.current;
        current.current = null;
        locked.current = false;
        refreshPending.current = false;
        setOwned({ userId, workspace: null });
        setLoading(true);
        setBusy(false);
        setError('');
        let scheduled: ReturnType<typeof setTimeout> | undefined;

        function read() {
            if (locked.current) {
                refreshPending.current = true;

                return;
            }

            if (readController.current) {
                return;
            }

            const controller = new AbortController();
            readController.current = controller;
            api<Workspace>(show.url(), undefined, controller.signal)
                .then((state) => {
                    if (
                        controller.signal.aborted ||
                        generation.current !== scope
                    ) {
                        return;
                    }

                    // A delayed/cached read must not roll back an acknowledged edit.
                    if (
                        !current.current ||
                        state.revision >= current.current.revision
                    ) {
                        current.current = state;
                        setOwned({ userId, workspace: state });
                    }

                    setError('');
                })
                .catch((reason: unknown) => {
                    if (
                        !controller.signal.aborted &&
                        generation.current === scope
                    ) {
                        setError(
                            reason instanceof Error
                                ? reason.message
                                : 'Your plan could not be loaded.',
                        );
                    }
                })
                .finally(() => {
                    if (readController.current === controller) {
                        readController.current = null;
                    }

                    if (
                        !controller.signal.aborted &&
                        generation.current === scope
                    ) {
                        setLoading(false);
                    }
                });
        }
        function resume() {
            if (document.visibilityState !== 'visible') {
                return;
            }

            clearTimeout(scheduled);
            // Browsers commonly deliver focus and visibility together.
            scheduled = setTimeout(read, 100);
        }
        refresh.current = read;
        read();
        window.addEventListener('focus', resume);
        document.addEventListener('visibilitychange', resume);

        return () => {
            generation.current = scope + 1;
            clearTimeout(scheduled);
            readController.current?.abort();
            readController.current = null;
            refresh.current = () => {};
            window.removeEventListener('focus', resume);
            document.removeEventListener('visibilitychange', resume);
        };
    }, [userId]);

    async function send(command: Command) {
        if (!current.current || owned.userId !== userId) {
            throw new Error('Load your plan before making changes.');
        }

        const scope = generation.current;
        const state = await api<Workspace>(update.url(), {
            revision: current.current.revision,
            ...command,
        });

        if (generation.current !== scope) {
            throw new Error(
                'Your account changed. Reload your plan before continuing.',
            );
        }

        current.current = state;
        setOwned({ userId, workspace: state });

        return state;
    }

    async function run(operation: () => Promise<Workspace>) {
        if (locked.current) {
            return null;
        }

        const scope = generation.current;
        locked.current = true;
        readController.current?.abort();
        readController.current = null;
        setBusy(true);
        setError('');

        try {
            return await operation();
        } catch (reason) {
            if (generation.current !== scope) {
                return null;
            }

            if (reason instanceof ApiError && reason.status === 409) {
                try {
                    const state = await api<Workspace>(show.url());

                    if (generation.current !== scope) {
                        return null;
                    }

                    current.current = state;
                    setOwned({ userId, workspace: state });
                } catch {
                    // The conflict is still shown; never replay against unseen state.
                }
            }

            if (generation.current === scope) {
                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'Your change could not be confirmed. Refresh your plan before trying again.',
                );
            }

            return null;
        } finally {
            if (generation.current === scope) {
                locked.current = false;
                setBusy(false);

                if (refreshPending.current) {
                    refreshPending.current = false;
                    refresh.current();
                }
            }
        }
    }

    function edit(
        date: string,
        id: string,
        values: {
            name: string;
            start: string;
            end: string;
            location: string;
            expectedRevision?: number;
        },
    ) {
        return run(async () => {
            if (
                values.expectedRevision !== undefined &&
                values.expectedRevision !== current.current?.revision
            ) {
                throw new Error(
                    'Your plan changed while you were editing. Your unsaved text is still here. Cancel this edit and review the latest plan before saving.',
                );
            }

            if (current.current?.draft) {
                throw new Error(
                    'Review your unfinished changes in Composer before editing this plan.',
                );
            }

            await send({ action: 'open', date });

            return send({
                action: 'edit',
                id,
                name: values.name,
                start: values.start,
                end: values.end,
                location: values.location,
            });
        });
    }

    function retry() {
        setLoading(!current.current);
        setError('');
        refresh.current();
    }

    return {
        workspace: owned.userId === userId ? owned.workspace : null,
        loading: owned.userId !== userId || loading,
        busy: owned.userId === userId && busy,
        error: owned.userId === userId ? error : '',
        retry,
        edit,
        undo: () => run(() => send({ action: 'undo' })),
    };
}
