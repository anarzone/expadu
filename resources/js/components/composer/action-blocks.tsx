import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { choices } from '@/components/paperwork/facts';
import { AiBadge } from '@/components/ui/ai-badge';
import { Icon } from '@/components/ui/icon';

/*
 * What Composer did, or would do, in the person's app (the "Composer does
 * things" canvas, owner 23 Oct 2026: "build it"). Every line is the server's,
 * worded in code from what the change returned; nothing here decides or words
 * a change. A change made shows with Undo for 24 hours; with Ask first it waits
 * as a proposal with Apply. Paperwork's record shows its rows with Change, and
 * what a change moved in the plan. What Composer won't do itself (a permanent
 * change, a data right) opens the right place instead.
 */

/** Where a change can be seen: a page, or one of Composer's own panels. */
export type ActionLink = {
    label: string;
    href?: string | null;
    nav?: 'saved' | 'plans' | null;
};

type Area = 'everyday' | 'paperwork';

export type DoneBlock = {
    type: 'done';
    id: string;
    area: Area;
    action?: string;
    /** "Saved Bau", "Your visa now runs until 12 Feb 2027". */
    text: string;
    sub?: string | null;
    /** Undo, until the time it lasts (24 hours). */
    undo?: { token: string; until: string } | null;
    link?: ActionLink | null;
    /** My day as it is now, the new item marked. */
    day?: { time: string; what: string; new: boolean }[] | null;
    /** What Paperwork would like to know next ("When did you move in?"). */
    ask?: {
        text: string;
        options: { label: string; prompt?: string; draft?: string }[];
    } | null;
};

export type UndoneBlock = {
    type: 'undone';
    id: string;
    area?: Area;
    action?: string;
    text: string;
};

export type ProposalBlock = {
    type: 'proposal';
    id: string;
    /** "Change when your visa runs out?" */
    title: string;
    from?: string | null;
    to?: string | null;
    sub?: string | null;
    /** What Apply makes; none for a question answered by its choices (they save themselves). */
    token?: string | null;
    area: Area;
    action?: string;
    /** Composer's own words for it. */
    text?: string;
    /** Until when it can be applied (24 hours). */
    until?: string | null;
    /** The record row it changes, so its date or choices change it here. */
    fact_key?: string | null;
    edit?: FactField | null;
    /** "Not sure": plain lines that help, and the one answer to keep for now. */
    unsure?: {
        label: string;
        lines: string[];
        keep: { value: string; label: string } | null;
    } | null;
};

export type RecordRow = {
    key: string;
    label: string;
    /** As the record says it: "National D visa", "1 Oct 2026". */
    value: string;
    note?: string | null;
    /**
     * How the person changes it here, in place; no value when it isn't answered yet.
     * A choice comes as the answer's own options, worded here as Paperwork words them.
     */
    edit?:
        | { kind: 'date'; value: string | null }
        | {
              kind: 'choice';
              type: 'enum' | 'boolean';
              value: string | boolean | null;
              options: string[];
          }
        | null;
};

/** One choice: the answer it saves, how it reads, and an optional plain line under it. */
export type FactChoice = {
    value: string | boolean;
    label: string;
    hint?: string | null;
};

/** A Paperwork answer's field, shared by record rows and proposals: a date, or its choices. */
export type FactField =
    | { kind: 'date'; value: string | null }
    | { kind: 'choice'; value: string | boolean | null; options: FactChoice[] };

export type RecordBlock = {
    type: 'record';
    title?: string | null;
    rows: RecordRow[];
    link?: ActionLink | null;
};

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
    id?: string;
    changes: PlanChange[];
    link?: ActionLink | null;
};

export type HandoffBlock = {
    type: 'handoff';
    what?: string;
    text: string;
    place: {
        crumb: string;
        description?: string | null;
        actions: string[];
        /** The action the person asked for, ringed. */
        here: string;
        label: string;
        href?: string | null;
        /** The AI notice under the box, which holds Withdraw. */
        nav?: 'ai_notice' | null;
        /** Whose Withdraw it opens: Composer's AI, or its Paperwork permission. */
        purpose?: 'composer_assist' | 'composer_paperwork' | null;
    };
    note?: string | null;
};

/** What an AI permission says before it's given (ProcessingPurpose::disclosure). */
export type Disclosure = {
    purpose: string;
    notice_version: string;
    provider_version: string;
    processor_name: string | null;
    processor_privacy_url: string | null;
    /** The notice in words, sentence by sentence, drawn as given. */
    notice?: string[] | null;
};

/** Paperwork needs their say-so before Composer reads or changes it, for this one message. */
export type PermissionBlock = {
    type: 'permission';
    purpose: 'composer_paperwork' | 'paperwork_answer';
    title: string;
    sub?: string | null;
    disclosure: Disclosure;
};

export type ActionBlock =
    | DoneBlock
    | UndoneBlock
    | ProposalBlock
    | RecordBlock
    | PlanChangesBlock
    | HandoffBlock
    | PermissionBlock;

export const ACTION_TYPES: ActionBlock['type'][] = [
    'done',
    'undone',
    'proposal',
    'record',
    'plan_changes',
    'handoff',
    'permission',
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
    /** Words in the box for them to finish ("I moved in on "). */
    draft: (text: string) => void;
    /** One of Composer's own panels, or the AI notice under the box (with whose Withdraw). */
    nav: (
        where: 'saved' | 'plans' | 'ai_notice',
        purpose?: 'composer_assist' | 'composer_paperwork',
    ) => void;
    /** Their say-so for Paperwork, for this message: it's asked again with it. */
    allow: (disclosure: Disclosure) => void;
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

/** Where a change can be seen: a page, or one of Composer's own panels. */
function SeeIt({ link, calls }: { link: ActionLink; calls: ActionCalls }) {
    if (link.nav) {
        const nav = link.nav;

        return (
            <button
                type="button"
                className="action-link"
                onClick={() => calls.nav(nav)}
            >
                {link.label}
            </button>
        );
    }

    return link.href ? (
        <Link className="action-link" href={link.href}>
            {link.label}
        </Link>
    ) : null;
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
                    <strong>
                        {block.text} <AiBadge label="Done by AI" />
                    </strong>
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
            {!!block.day?.length && (
                <div className="action-day" aria-label="My day">
                    {block.day.map((item) => (
                        <div
                            key={`${item.time}-${item.what}`}
                            className={item.new ? 'is-new' : undefined}
                        >
                            <span>{item.time}</span>
                            <span>{item.what}</span>
                        </div>
                    ))}
                </div>
            )}
            {block.ask && (
                <div className="action-ask">
                    <span>{block.ask.text}</span>
                    {block.ask.options.map((option) => (
                        <button
                            type="button"
                            key={option.label}
                            disabled={calls.busy}
                            onClick={() =>
                                option.prompt
                                    ? calls.ask(option.prompt)
                                    : calls.draft(option.draft ?? '')
                            }
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            )}
            {block.link && <SeeIt link={block.link} calls={calls} />}
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
    const [unsure, setUnsure] = useState(false);
    // It can be applied for 24 hours; the server says until when, and checks it again.
    const [now] = useState(() => Date.now());
    const expired = !!block.until && Date.parse(block.until) <= now;
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
            {expired ? (
                <span className="action-sub">
                    This can no longer be applied. Ask again if you still want
                    it.
                </span>
            ) : key && edit?.kind === 'choice' ? (
                // A question its choices answer, open on the card: a tap saves it.
                <>
                    <FactEdit
                        label={block.title}
                        field={edit}
                        busy={calls.busy}
                        onSave={(value) => calls.change(key, value)}
                    />
                    {block.unsure && (
                        <div className="action-unsure">
                            <button
                                type="button"
                                className="action-ghost"
                                aria-expanded={unsure}
                                onClick={() => setUnsure((open) => !open)}
                            >
                                {block.unsure.label}
                            </button>
                            {unsure && (
                                <>
                                    {block.unsure.lines.map((line) => (
                                        <p key={line} className="action-sub">
                                            {line}
                                        </p>
                                    ))}
                                    {block.unsure.keep && (
                                        <button
                                            type="button"
                                            className="action-choice"
                                            disabled={calls.busy}
                                            onClick={() =>
                                                calls.change(
                                                    key,
                                                    block.unsure!.keep!.value,
                                                )
                                            }
                                        >
                                            {block.unsure.keep.label}
                                        </button>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </>
            ) : editing && key && edit ? (
                <FactEdit
                    label="The date"
                    field={edit}
                    busy={calls.busy}
                    onSave={(value) => calls.change(key, value)}
                    onClose={() => setEditing(false)}
                />
            ) : (
                <span className="action-proposal-buttons">
                    {block.token && (
                        <button
                            type="button"
                            className="action-apply"
                            disabled={calls.busy}
                            onClick={() => calls.apply(block.token!)}
                        >
                            Apply
                        </button>
                    )}
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

/**
 * One Paperwork answer changed in place (owner, 27 Oct 2026: "update my info by prompt
 * or by UI inside Composer"): a date field with Save, or the answer's choices as
 * Paperwork's Your situation offers them, where tapping another one saves it. Saving
 * goes as every change on a card does: made with Undo, or proposed under Ask first.
 * Opened from a Change (`onClose`), Esc or Cancel leaves it as it was; a proposal's
 * choices stay open.
 */
export function FactEdit({
    label,
    field,
    busy,
    onSave,
    onClose,
}: {
    label: string;
    field: FactField;
    busy: boolean;
    onSave: (value: string) => void;
    onClose?: () => void;
}) {
    const [draft, setDraft] = useState(
        field.kind === 'date' ? (field.value ?? '') : '',
    );
    const group = useRef<HTMLDivElement>(null);
    const escape = (event: KeyboardEvent) => {
        if (event.key === 'Escape' && onClose) {
            event.preventDefault();
            event.stopPropagation();
            onClose();
        }
    };

    // Opened from a Change, the choice it has now (else the first) takes focus.
    useEffect(() => {
        if (!onClose) {
            return;
        }

        (
            group.current?.querySelector<HTMLButtonElement>(
                '[aria-pressed="true"]',
            ) ?? group.current?.querySelector<HTMLButtonElement>('button')
        )?.focus({ preventScroll: true });
        // Only when it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const cancel = onClose && (
        <button
            type="button"
            className="action-record-cancel"
            aria-label={`Keep ${label.toLowerCase()} as it is`}
            onClick={onClose}
        >
            <Icon name="close" />
        </button>
    );

    if (field.kind === 'date') {
        return (
            <form
                className="action-record-edit"
                onKeyDown={escape}
                onSubmit={(event) => {
                    event.preventDefault();

                    if (draft && draft !== field.value) {
                        onSave(draft);
                    }

                    onClose?.();
                }}
            >
                <label>
                    <span className="sr-only">{label}</span>
                    <input
                        type="date"
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        autoFocus={!!onClose}
                    />
                </label>
                <button
                    type="submit"
                    className="action-save"
                    disabled={busy || !draft}
                >
                    Save
                </button>
                {cancel}
            </form>
        );
    }

    return (
        <div
            ref={group}
            className="action-record-edit action-record-choices"
            role="group"
            aria-label={label}
            onKeyDown={escape}
        >
            {field.options.map((choice) => (
                <button
                    key={String(choice.value)}
                    type="button"
                    className="action-choice"
                    aria-pressed={choice.value === field.value}
                    disabled={busy}
                    onClick={() => {
                        if (choice.value !== field.value) {
                            onSave(String(choice.value));
                        }

                        onClose?.();
                    }}
                >
                    {choice.label}
                    {choice.hint && <small>{choice.hint}</small>}
                </button>
            ))}
            {cancel}
        </div>
    );
}

/** What the person's Paperwork record says now, each row changeable in place. */
export function RecordRows({
    block,
    calls,
}: {
    block: RecordBlock;
    calls: ActionCalls;
}) {
    // The row being changed in place, if any; closed, focus goes back to its Change.
    const [editing, onEdit] = useState<string | null>(null);
    const card = useRef<HTMLDivElement>(null);
    const close = (key: string) => {
        onEdit(null);
        requestAnimationFrame(() =>
            card.current
                ?.querySelector<HTMLButtonElement>(`[data-change="${key}"]`)
                ?.focus({ preventScroll: true }),
        );
    };

    return (
        <>
            <div
                ref={card}
                className="action-record"
                aria-label={block.title ?? 'Your Paperwork record'}
            >
                {block.title && <h3>{block.title}</h3>}
                {block.rows.map((row) => (
                    <div
                        key={row.key}
                        className={
                            editing === row.key
                                ? 'action-record-row is-editing'
                                : 'action-record-row'
                        }
                    >
                        <span>{row.label}</span>
                        {editing === row.key && row.edit ? (
                            <FactEdit
                                label={row.label}
                                field={
                                    row.edit.kind === 'choice'
                                        ? {
                                              kind: 'choice',
                                              value: row.edit.value,
                                              options: choices(
                                                  row.key,
                                                  row.edit.type,
                                                  row.edit.options,
                                              ) as FactChoice[],
                                          }
                                        : row.edit
                                }
                                busy={calls.busy}
                                onSave={(value) => calls.change(row.key, value)}
                                onClose={() => close(row.key)}
                            />
                        ) : (
                            <>
                                <strong>
                                    {row.value}
                                    {row.note && <small> · {row.note}</small>}
                                </strong>
                                <button
                                    type="button"
                                    className="action-change"
                                    data-change={row.key}
                                    aria-label={`Change ${row.label.toLowerCase()}`}
                                    disabled={calls.busy}
                                    onClick={() =>
                                        row.edit
                                            ? onEdit(row.key)
                                            : // A card from before rows edited in place: asked in words, its label as the row says it.
                                              calls.ask(`Change “${row.label}”`)
                                    }
                                >
                                    Change
                                </button>
                            </>
                        )}
                    </div>
                ))}
            </div>
            {block.link && <SeeIt link={block.link} calls={calls} />}
        </>
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
export function PlanChanges({
    block,
    calls,
}: {
    block: PlanChangesBlock;
    calls: ActionCalls;
}) {
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
            {block.link && <SeeIt link={block.link} calls={calls} />}
        </section>
    );
}

/** What Composer won't do itself: where it is, with the asked action ringed. */
export function Handoff({
    block,
    calls,
}: {
    block: HandoffBlock;
    calls: ActionCalls;
}) {
    const { place } = block;

    return (
        <>
            <p>{block.text}</p>
            <div className="action-handoff">
                <span className="action-handoff-crumb">{place.crumb}</span>
                {place.description && <span>{place.description}</span>}
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
                {place.nav ? (
                    <button
                        type="button"
                        className="action-go"
                        onClick={() =>
                            calls.nav('ai_notice', place.purpose ?? undefined)
                        }
                    >
                        {place.label}
                    </button>
                ) : (
                    place.href && (
                        <Link className="action-go" href={place.href}>
                            {place.label}
                        </Link>
                    )
                )}
            </div>
            {block.note && <p className="action-sub">{block.note}</p>}
        </>
    );
}

/**
 * Paperwork's say-so, for this one message (Ask first's Paperwork rule): its
 * title, the one line on what it does, and Allow, which asks the same message
 * again with it. The whole notice stays, unchanged, folded under "What Composer
 * sends": open the first time in a conversation, folded after that. One Allow
 * holds until they turn it off (Settings › Composer). Without it, Composer
 * neither reads nor changes the record.
 */
export function Permission({
    block,
    calls,
    first = false,
}: {
    block: PermissionBlock;
    calls: ActionCalls;
    /** The conversation's first ask: the notice starts open. */
    first?: boolean;
}) {
    const { disclosure } = block;
    const said = !!disclosure.notice?.length || !!disclosure.processor_name;

    return (
        <div className="action-proposal action-permission">
            <strong>{block.title}</strong>
            {block.sub && <span className="action-sub">{block.sub}</span>}
            {said && (
                <details className="action-notice" open={first}>
                    <summary>What Composer sends</summary>
                    {disclosure.notice?.length ? (
                        disclosure.notice.map((sentence) => (
                            <p key={sentence}>{sentence}</p>
                        ))
                    ) : (
                        <p>
                            {`Read by ${disclosure.processor_name}, an AI service, for this message only`}
                        </p>
                    )}
                    {disclosure.processor_privacy_url && (
                        <a
                            className="action-link"
                            href={disclosure.processor_privacy_url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Privacy notice
                        </a>
                    )}
                </details>
            )}
            {!said && disclosure.processor_privacy_url && (
                <a
                    className="action-link"
                    href={disclosure.processor_privacy_url}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Privacy notice
                </a>
            )}
            <span className="action-proposal-buttons">
                <button
                    type="button"
                    className="action-apply"
                    disabled={calls.busy}
                    onClick={() => calls.allow(disclosure)}
                >
                    Allow
                </button>
            </span>
        </div>
    );
}
