import { router } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CommandError, read, send } from './api';
import { ConfirmItsYou } from './confirm-its-you';
import { formatDate } from './format';
import { Icon } from './icons';
import { usePaperwork } from './state';

/** A person's access to this plan (GET people/{person}/sharing). */
interface Grant {
    id: number;
    scopes: string[];
    /** The grantee's account name, for whoever manages the record; null otherwise. */
    name: string | null;
    is_my_access: boolean;
    accepted_at: string;
    expires_at: string;
    revoked_at: string | null;
}

/** What the record's actions need from where they sit: a sheet and a toast. */
interface RecordUi {
    openSheet: (sheet: { title: string; body: ReactNode }) => void;
    closeSheet: () => void;
    toast: (message: string) => void;
}

const scopeLabels: Record<string, string> = {
    view_plan: 'Your plan',
    view_facts: 'Your answers',
    edit_facts: 'Changing your answers',
    manage_process: 'Updating progress',
    manage_evidence: 'Your documents',
};

const base = '/bureaucracy/v2';

/**
 * Share your plan (the mock's bureaucracy-next "sharing" editor): who sees
 * what now, with Remove access, and the record itself, to download, to stop
 * its processing (GDPR Art. 18), or to delete. Invite someone waits for
 * EXP-79 and the design (how an invitation travels, its email field and the
 * accept screen), so it isn't here yet.
 */
export function Sharing({ personId }: { personId: number }) {
    const { toast, openSheet, closeSheet } = usePaperwork();
    const [grants, setGrants] = useState<Grant[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        read<{ grants: Grant[] }>(`${base}/people/${personId}/sharing`)
            .then((data) =>
                // Current access only: not removed, not run out.
                setGrants(
                    data.grants.filter(
                        (grant) =>
                            !grant.revoked_at &&
                            new Date(grant.expires_at) > new Date(),
                    ),
                ),
            )
            .catch((e: Error) => setError(e.message));
    }, [personId]);

    async function remove(grant: Grant) {
        try {
            await send('DELETE', `${base}/grants/${grant.id}`, {});
            setGrants((all) => all?.filter((g) => g.id !== grant.id) ?? null);
            toast('Access removed. They no longer see your plan.');
        } catch (e) {
            setError((e as Error).message);
        }
    }

    return (
        <>
            <p className="muted">
                People you share with see only what you choose. You can remove
                access at any time.
            </p>
            {error ? (
                <p className="case-inline-error" role="alert">
                    {error}
                </p>
            ) : null}
            <section className="sharing-grants">
                <h3>Shared now</h3>
                {grants === null ? (
                    <p className="muted" aria-busy="true">
                        Loading…
                    </p>
                ) : grants.length === 0 ? (
                    <p className="muted">Nobody yet.</p>
                ) : (
                    grants.map((grant) => (
                        <div className="sharing-grant" key={grant.id}>
                            <span>
                                <strong>
                                    {grant.name ?? 'Someone you shared with'}
                                </strong>
                                <small>
                                    {[
                                        ...grant.scopes.map(
                                            (s) => scopeLabels[s] ?? s,
                                        ),
                                        `since ${formatDate(berlinDay(grant.accepted_at))}`,
                                    ].join(' · ')}
                                </small>
                            </span>
                            <button
                                type="button"
                                className="plain-button"
                                onClick={() => void remove(grant)}
                            >
                                Remove access
                            </button>
                        </div>
                    ))
                )}
            </section>
            <section className="sharing-grants">
                <h3>Your record</h3>
                <p className="muted">
                    Download a copy of everything Paperwork keeps about you,
                    stop its processing, or delete it.
                </p>
                <RecordActions
                    personId={personId}
                    stopped={false}
                    ui={{ openSheet, closeSheet, toast }}
                />
            </section>
        </>
    );
}

/**
 * A record whose processing is stopped (entry state 'processing_stopped'):
 * there's no plan to show, since nothing uses the record (Art. 18), so the
 * page says so and keeps the record's own actions. Outside the plan's
 * workspace it brings its own sheet and toast.
 */
export function StoppedRecord({ personId }: { personId: number }) {
    const [sheet, setSheet] = useState<{
        title: string;
        body: ReactNode;
    } | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const timer = useRef<number | null>(null);
    const toast = useCallback((text: string) => {
        setMessage(text);

        if (timer.current) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => setMessage(null), 4200);
    }, []);

    return (
        <>
            <section className="access-blank">
                <h2>Processing stopped.</h2>
                <p>
                    Paperwork keeps your record but doesn’t use it until you
                    resume. You can still download a copy or delete it.
                </p>
                <RecordActions
                    personId={personId}
                    stopped
                    ui={{
                        openSheet: setSheet,
                        closeSheet: () => setSheet(null),
                        toast,
                    }}
                />
            </section>
            <LocalSheet sheet={sheet} onClose={() => setSheet(null)} />
            <div
                className={clsx('toast', message && 'show')}
                role="status"
                aria-live="polite"
            >
                {message}
            </div>
        </>
    );
}

/** A record its holder deleted (entry state 'erased'): said plainly, no "contact us". */
export function ErasedRecord() {
    return (
        <section className="access-blank">
            <h2>Your Paperwork record is deleted.</h2>
            <p>Everything Paperwork kept about you is gone.</p>
        </section>
    );
}

/**
 * Your record's actions (mock #209): Download a copy, Stop or Resume
 * processing, Delete your record. Stopping is one tap and Resume undoes it
 * (owner, 21 Oct 2026); either way the page reloads its entry, which says
 * whether there's a plan to show.
 */
function RecordActions({
    personId,
    stopped,
    ui,
}: {
    personId: number;
    stopped: boolean;
    ui: RecordUi;
}) {
    const [downloading, setDownloading] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [now, setNow] = useState(stopped);
    const [error, setError] = useState<string | null>(null);

    async function setProcessing(stop: boolean) {
        if (switching) {
            return;
        }

        setSwitching(true);
        setError(null);

        try {
            await send(
                'PUT',
                `${base}/people/${personId}/processing-restriction`,
                { stopped: stop },
            );
        } catch (e) {
            setError((e as Error).message);
            setSwitching(false);

            return;
        }

        setNow(stop);
        setSwitching(false);
        ui.toast(
            stop
                ? 'Paperwork stops using your record now. Nothing is deleted.'
                : 'Paperwork uses your record again.',
        );
        // The entry says whether there's still a plan to show; the switch keeps focus.
        router.reload({
            only: ['entry'],
            onFinish: () =>
                document
                    .querySelector<HTMLButtonElement>(
                        '[data-record-processing]',
                    )
                    ?.focus({ preventScroll: true }),
        });
    }

    async function download() {
        setDownloading(true);
        setError(null);
        const response = await fetch(`${base}/people/${personId}/export`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
        }).catch(() => null);
        setDownloading(false);

        if (response?.status === 423) {
            ui.openSheet({
                title: 'Confirm it’s you',
                body: (
                    <ConfirmItsYou
                        onConfirmed={() => {
                            ui.closeSheet();
                            void download();
                        }}
                    />
                ),
            });

            return;
        }

        if (!response?.ok) {
            setError('We couldn’t prepare your copy. Try again in a moment.');

            return;
        }

        save(
            await response.blob(),
            filename(response.headers.get('Content-Disposition')),
        );
        ui.toast('Your copy is downloading.');
    }

    return (
        <div className="record-actions">
            {now ? (
                <p className="record-paused" role="status">
                    <strong>Processing stopped.</strong> Paperwork keeps your
                    record but doesn’t use it until you resume.
                </p>
            ) : null}
            {error ? (
                <p className="case-inline-error" role="alert">
                    {error}
                </p>
            ) : null}
            <div className="actions">
                <button
                    type="button"
                    className="button secondary"
                    disabled={downloading}
                    onClick={() => void download()}
                >
                    Download a copy
                </button>
                <button
                    type="button"
                    className="button secondary"
                    data-record-processing
                    aria-busy={switching || undefined}
                    onClick={() => void setProcessing(!now)}
                >
                    {now ? 'Resume processing' : 'Stop processing'}
                </button>
                <button
                    type="button"
                    className="button secondary"
                    onClick={() =>
                        ui.openSheet({
                            title: 'Delete your record?',
                            body: (
                                <DeleteRecord
                                    personId={personId}
                                    onKeep={ui.closeSheet}
                                    onErased={() => {
                                        ui.closeSheet();
                                        ui.toast(
                                            'Your Paperwork record is deleted.',
                                        );
                                        router.visit('/bureaucracy', {
                                            replace: true,
                                        });
                                    }}
                                />
                            ),
                        })
                    }
                >
                    Delete your record
                </button>
            </div>
        </div>
    );
}

/**
 * Deleting the record asks first: it's permanent (owner, 21 Oct 2026, as
 * Composer's History does), and Keep it is the safe default. A stale
 * confirmation asks for it here, then the delete goes again.
 */
function DeleteRecord({
    personId,
    onKeep,
    onErased,
}: {
    personId: number;
    onKeep: () => void;
    onErased: () => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function erase() {
        setBusy(true);
        setError(null);

        try {
            await send('DELETE', `${base}/people/${personId}`, {
                confirm: true,
            });
        } catch (e) {
            setBusy(false);

            if (e instanceof CommandError && e.status === 423) {
                setConfirming(true);

                return;
            }

            setError((e as Error).message);

            return;
        }

        onErased();
    }

    if (confirming) {
        return (
            <ConfirmItsYou
                onConfirmed={() => {
                    setConfirming(false);
                    void erase();
                }}
            />
        );
    }

    return (
        <div className="delete-record">
            <p>
                This erases everything Paperwork keeps about you: your answers,
                your tasks and your documents. It can’t be undone.
            </p>
            {error ? (
                <p className="case-inline-error" role="alert">
                    {error}
                </p>
            ) : null}
            <div className="actions">
                <button type="button" className="plain-button" onClick={onKeep}>
                    Keep it
                </button>
                <button
                    type="button"
                    className="button primary"
                    disabled={busy}
                    onClick={() => void erase()}
                >
                    Delete record
                </button>
            </div>
        </div>
    );
}

/** The workspace's sheet (editors.tsx SheetDialog), for the stopped page that has none. */
function LocalSheet({
    sheet,
    onClose,
}: {
    sheet: { title: string; body: ReactNode } | null;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = ref.current;

        if (sheet && dialog && !dialog.open) {
            dialog.showModal();
        }

        if (!sheet && dialog?.open) {
            dialog.close();
        }
    }, [sheet]);

    return (
        <dialog
            ref={ref}
            className="detail-dialog"
            onClose={onClose}
            aria-labelledby="record-sheet-title"
        >
            {sheet ? (
                <>
                    <div className="dialog-head">
                        <h2 id="record-sheet-title">{sheet.title}</h2>
                        <button
                            type="button"
                            className="icon-button"
                            onClick={onClose}
                            aria-label="Close"
                        >
                            <Icon name="close" />
                        </button>
                    </div>
                    <div id="dialog-body">{sheet.body}</div>
                </>
            ) : null}
        </dialog>
    );
}

/** The Cologne calendar day of a timestamp, as formatDate takes it (YYYY-MM-DD). */
function berlinDay(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Berlin',
    }).format(new Date(iso));
}

function csrf(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/** The file name the server gives (Content-Disposition), or a plain one. */
function filename(disposition: string | null): string {
    const match = disposition?.match(/filename="?([^";]+)"?/);

    return match?.[1] ?? 'paperwork-record.json';
}

function save(blob: Blob, name: string) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
