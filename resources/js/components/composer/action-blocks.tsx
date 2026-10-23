import { Link } from '@inertiajs/react';
import { useState } from 'react';

/*
 * What Composer did, or would do, in the person's app (the "Composer does
 * things" canvas, owner 23 Oct 2026: "build it"). Every line is the server's,
 * worded in code from what the change returned; nothing here decides or words
 * a change. A change made shows with Undo for 24 hours; with Ask first it waits
 * as a proposal with Apply. Paperwork's record shows its rows with Change, and
 * what a change moved in the plan. What Composer won't do itself (a permanent
 * change, a data right) opens the right place instead.
 */

export type ActionLink = { label: string; href: string };

export type DoneBlock = {
    type: 'done';
    id: string;
    /** "Saved Bau", "Your visa now runs until 12 Feb 2027". */
    text: string;
    sub?: string | null;
    /** Undo, until the time it lasts (24 hours). */
    undo?: { token: string; until: string } | null;
    link?: ActionLink | null;
};

export type UndoneBlock = { type: 'undone'; id: string; text: string };

export type ProposalBlock = {
    type: 'proposal';
    id: string;
    /** "Change when your visa runs out?" */
    title: string;
    from?: string | null;
    to?: string | null;
    sub?: string | null;
    token: string;
    area: 'everyday' | 'paperwork';
    /** The record row it changes, with its date, so "Change the date" edits it here. */
    fact_key?: string | null;
    edit?: { kind: 'date'; value: string } | null;
};

export type RecordRow = {
    key: string;
    label: string;
    /** As the record says it: "National D visa", "1 Oct 2026". */
    value: string;
    note?: string | null;
    /** A field the person can change here, in place. */
    edit?: { kind: 'date'; value: string } | null;
};

export type RecordBlock = { type: 'record'; rows: RecordRow[] };

/** One row of PaperworkPlanDiff::between. */
export type PlanChange = {
    process_id: number | null;
    occurrence_key: string;
    title: string | null;
    change:
        | 'added'
        | 'removed'
        | 'due_moved'
        | 'step_added'
        | 'step_removed'
        | 'state_changed';
    step?: { id: string; title: string | null };
    from?: string | null;
    to?: string | null;
};

export type PlanChangesBlock = {
    type: 'plan_changes';
    changes: PlanChange[];
    link?: ActionLink | null;
};

export type HandoffBlock = {
    type: 'handoff';
    text: string;
    place: {
        crumb: string;
        description: string;
        actions: string[];
        /** The action the person asked for, ringed. */
        here: string;
        label: string;
        href: string;
    };
    note?: string | null;
};

export type ActionBlock =
    | DoneBlock
    | UndoneBlock
    | ProposalBlock
    | RecordBlock
    | PlanChangesBlock
    | HandoffBlock;

export const ACTION_TYPES: ActionBlock['type'][] = [
    'done',
    'undone',
    'proposal',
    'record',
    'plan_changes',
    'handoff',
];

/** What the blocks ask of the page: one call, the workspace it returns shows the result. */
export type ActionCalls = {
    undo: (token: string) => void;
    apply: (token: string) => void;
    /** A row changed in place: the server makes it (or proposes it, with Ask first). */
    change: (key: string, value: string) => void;
    /** Words for Composer, as if typed ("Change my residence title"). */
    ask: (prompt: string) => void;
    /** Back to the box (a proposal's "Change something"). */
    focusBox: () => void;
    busy: boolean;
};

const DAY = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'Europe/Berlin',
});

/** "12 Feb 2027" for a plan date (YYYY-MM-DD or ISO); as given otherwise. */
function day(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const date = new Date(
        /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T12:00:00` : value,
    );

    return Number.isNaN(date.getTime()) ? value : DAY.format(date);
}

export function DoneLine({
    block,
    calls,
}: {
    block: DoneBlock;
    calls: ActionCalls;
}) {
    // Undo lasts 24 hours; the server says until when, and checks it again.
    const [now] = useState(() => Date.now());
    const undo =
        block.undo && Date.parse(block.undo.until) > now ? block.undo : null;

    return (
        <>
            <div className="action-done" role="status">
                <span className="action-done-tick" aria-hidden="true">
                    ✓
                </span>
                <span className="action-done-copy">
                    <strong>{block.text}</strong>
                    {block.sub && <span>{block.sub}</span>}
                </span>
                {undo && (
                    <button
                        type="button"
                        className="action-undo"
                        disabled={calls.busy}
                        onClick={() => calls.undo(undo.token)}
                    >
                        Undo
                    </button>
                )}
            </div>
            {block.link && (
                <Link className="action-link" href={block.link.href}>
                    {block.link.label}
                </Link>
            )}
        </>
    );
}

export function UndoneLine({ block }: { block: UndoneBlock }) {
    return (
        <div className="action-undone" role="status">
            <span aria-hidden="true">↺</span>
            {block.text}
        </div>
    );
}

export function Proposal({
    block,
    calls,
}: {
    block: ProposalBlock;
    calls: ActionCalls;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(block.edit?.value ?? '');
    const key = block.fact_key;
    const edit = block.edit;

    return (
        <div className="action-proposal">
            <strong>{block.title}</strong>
            {(block.from || block.to) && (
                <span className="action-proposal-change">
                    {block.from && <s>{block.from}</s>}
                    {block.from && block.to && ' → '}
                    {block.to && <strong>{block.to}</strong>}
                </span>
            )}
            {block.sub && <span className="action-sub">{block.sub}</span>}
            {editing && key && edit ? (
                <form
                    className="action-record-edit"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setEditing(false);

                        if (draft && draft !== edit.value) {
                            calls.change(key, draft);
                        }
                    }}
                >
                    <label>
                        <span className="sr-only">The date</span>
                        <input
                            type="date"
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            autoFocus
                        />
                    </label>
                    <button
                        type="submit"
                        className="action-save"
                        disabled={calls.busy}
                    >
                        Save
                    </button>
                </form>
            ) : (
                <span className="action-proposal-buttons">
                    <button
                        type="button"
                        className="action-apply"
                        disabled={calls.busy}
                        onClick={() => calls.apply(block.token)}
                    >
                        Apply
                    </button>
                    {key && edit ? (
                        <button
                            type="button"
                            className="action-ghost"
                            onClick={() => setEditing(true)}
                        >
                            Change the date
                        </button>
                    ) : (
                        <button
                            type="button"
                            className="action-ghost"
                            onClick={calls.focusBox}
                        >
                            Change something
                        </button>
                    )}
                </span>
            )}
        </div>
    );
}

/** What the person's Paperwork record says now, each row changeable. */
export function RecordRows({
    block,
    calls,
}: {
    block: RecordBlock;
    calls: ActionCalls;
}) {
    // The row being changed in place, if any.
    const [editing, onEdit] = useState<string | null>(null);
    const [draft, setDraft] = useState('');

    return (
        <div className="action-record" aria-label="Your Paperwork record">
            {block.rows.map((row) => (
                <div key={row.key} className="action-record-row">
                    <span>{row.label}</span>
                    {editing === row.key && row.edit ? (
                        <form
                            className="action-record-edit"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const value = draft || row.edit!.value;

                                if (value !== row.edit!.value) {
                                    calls.change(row.key, value);
                                }

                                onEdit(null);
                            }}
                        >
                            <label>
                                <span className="sr-only">{row.label}</span>
                                <input
                                    type="date"
                                    defaultValue={row.edit.value}
                                    onChange={(event) =>
                                        setDraft(event.target.value)
                                    }
                                    autoFocus
                                />
                            </label>
                            <button
                                type="submit"
                                className="action-save"
                                disabled={calls.busy}
                            >
                                Save
                            </button>
                        </form>
                    ) : (
                        <>
                            <strong>
                                {row.value}
                                {row.note && <small> · {row.note}</small>}
                            </strong>
                            <button
                                type="button"
                                className="action-change"
                                aria-label={`Change ${row.label.toLowerCase()}`}
                                disabled={calls.busy}
                                onClick={() => {
                                    if (row.edit) {
                                        setDraft('');
                                        onEdit(row.key);
                                    } else {
                                        calls.ask(
                                            `Change my ${row.label.toLowerCase()}`,
                                        );
                                    }
                                }}
                            >
                                Change
                            </button>
                        </>
                    )}
                </div>
            ))}
        </div>
    );
}

const STEP: Record<PlanChange['change'], string> = {
    added: 'Now in your plan',
    removed: 'No longer in your plan',
    due_moved: 'Moved',
    step_added: 'New step',
    step_removed: 'No longer needed',
    state_changed: 'Changed',
};

/** What a change moved in the plan, by task, in the plan's own titles and dates. */
export function PlanChanges({ block }: { block: PlanChangesBlock }) {
    const tasks = [
        ...new Map(
            block.changes.map((change) => [
                change.occurrence_key,
                change.title,
            ]),
        ),
    ];

    if (block.changes.length === 0) {
        return null;
    }

    return (
        <section className="action-plan" aria-label="What changed in your plan">
            <h3>What changed in your plan</h3>
            {tasks.map(([key, title]) => (
                <div key={key} className="action-plan-task">
                    {title && <strong>{title}</strong>}
                    {block.changes
                        .filter((change) => change.occurrence_key === key)
                        .map((change, index) => (
                            <div
                                key={`${change.change}-${change.step?.id ?? index}`}
                                className="action-plan-step"
                            >
                                <span>
                                    {change.step?.title ?? STEP[change.change]}
                                    {change.step && (
                                        <small> · {STEP[change.change]}</small>
                                    )}
                                </span>
                                {change.change === 'due_moved' && (
                                    <span className="action-plan-when">
                                        by {day(change.to)}
                                        <s>{day(change.from)}</s>
                                    </span>
                                )}
                            </div>
                        ))}
                </div>
            ))}
            {block.link && (
                <Link className="action-link" href={block.link.href}>
                    {block.link.label}
                </Link>
            )}
        </section>
    );
}

/** What Composer won't do itself: where it is, with the asked action ringed. */
export function Handoff({ block }: { block: HandoffBlock }) {
    const { place } = block;

    return (
        <>
            <p>{block.text}</p>
            <div className="action-handoff">
                <span className="action-handoff-crumb">{place.crumb}</span>
                <span>{place.description}</span>
                <span className="action-handoff-actions" aria-hidden="true">
                    {place.actions.map((action) => (
                        <span
                            key={action}
                            className={
                                action === place.here ? 'is-here' : undefined
                            }
                        >
                            {action}
                        </span>
                    ))}
                </span>
                <Link className="action-go" href={place.href}>
                    {place.label}
                </Link>
            </div>
            {block.note && <p className="action-sub">{block.note}</p>}
        </>
    );
}
