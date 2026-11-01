import { router, usePage } from '@inertiajs/react';
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
import type { Plan, Process, Question, Requirement, Step } from './types';

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
    /** One search over tasks, dates, documents and Good to know (overview and documents). */
    q: string;
    /** Whose plan: someone who shared theirs; null is your own. */
    person: number | null;
}

/** The views the one search applies to. */
export const searchable: View[] = ['overview', 'paperwork'];

const views: View[] = ['overview', 'paperwork', 'actions', 'history', 'detail'];
const filters: ActionFilter[] = [
    'all',
    'todo',
    'blocked',
    'waiting',
    'completed',
];

/** The query of a URL or path ("/paperwork?view=paperwork" or "?view=paperwork"). */
const queryOf = (url: string) =>
    new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?')) : '');

/**
 * Where the page is, from a URL: the Inertia page's while rendering, so the
 * server (which has no window) and the browser render the same view; the
 * browser's own on back and forward.
 */
function fromUrl(url: string): Location {
    const q = queryOf(url);
    const view = q.get('view') as View | null;
    const filter = q.get('filter') as ActionFilter | null;

    return {
        view: view && views.includes(view) ? view : 'overview',
        process: q.get('process'),
        step: q.get('step'),
        filter: filter && filters.includes(filter) ? filter : 'all',
        paperFilter: q.get('documents') ?? 'all',
        focus: q.get('document'),
        q: q.get('q') ?? '',
        person: Number(q.get('person')) || null,
    };
}

function toUrl(location: Location): string {
    const url = new URL(window.location.href);
    url.search = '';

    if (location.person) {
        url.searchParams.set('person', String(location.person));
    }

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

    if (searchable.includes(location.view) && location.q.trim()) {
        url.searchParams.set('q', location.q);
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

/** Your situation, opened on its summary, on one answer, or on the plan's current question. */
export interface SituationRequest {
    key?: string;
    question?: Question;
    /** The starter run of a person with no answers yet (PW7). */
    starter?: boolean;
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
    situation: SituationRequest | null;
    openSituation: (request?: SituationRequest) => void;
    closeSituation: () => void;
    /** Fetch the latest plan after a change made outside run(). */
    refresh: () => Promise<void>;
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
    const page = usePage();
    const [location, setLocationState] = useState<Location>(() =>
        fromUrl(page.url),
    );
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [sheet, setSheet] = useState<Sheet | null>(null);
    const [editor, setEditor] = useState<Editor | null>(null);
    // `?guide=details` opens Your situation (Today's "Your situation" link), for
    // someone who may see the answers.
    const [situation, setSituation] = useState<SituationRequest | null>(() =>
        queryOf(page.url).get('guide') === 'details' &&
        plan.scopes.includes('view_facts')
            ? {}
            : null,
    );
    const timer = useRef<number | null>(null);

    useEffect(() => {
        const onPop = () => {
            setLocationState(fromUrl(window.location.search));
            setEditor(null);
            setSituation(null);
        };
        window.addEventListener('popstate', onPop);

        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const go = useCallback((next: Partial<Location> & { view: View }) => {
        setEditor(null);
        setSituation(null);
        setSheet(null);
        setLocationState((current) => {
            const location: Location = {
                ...current,
                process: null,
                step: null,
                focus: null,
                // The search follows you between Overview and Documents only.
                q: searchable.includes(next.view) ? current.q : '',
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
                setSituation(null);
                setEditor(next);
            },
            closeEditor: () => setEditor(null),
            situation,
            openSituation: (request = {}) => {
                setSheet(null);
                setEditor(null);
                setSituation(request);
            },
            closeSituation: () => {
                setSituation(null);
                const url = new URL(window.location.href);

                if (url.searchParams.has('guide')) {
                    url.searchParams.delete('guide');
                    window.history.replaceState({}, '', url.toString());
                }
            },
            refresh,
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
        situation,
        refresh,
    ]);

    return (
        <PaperworkContext.Provider value={value}>
            <div className="paperwork-page" data-case-view={location.view}>
                {children}
                <div
                    className={clsx('toast', message && 'show')}
                    role="status"
                    aria-live="polite"
                >
                    {message}
                </div>
            </div>
        </PaperworkContext.Provider>
    );
}
