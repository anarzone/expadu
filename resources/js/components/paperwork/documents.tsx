import { commands } from './api';
import { readinessLabels } from './format';
import { Icon, TopicIcon } from './icons';
import { SourcesBody } from './lists';
import { usePaperwork } from './state';
import type { EvidenceItem, Process, Requirement } from './types';

function TermLine({ terms }: { terms: Requirement['terms'] }) {
    if (!terms.length) {
        return null;
    }

    return (
        <small className="document-term">
            {terms.map((t) => `${t.english} (${t.german})`).join(' · ')}
        </small>
    );
}

/** The task a document comes from: a catalogue link, never a prerequisite or a claim about your progress. */
function ProducedLine({ link }: { link: Requirement['produced_by'] }) {
    const { plan, go } = usePaperwork();

    if (!link) {
        return null;
    }

    const from = plan.processes.find(
        (p) => p.definition_id === link.process_id && !p.is_closed,
    );

    if (!from) {
        return null;
    }

    return (
        <p className="document-source">
            <Icon name="document" />
            <span>This comes from your {from.title} task.</span>
            <button
                type="button"
                className="plain-button"
                onClick={() =>
                    go({ view: 'detail', process: from.occurrence_key })
                }
            >
                Open {from.title}
            </button>
        </p>
    );
}

const sameLabel = (a: string, b: string) =>
    a.trim().toLowerCase() === b.trim().toLowerCase();

function DocumentRow({
    process,
    requirement,
}: {
    process: Process;
    requirement: Requirement;
}) {
    const { plan, run, toast, openSheet, canEdit } = usePaperwork();
    const evidence: EvidenceItem[] =
        'evidence' in plan.paperwork ? plan.paperwork.evidence : [];
    const conditional = requirement.applicability !== 'required';
    const confirmed = requirement.readiness === 'confirmed_for_use';
    const started = process.id !== null;

    // "I have this": one record per document name, so having it once shows everywhere it is asked for.
    const have = () => {
        const existing = evidence.find((e) =>
            sameLabel(e.details.label, requirement.label),
        );
        const refs = [
            ...new Set([
                ...(existing?.details.requirement_refs ?? []),
                requirement.id,
            ]),
        ];

        return run(
            () =>
                commands.recordEvidence(
                    plan.person_id,
                    existing?.id ?? crypto.randomUUID(),
                    existing?.version ?? 0,
                    {
                        label: existing?.details.label ?? requirement.label,
                        kind: existing?.details.kind ?? 'document',
                        reported_available: true,
                        requirement_refs: refs,
                    },
                ),
            'You have it. Mark it ready after checking it for this task.',
        ).then((refused) => refused && toast(refused));
    };

    const confirm = () => {
        const evidenceId =
            requirement.evidence_id ?? requirement.suggested_evidence_ids[0];
        const item = evidence.find((e) => e.id === evidenceId);

        if (!evidenceId || !item || process.id === null) {
            return;
        }

        return run(
            () =>
                commands.confirmRequirement(
                    process.id!,
                    requirement.id,
                    process.version,
                    evidenceId,
                    item.version,
                    requirement.semantic_hash,
                ),
            'Marked ready for this task. Nothing was sent.',
        ).then((refused) => refused && toast(refused));
    };

    const notReady = async () => {
        if (requirement.evidence_id && process.id !== null) {
            const refused = await run(() =>
                commands.withdrawRequirement(
                    process.id!,
                    requirement.id,
                    process.version,
                ),
            );

            if (refused) {
                return toast(refused);
            }
        }

        const holders = evidence.filter((e) =>
            e.details.requirement_refs?.includes(requirement.id),
        );

        for (const item of holders) {
            const refs = (item.details.requirement_refs ?? []).filter(
                (r) => r !== requirement.id,
            );
            const refused = await run(() =>
                commands.recordEvidence(
                    plan.person_id,
                    item.id,
                    item.version,
                    { ...item.details, requirement_refs: refs },
                    refs.length ? 'active' : 'archived',
                ),
            );

            if (refused) {
                return toast(refused);
            }
        }

        toast('Marked as not ready');
    };

    return (
        <details className="document-row">
            <summary>
                <span
                    className={`document-symbol ${confirmed ? 'confirmed' : ''}`}
                >
                    <Icon name={confirmed ? 'check' : 'document'} />
                </span>
                <span>
                    <strong>{requirement.label}</strong>
                    <TermLine terms={requirement.terms} />
                    <small>
                        {readinessLabels[requirement.readiness]}
                        {conditional ? ' · May be needed' : ''}
                    </small>
                </span>
                <Icon name="down" />
            </summary>
            <div className="document-actions">
                <p>
                    {conditional
                        ? 'Whether you need this depends on your situation. '
                        : ''}
                    You are updating your checklist for {process.title}. This is
                    not an official document check.
                </p>
                <ProducedLine link={requirement.produced_by} />
                {canEdit ? (
                    !started ? (
                        <p className="muted">
                            Start tracking {process.title} to mark documents
                            ready for it.
                        </p>
                    ) : (
                        <>
                            {requirement.readiness === 'missing' ? (
                                <button
                                    type="button"
                                    className="button secondary"
                                    onClick={have}
                                >
                                    <Icon name="document" />I have this
                                </button>
                            ) : [
                                  'reported_available',
                                  'needs_reconfirmation',
                              ].includes(requirement.readiness) &&
                              !conditional ? (
                                <button
                                    type="button"
                                    className="button primary"
                                    onClick={confirm}
                                >
                                    <Icon name="check" />
                                    Ready for this task
                                </button>
                            ) : confirmed ? (
                                <span className="document-confirmed">
                                    <Icon name="check" />
                                    Marked ready by you
                                </span>
                            ) : null}
                            {requirement.readiness !== 'missing' ? (
                                <button
                                    type="button"
                                    className="plain-button"
                                    onClick={notReady}
                                >
                                    Not ready yet
                                </button>
                            ) : null}
                        </>
                    )
                ) : null}
                <button
                    type="button"
                    className="document-info"
                    onClick={() =>
                        openSheet({
                            title: 'Source and coverage',
                            body: <SourcesBody process={process} />,
                        })
                    }
                >
                    <Icon name="info" />
                    Check official source
                </button>
            </div>
        </details>
    );
}

export function Documents() {
    const { plan, location, setLocation, requirements, go } = usePaperwork();

    if (!('requirements' in plan.paperwork)) {
        return (
            <div className="honest-empty">
                <h2 id="view-heading" tabIndex={-1}>
                    Documents aren’t shared with you
                </h2>
                <p>
                    Ask the person whose plan this is for access to their
                    document checklist.
                </p>
            </div>
        );
    }

    const open = plan.processes.filter((p) => !p.is_closed);
    const shown =
        location.paperFilter === 'all'
            ? open
            : open.filter((p) => p.occurrence_key === location.paperFilter);

    return (
        <>
            <div className="paperwork-heading">
                <div>
                    <h2 id="view-heading" tabIndex={-1}>
                        Your paperwork
                    </h2>
                    <p>Your document checklist. Nothing is uploaded or sent.</p>
                </div>
                <label className="paperwork-select">
                    <span className="sr-only">Task</span>
                    <select
                        value={location.paperFilter}
                        onChange={(e) =>
                            setLocation({ paperFilter: e.target.value })
                        }
                        aria-label="Filter paperwork by task"
                    >
                        <option value="all">All tasks</option>
                        {open.map((p) => (
                            <option
                                key={p.occurrence_key}
                                value={p.occurrence_key}
                            >
                                {p.title}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <div className="paperwork-groups">
                {shown.map((process) => {
                    const docs = requirements.filter(
                        (r) =>
                            r.occurrence_key === process.occurrence_key &&
                            r.applicability !== 'not_required',
                    );

                    return (
                        <section
                            className="paperwork-group"
                            key={process.occurrence_key}
                        >
                            <div className="case-section-head">
                                <button
                                    type="button"
                                    className="paper-process-link"
                                    onClick={() =>
                                        go({
                                            view: 'detail',
                                            process: process.occurrence_key,
                                        })
                                    }
                                >
                                    <TopicIcon topic={process.topic} />
                                    <h3>{process.title}</h3>
                                    <Icon name="arrow" />
                                </button>
                                <span>
                                    {
                                        docs.filter(
                                            (d) =>
                                                d.readiness ===
                                                'confirmed_for_use',
                                        ).length
                                    }
                                    /{docs.length}
                                </span>
                            </div>
                            {docs.length ? (
                                docs.map((r) => (
                                    <DocumentRow
                                        key={r.id}
                                        process={process}
                                        requirement={r}
                                    />
                                ))
                            ) : (
                                <p className="muted">
                                    This task lists no documents.
                                </p>
                            )}
                        </section>
                    );
                })}
            </div>
            <p className="date-separation">
                <Icon name="info" />
                “Ready” means you have checked it for this task. No files are
                uploaded or sent.
            </p>
        </>
    );
}
