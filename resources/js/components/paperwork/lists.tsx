import { coverageLabels, formatDate } from './format';
import { Icon, TopicIcon } from './icons';
import { ActionRow, statusFilters } from './overview';
import { usePaperwork } from './state';
import type { CoverageUnit, Process } from './types';

export function BackToOverview() {
    const { go } = usePaperwork();

    return (
        <button
            type="button"
            className="view-back"
            onClick={() => go({ view: 'overview' })}
        >
            <Icon name="back" />
            Your overview
        </button>
    );
}

export function AllActions() {
    const { plan, location, setLocation } = usePaperwork();
    const steps = plan.processes
        .filter((p) => !p.is_closed)
        .flatMap((process) => process.steps.map((step) => ({ process, step })))
        .filter(({ step }) => step.status !== 'cancelled');
    const list = steps.filter(
        ({ step }) =>
            location.filter === 'all' || step.status === location.filter,
    );
    const options: [typeof location.filter, string, number][] = [
        ['all', 'All', plan.progress.total],
        ...statusFilters.map(
            ([id, label]) =>
                [id, label, plan.progress[id].count] as [
                    typeof location.filter,
                    string,
                    number,
                ],
        ),
    ];

    return (
        <>
            <BackToOverview />
            <div className="section-line">
                <h2 id="view-heading" tabIndex={-1}>
                    All actions
                </h2>
                <span className="muted">
                    {list.length} {list.length === 1 ? 'action' : 'actions'}
                </span>
            </div>
            <div className="action-filters" aria-label="Filter actions">
                {options.map(([id, label, count]) => (
                    <button
                        type="button"
                        key={id}
                        aria-pressed={location.filter === id}
                        onClick={() => setLocation({ filter: id })}
                    >
                        {label} <span>{count}</span>
                    </button>
                ))}
            </div>
            <section className="action-list">
                {list.length ? (
                    list.map(({ process, step }) => (
                        <ActionRow
                            key={step.id}
                            process={process}
                            step={step}
                        />
                    ))
                ) : (
                    <div className="honest-empty">
                        <h3>No actions in this view</h3>
                        <button
                            type="button"
                            onClick={() => setLocation({ filter: 'all' })}
                        >
                            Show all actions <Icon name="arrow" />
                        </button>
                    </div>
                )}
            </section>
        </>
    );
}

/** Retained history and closed tasks. Records of your own reports, never an authority decision. */
export function History() {
    const { plan, go } = usePaperwork();
    const rows: Process[] = [
        ...plan.history,
        ...plan.processes.filter((p) => p.is_closed),
    ];

    return (
        <>
            <BackToOverview />
            <div className="section-line">
                <h2 id="view-heading" tabIndex={-1}>
                    Your history
                </h2>
                <span className="muted">{plan.history_count} records</span>
            </div>
            <section className="history-list">
                {rows.length ? (
                    rows.map((p) => (
                        <button
                            type="button"
                            key={p.occurrence_key}
                            className="history-row"
                            onClick={() =>
                                p.id !== null
                                    ? go({
                                          view: 'detail',
                                          process: p.occurrence_key,
                                      })
                                    : undefined
                            }
                        >
                            <TopicIcon topic={p.topic} tone="green" />
                            <span>
                                <strong>{p.title ?? 'A retired task'}</strong>
                                <small>
                                    {p.state.workflow === 'cancelled'
                                        ? 'Cancellation recorded'
                                        : 'Completion recorded'}{' '}
                                    ·{' '}
                                    {p.closed_on
                                        ? formatDate(p.closed_on)
                                        : 'date not given'}
                                </small>
                            </span>
                            <Icon name="arrow" />
                        </button>
                    ))
                ) : (
                    <div className="honest-empty">
                        No historical records yet.
                    </div>
                )}
            </section>
            <p className="date-separation">
                Records of your reports. These do not establish an authority
                decision.
            </p>
        </>
    );
}

function SourceRecord({ unit }: { unit: CoverageUnit }) {
    // An unconfirmed card shows nothing of its own guidance: only its title and the official pages.
    if (unit.state === 'unconfirmed') {
        return (
            <section className="source-record is-unconfirmed">
                <h3>{unit.title ?? 'Guidance'}</h3>
                <p>
                    We couldn’t confirm this right now. Check the official page.
                </p>
                <div className="source-links">
                    {unit.source_urls.legal.map((url) => (
                        <a
                            key={url}
                            href={url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Official page ↗
                        </a>
                    ))}
                </div>
            </section>
        );
    }

    return (
        <section className="source-record">
            <h3>{unit.title ?? 'Withdrawn guidance'}</h3>
            <small>
                {coverageLabels[unit.state]}
                {unit.verified_at
                    ? ` · checked against official sources ${formatDate(unit.verified_at)}`
                    : ''}
            </small>
            <div className="source-links">
                {unit.source_urls.official.map((url) => (
                    <a
                        key={`o-${url}`}
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Official source ↗
                    </a>
                ))}
                {unit.source_urls.legal.map((url) => (
                    <a
                        key={`l-${url}`}
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Legal source ↗
                    </a>
                ))}
            </div>
        </section>
    );
}

/** What each reviewed unit covers. None of these states means you are eligible or every case is covered. */
export function SourcesBody({ process }: { process?: Process }) {
    const { plan } = usePaperwork();
    // A task's own units (and any of its process withdrawn or unconfirmed); otherwise the plan's
    // processes first, with the rest of the catalogue tucked away below.
    const yours = new Set(
        process
            ? [process.definition_id]
            : [...plan.processes, ...plan.history].map((p) => p.definition_id),
    );
    const ids = process ? process.steps.map((s) => s.guidance_id) : null;
    const relevant = plan.coverage.units.filter((u) =>
        ids
            ? ids.includes(u.unit_id) ||
              (u.definition_id !== null &&
                  yours.has(u.definition_id) &&
                  u.state !== 'partial' &&
                  u.state !== 'complete')
            : u.definition_id !== null && yours.has(u.definition_id),
    );
    const others = process
        ? []
        : plan.coverage.units.filter((u) => !relevant.includes(u));

    return (
        <>
            <p>
                Each card is checked against the official pages it quotes.
                Whether a rule applies to you, and any legal date, comes from
                the authority.
            </p>
            {relevant.length ? (
                relevant.map((unit) => (
                    <SourceRecord key={unit.unit_id} unit={unit} />
                ))
            ) : (
                <p className="muted">No guidance is published for this yet.</p>
            )}
            {others.length ? (
                <details className="step-guidance">
                    <summary className="sources-also">
                        Also in the catalogue ({others.length}){' '}
                        <Icon name="down" />
                    </summary>
                    {others.map((unit) => (
                        <SourceRecord key={unit.unit_id} unit={unit} />
                    ))}
                </details>
            ) : null}
            <p className="date-separation">
                Missing coverage is not a completed plan.
            </p>
        </>
    );
}
