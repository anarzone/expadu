import { router } from '@inertiajs/react';
import { clsx } from 'clsx';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { CommandError } from './api';
import type { Plan, Process, Requirement, Step } from './types';

export type View = 'overview' | 'paperwork' | 'actions' | 'history' | 'detail';
export type ActionFilter = 'all' | 'todo' | 'blocked' | 'waiting' | 'completed';

export interface Location {
    view: View;
    process: string | null;
    step: string | null;
    filter: ActionFilter;
    paperFilter: string;
    /** A requirement row to open and scroll to on the documents view. */
    focus: string | null;
}

const views: View[] = ['overview', 'paperwork', 'actions', 'history', 'detail'];
const filters: ActionFilter[] = [
    'all',
    'todo',
    'blocked',
    'waiting',
    'completed',
];

function fromUrl(): Location {
    const q = new URLSearchParams(window.location.search);
    const view = q.get('view') as View | null;
    const filter = q.get('filter') as ActionFilter | null;

    return {
        view: view && views.includes(view) ? view : 'overview',
        process: q.get('process'),
        step: q.get('step'),
        filter: filter && filters.includes(filter) ? filter : 'all',
        paperFilter: q.get('documents') ?? 'all',
        focus: q.get('document'),
    };
}

function toUrl(location: Location): string {
    const url = new URL(window.location.href);
    url.search = '';

    if (location.view !== 'overview') {
        url.searchParams.set('view', location.view);
    }

    if (location.process) {
        url.searchParams.set('process', location.process);
    }

    if (location.step) {
        url.searchParams.set('step', location.step);
    }

    if (location.view === 'actions' && location.filter !== 'all') {
        url.searchParams.set('filter', location.filter);
    }

    if (location.view === 'paperwork' && location.paperFilter !== 'all') {
        url.searchParams.set('documents', location.paperFilter);
    }

    if (location.view === 'paperwork' && location.focus) {
        url.searchParams.set('document', location.focus);
    }

    return url.toString();
}

interface Sheet {
    title: string;
    body: ReactNode;
}

interface Editor {
    title: string;
    body: ReactNode;
}

interface PaperworkContextValue {
    plan: Plan;
    jurisdiction: string;
    location: Location;
    go: (next: Partial<Location> & { view: View }) => void;
    setLocation: (next: Partial<Location>) => void;
    /** Run a command, refresh the plan, then show what happened. Returns the refusal message, if any. */
    run: (
        command: () => Promise<unknown>,
        done?: string,
    ) => Promise<string | null>;
    busy: boolean;
    toast: (message: string) => void;
    sheet: Sheet | null;
    openSheet: (sheet: Sheet) => void;
    closeSheet: () => void;
    editor: Editor | null;
    openEditor: (editor: Editor) => void;
    closeEditor: () => void;
    processByKey: (key: string | null | undefined) => Process | undefined;
    stepById: (
        id: string | null | undefined,
    ) => { process: Process; step: Step } | undefined;
    requirements: Requirement[];
    canEdit: boolean;
}

const PaperworkContext = createContext<PaperworkContextValue | null>(null);

export function usePaperwork(): PaperworkContextValue {
    const value = useContext(PaperworkContext);

    if (!value) {
        throw new Error('usePaperwork needs a PaperworkProvider');
    }

    return value;
}

export function PaperworkProvider({
    plan,
    jurisdiction,
    children,
}: {
    plan: Plan;
    jurisdiction: string;
    children: ReactNode;
}) {
    const [location, setLocationState] = useState<Location>(() => fromUrl());
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [sheet, setSheet] = useState<Sheet | null>(null);
    const [editor, setEditor] = useState<Editor | null>(null);
    const timer = useRef<number | null>(null);

    useEffect(() => {
        const onPop = () => {
            setLocationState(fromUrl());
            setEditor(null);
        };
        window.addEventListener('popstate', onPop);

        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const go = useCallback((next: Partial<Location> & { view: View }) => {
        setEditor(null);
        setSheet(null);
        setLocationState((current) => {
            const location: Location = {
                ...current,
                process: null,
                step: null,
                focus: null,
                ...next,
            };
            window.history.pushState({}, '', toUrl(location));

            return location;
        });
        window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior });
        requestAnimationFrame(() =>
            document
                .querySelector<HTMLElement>('#view-heading, .paperwork-page h1')
                ?.focus({ preventScroll: true }),
        );
    }, []);

    const setLocation = useCallback((next: Partial<Location>) => {
        setLocationState((current) => {
            const location = { ...current, ...next };
            window.history.replaceState({}, '', toUrl(location));

            return location;
        });
    }, []);

    const toast = useCallback((text: string) => {
        setMessage(text);

        if (timer.current) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => setMessage(null), 4200);
    }, []);

    const refresh = useCallback(
        () =>
            new Promise<void>((resolve) => {
                router.reload({ only: ['entry'], onFinish: () => resolve() });
            }),
        [],
    );

    const run = useCallback(
        async (
            command: () => Promise<unknown>,
            done?: string,
        ): Promise<string | null> => {
            setBusy(true);

            try {
                await command();
                await refresh();

                if (done) {
                    toast(done);
                }

                return null;
            } catch (error) {
                const text =
                    error instanceof CommandError
                        ? error.message
                        : 'Something went wrong. Try again in a moment.';

                // A conflict means the page was out of date: show the latest plan.
                if (error instanceof CommandError && error.status === 409) {
                    await refresh();
                }

                return text;
            } finally {
                setBusy(false);
            }
        },
        [refresh, toast],
    );

    const value = useMemo<PaperworkContextValue>(() => {
        const all = [...plan.processes, ...plan.history];
        const requirements =
            'requirements' in plan.paperwork ? plan.paperwork.requirements : [];

        return {
            plan,
            jurisdiction,
            location,
            go,
            setLocation,
            run,
            busy,
            toast,
            sheet,
            openSheet: setSheet,
            closeSheet: () => setSheet(null),
            editor,
            openEditor: (next) => {
                setSheet(null);
                setEditor(next);
            },
            closeEditor: () => setEditor(null),
            processByKey: (key) =>
                key ? all.find((p) => p.occurrence_key === key) : undefined,
            stepById: (id) => {
                for (const process of all) {
                    const step = process.steps.find((s) => s.id === id);

                    if (step) {
                        return { process, step };
                    }
                }

                return undefined;
            },
            requirements,
            canEdit: plan.scopes.includes('manage_process'),
        };
    }, [
        plan,
        jurisdiction,
        location,
        go,
        setLocation,
        run,
        busy,
        toast,
        sheet,
        editor,
    ]);

    return (
        <PaperworkContext.Provider value={value}>
            {children}
            <div
                className={clsx('toast', message && 'show')}
                role="status"
                aria-live="polite"
            >
                {message}
            </div>
        </PaperworkContext.Provider>
    );
}
