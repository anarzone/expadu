import { Head, Link } from '@inertiajs/react';
import { TaskDetail } from '@/components/paperwork/detail';
import { Documents } from '@/components/paperwork/documents';
import { InlineEditor, SheetDialog } from '@/components/paperwork/editors';
import { Icon } from '@/components/paperwork/icons';
import { AllActions, History, SourcesBody } from '@/components/paperwork/lists';
import { Overview } from '@/components/paperwork/overview';
import { PaperworkProvider, usePaperwork } from '@/components/paperwork/state';
import type { PlanEntry } from '@/components/paperwork/types';
import AppLayout from '@/layouts/app-layout';
import '../../css/paperwork.css';

interface Props {
    entry: PlanEntry;
    jurisdiction: string;
}

function Workspace() {
    const { location, go, processByKey, openSheet } = usePaperwork();
    const process =
        location.view === 'detail' ? processByKey(location.process) : undefined;
    const heading = process?.title ?? 'Paperwork';
    const coverage = () =>
        openSheet({ title: 'Sources and coverage', body: <SourcesBody /> });

    return (
        <>
            <div className="case-top">
                <h1 tabIndex={-1}>{heading}</h1>
            </div>
            <div className="case-navigation">
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
                <span>
                    Guidance checked against official sources · your progress is
                    your own record
                </span>
                <button type="button" onClick={coverage}>
                    Sources &amp; coverage
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

export default function Paperwork({ entry, jurisdiction }: Props) {
    return (
        <AppLayout rightPanel={null}>
            <Head title="Paperwork" />
            <div className="paperwork-page">
                {entry.state === 'ready' && entry.plan ? (
                    <PaperworkProvider
                        plan={entry.plan}
                        jurisdiction={jurisdiction}
                    >
                        <Workspace />
                    </PaperworkProvider>
                ) : (
                    <>
                        <div className="case-top">
                            <h1>Paperwork</h1>
                        </div>
                        <Unavailable state={entry.state} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
