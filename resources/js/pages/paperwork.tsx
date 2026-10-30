import { Head, Link, router } from '@inertiajs/react';
import { clsx } from 'clsx';
import { useEffect, useRef, useState } from 'react';
import { TaskDetail, TaskTitleStatus } from '@/components/paperwork/detail';
import { Documents } from '@/components/paperwork/documents';
import { InlineEditor, SheetDialog } from '@/components/paperwork/editors';
import { Icon } from '@/components/paperwork/icons';
import {
    AllActions,
    BackToOverview,
    History,
    SourcesBody,
} from '@/components/paperwork/lists';
import { Overview } from '@/components/paperwork/overview';
import {
    ErasedRecord,
    RecordNotice,
    RecordNoticeToast,
    WorkspaceNotice,
    Sharing,
    StoppedRecord,
} from '@/components/paperwork/sharing';
import type { RecordRing } from '@/components/paperwork/sharing';
import {
    StartRecord,
    StarterWelcome,
    useFreshStart,
} from '@/components/paperwork/starter';
import {
    PaperworkProvider,
    searchable,
    usePaperwork,
} from '@/components/paperwork/state';
import type { Person, PlanEntry } from '@/components/paperwork/types';
import AppLayout from '@/layouts/app-layout';
import { composer, paperwork } from '@/routes';
import { waitlist as joinWaitlist } from '@/routes/paperwork';
import '../../css/paperwork.css';

type CoverageWaitlist = 'unconfirmed' | 'confirmed' | null;

interface Props {
    entry: PlanEntry;
    jurisdiction: string;
    people: Person[];
    coverageWaitlist: CoverageWaitlist;
    /** The cities Paperwork covers, for "Get started". */
    coveredCities?: string[];
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

/** Whose plan: your own, or someone who shared theirs with you; and sharing your own. */
function PersonPicker({ people }: { people: Person[] }) {
    const { plan, openEditor } = usePaperwork();
    const menu = useRef<HTMLDetailsElement>(null);
    const current = people.find((p) => p.id === plan.person_id);
    const name = (p: Person | undefined) => (!p || p.is_self ? 'You' : p.label);

    return (
        <details className="person-picker" ref={menu}>
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
                                              ? paperwork.url()
                                              : paperwork.url({
                                                    query: { person: p.id },
                                                }),
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
                {current?.is_self ? (
                    <button
                        type="button"
                        onClick={() => {
                            menu.current?.removeAttribute('open');
                            openEditor({
                                title: 'Share your plan',
                                body: <Sharing personId={plan.person_id} />,
                            });
                        }}
                    >
                        <Icon name="people" />
                        <span>
                            Share your plan
                            <small>Choose who sees what</small>
                        </span>
                    </button>
                ) : null}
            </div>
        </details>
    );
}

function Workspace({
    people,
    coverageWaitlist,
}: {
    people: Person[];
    coverageWaitlist: CoverageWaitlist;
}) {
    const {
        plan,
        location,
        go,
        processByKey,
        openSheet,
        openSituation,
        openEditor,
    } = usePaperwork();
    const self = people.find((p) => p.id === plan.person_id)?.is_self ?? false;

    // Handed over from Composer (/paperwork?record=delete): your record's
    // actions open with the one asked for ringed. Only on your own plan; the
    // address forgets it once it's open.
    useEffect(() => {
        const url = new URL(window.location.href);
        const ring = url.searchParams.get('record');

        if (!ring || !['download', 'stop', 'delete'].includes(ring)) {
            return;
        }

        url.searchParams.delete('record');
        window.history.replaceState(window.history.state, '', url);

        if (self) {
            openEditor({
                title: 'Share your plan',
                body: (
                    <Sharing
                        personId={plan.person_id}
                        ring={ring as RecordRing}
                    />
                ),
            });
        }
        // Read once, as the page opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    // No answers yet on your own plan: the starter path's welcome leads (PW7).
    const fresh = useFreshStart(self);
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

    return (
        <>
            {/* A task's page leads with the way back, then its name with its status. */}
            {titled ? (
                <div className="case-back">
                    <BackToOverview />
                </div>
            ) : null}
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
                {process ? (
                    <TaskTitleStatus processKey={process.occurrence_key} />
                ) : null}
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
                {plan.coverage.state === 'outside_coverage' ? (
                    <OutsideCoverage waitlist={coverageWaitlist} />
                ) : location.view === 'paperwork' ? (
                    <Documents />
                ) : location.view === 'actions' ? (
                    <AllActions />
                ) : location.view === 'history' ? (
                    <History />
                ) : location.view === 'detail' ? (
                    <TaskDetail />
                ) : (
                    <>
                        {fresh ? <StarterWelcome /> : null}
                        <Overview onCoverage={coverage} />
                    </>
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

/**
 * No reviewed guidance for the person's area (EXP-80): honest, with a way on.
 * "Tell me when it's covered" puts the account on the city waitlist, which
 * the person confirms by mail (App\Waitlist\CoverageWaitlist); the page then
 * remembers where they stand.
 */
function OutsideCoverage({ waitlist }: { waitlist: CoverageWaitlist }) {
    const [joining, setJoining] = useState(false);

    return (
        <section className="access-blank">
            <h2>No reviewed guidance for your area yet.</h2>
            <p>
                Paperwork covers Cologne so far, and doesn’t guess for other
                cities. Your answers stay.
            </p>
            <div className="actions">
                {waitlist ? (
                    <p className="coverage-notify" role="status">
                        <Icon name="check" />
                        <span>
                            {waitlist === 'confirmed'
                                ? 'We’ll email you when your area is covered.'
                                : 'Check your inbox to confirm. Then we’ll email you when your area is covered.'}
                        </span>
                    </p>
                ) : (
                    <button
                        type="button"
                        className="button primary"
                        disabled={joining}
                        onClick={() =>
                            router.post(
                                joinWaitlist.url(),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setJoining(true),
                                    onFinish: () => setJoining(false),
                                },
                            )
                        }
                    >
                        Tell me when it’s covered
                    </button>
                )}
            </div>
            <Link className="coverage-composer" href={composer()}>
                <Icon name="spark" />
                <span>Ask Composer about your move</span>
                <Icon name="arrow" />
            </Link>
        </section>
    );
}

function Unavailable() {
    return (
        <section className="access-blank">
            <h2>Your paperwork record isn’t available right now.</h2>
            <p>
                Your record is paused or being removed. Contact us if you didn’t
                expect this.
            </p>
        </section>
    );
}

export default function Paperwork({
    entry,
    jurisdiction,
    people,
    coverageWaitlist,
    coveredCities = [],
}: Props) {
    const self = people.find((p) => p.is_self);
    const [notice, setNotice] = useState<string | null>(null);

    return (
        <AppLayout>
            <Head title="Paperwork" />
            <RecordNotice.Provider value={setNotice}>
                {entry.state === 'ready' && entry.plan ? (
                    // Another person's plan is another page: its views and search start fresh.
                    <PaperworkProvider
                        key={entry.plan.person_id}
                        plan={entry.plan}
                        jurisdiction={jurisdiction}
                    >
                        <Workspace
                            people={people}
                            coverageWaitlist={coverageWaitlist}
                        />
                        <WorkspaceNotice notice={notice} clear={setNotice} />
                    </PaperworkProvider>
                ) : (
                    <div className="paperwork-page">
                        <h1 className="sr-only" tabIndex={-1}>
                            Paperwork
                        </h1>
                        {entry.state === 'processing_stopped' && self ? (
                            <StoppedRecord personId={self.id} />
                        ) : entry.state === 'erased' ? (
                            <ErasedRecord />
                        ) : entry.state === 'setup_required' ? (
                            <StartRecord cities={coveredCities} />
                        ) : (
                            <Unavailable />
                        )}
                        <RecordNoticeToast notice={notice} clear={setNotice} />
                    </div>
                )}
            </RecordNotice.Provider>
        </AppLayout>
    );
}
