import { Head, Link, router } from '@inertiajs/react';
import { clsx } from 'clsx';
import { TaskDetail } from '@/components/paperwork/detail';
import { Documents } from '@/components/paperwork/documents';
import { InlineEditor, SheetDialog } from '@/components/paperwork/editors';
import { taskGroup } from '@/components/paperwork/format';
import { Icon } from '@/components/paperwork/icons';
import { AllActions, History, SourcesBody } from '@/components/paperwork/lists';
import { Overview } from '@/components/paperwork/overview';
import {
    PaperworkProvider,
    searchable,
    usePaperwork,
} from '@/components/paperwork/state';
import type { Person, PlanEntry } from '@/components/paperwork/types';
import AppLayout from '@/layouts/app-layout';
import { usePageContext } from '@/lib/page-context';
import '../../css/paperwork.css';

interface Props {
    entry: PlanEntry;
    jurisdiction: string;
    people: Person[];
}

/** One search over tasks, dates, documents and Good to know; Escape or Clear empties it. */
function SearchField() {
    const { location, setLocation } = usePaperwork();

    return (
        <label className="case-search">
            <Icon name="search" />
            <span className="sr-only">Search your paperwork</span>
            <input
                type="search"
                id="case-search"
                placeholder="Search your paperwork"
                autoComplete="off"
                enterKeyHint="search"
                value={location.q}
                onChange={(event) => setLocation({ q: event.target.value })}
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && location.q) {
                        event.preventDefault();
                        setLocation({ q: '' });
                    }
                }}
            />
            <button
                type="button"
                className="case-search-clear"
                aria-label="Clear search"
                hidden={!location.q}
                onClick={() => setLocation({ q: '' })}
            >
                <Icon name="close" />
            </button>
        </label>
    );
}

/** Whose plan: your own, or someone who shared theirs with you. */
function PersonPicker({ people }: { people: Person[] }) {
    const { plan } = usePaperwork();
    const current = people.find((p) => p.id === plan.person_id);
    const name = (p: Person | undefined) => (!p || p.is_self ? 'You' : p.label);

    return (
        <details className="person-picker">
            <summary aria-label={`Whose plan: ${name(current)}`}>
                <span className="person-name">{name(current)}</span>
                <Icon name="down" />
            </summary>
            <div className="person-menu">
                {people.map((p) => {
                    const chosen = p.id === plan.person_id;

                    return (
                        <button
                            type="button"
                            key={p.id}
                            aria-pressed={chosen}
                            onClick={() =>
                                chosen
                                    ? undefined
                                    : router.visit(
                                          p.is_self
                                              ? '/bureaucracy'
                                              : `/bureaucracy?person=${p.id}`,
                                      )
                            }
                        >
                            <Icon name={chosen ? 'check' : 'people'} />
                            <span>
                                {name(p)}
                                <small>
                                    {p.is_self
                                        ? 'Your own plan'
                                        : 'Shared with you'}
                                </small>
                            </span>
                        </button>
                    );
                })}
            </div>
        </details>
    );
}

function Workspace({ people }: { people: Person[] }) {
    const { plan, location, go, processByKey, openSheet, openSituation } =
        usePaperwork();
    const situationAllowed = plan.scopes.includes('view_facts');
    const process =
        location.view === 'detail' ? processByKey(location.process) : undefined;
    // No page title on screen (9 Oct): the navigation already says where you are, so the
    // views share one row with whose plan and Your situation. A task's own name stays.
    const titled = process !== undefined;
    const heading = process?.title ?? 'Paperwork';
    const withSearch =
        !titled &&
        searchable.includes(location.view) &&
        plan.processes.some((p) => !p.is_closed);
    const coverage = () =>
        openSheet({ title: 'Source and coverage', body: <SourcesBody /> });

    // What Composer's tray says this page is about: the task on screen, otherwise the
    // person's next one to do (blocked and waiting ones aren't), as the mock's
    // page-context.js names it.
    const next = plan.processes.find(
        (p) => !p.is_closed && taskGroup(p) === 'todo',
    );
    const about = process ?? next;
    usePageContext({
        page: 'paperwork',
        label: about ? `Paperwork · ${about.title}` : 'Paperwork',
    });

    return (
        <>
            <div
                className={clsx(
                    'case-top',
                    !titled && 'is-untitled',
                    withSearch && 'is-searchable',
                )}
            >
                <h1 tabIndex={-1} className={titled ? undefined : 'sr-only'}>
                    {heading}
                </h1>
                {titled ? null : (
                    <div className="case-tabs" aria-label="Paperwork views">
                        <button
                            type="button"
                            aria-pressed={location.view !== 'paperwork'}
                            onClick={() => go({ view: 'overview' })}
                        >
                            <Icon name="grid" />
                            Overview
                        </button>
                        <button
                            type="button"
                            aria-pressed={location.view === 'paperwork'}
                            onClick={() =>
                                go({ view: 'paperwork', paperFilter: 'all' })
                            }
                        >
                            <Icon name="document" />
                            Documents
                        </button>
                    </div>
                )}
                {withSearch ? <SearchField /> : null}
                <PersonPicker people={people} />
                {situationAllowed ? (
                    <button
                        type="button"
                        className="icon-button case-details"
                        aria-label="Your situation"
                        onClick={() => openSituation()}
                    >
                        <Icon name="sliders" />
                        <span className="case-details-long">
                            Your situation
                        </span>
                        <span className="case-details-short">Situation</span>
                    </button>
                ) : null}
            </div>
            <InlineEditor />
            <div className="bureaucracy-view">
                {location.view === 'paperwork' ? (
                    <Documents />
                ) : location.view === 'actions' ? (
                    <AllActions />
                ) : location.view === 'history' ? (
                    <History />
                ) : location.view === 'detail' ? (
                    <TaskDetail />
                ) : (
                    <Overview onCoverage={coverage} />
                )}
            </div>
            <div className="case-footnote">
                <Icon name="info" />
                <span>Your progress is your own record</span>
                <button type="button" onClick={coverage}>
                    Where this comes from
                </button>
            </div>
            <SheetDialog />
        </>
    );
}

function Unavailable({ state }: { state: PlanEntry['state'] }) {
    return (
        <section className="access-blank">
            <h2>
                {state === 'setup_required'
                    ? 'Tell us a little about your move first.'
                    : 'Your paperwork record isn’t available right now.'}
            </h2>
            <p>
                {state === 'setup_required'
                    ? 'A few answers about your situation let us show the tasks that apply to you.'
                    : 'Your record is paused or being removed. Contact us if you didn’t expect this.'}
            </p>
            {state === 'setup_required' ? (
                <div className="actions">
                    <Link className="button primary" href="/onboarding">
                        Get started
                    </Link>
                </div>
            ) : null}
        </section>
    );
}

export default function Paperwork({ entry, jurisdiction, people }: Props) {
    return (
        <AppLayout>
            <Head title="Paperwork" />
            {entry.state === 'ready' && entry.plan ? (
                // Another person's plan is another page: its views and search start fresh.
                <PaperworkProvider
                    key={entry.plan.person_id}
                    plan={entry.plan}
                    jurisdiction={jurisdiction}
                >
                    <Workspace people={people} />
                </PaperworkProvider>
            ) : (
                <div className="paperwork-page">
                    <h1 className="sr-only">Paperwork</h1>
                    <Unavailable state={entry.state} />
                </div>
            )}
        </AppLayout>
    );
}
