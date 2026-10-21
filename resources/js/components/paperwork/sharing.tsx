import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CommandError, read, send } from './api';
import { ConfirmItsYou } from './confirm-its-you';
import { formatDate } from './format';
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
 * what now, with Remove access, and the record itself, to download or delete.
 * Invite someone waits for EXP-79 and the design (how an invitation travels,
 * its email field and the accept screen), so it isn't here yet.
 */
export function Sharing({ personId }: { personId: number }) {
    const { toast, openSheet, closeSheet } = usePaperwork();
    const [grants, setGrants] = useState<Grant[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [downloading, setDownloading] = useState(false);

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

    async function download() {
        setDownloading(true);
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
            openSheet({
                title: 'Confirm it’s you',
                body: (
                    <ConfirmItsYou
                        onConfirmed={() => {
                            closeSheet();
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
        toast('Your copy is downloading.');
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
                    Download a copy of everything Paperwork keeps about you, or
                    delete it.
                </p>
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
                        onClick={() =>
                            openSheet({
                                title: 'Delete your record?',
                                body: (
                                    <DeleteRecord
                                        personId={personId}
                                        onKeep={closeSheet}
                                    />
                                ),
                            })
                        }
                    >
                        Delete your record
                    </button>
                </div>
            </section>
        </>
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
}: {
    personId: number;
    onKeep: () => void;
}) {
    const { closeSheet, toast } = usePaperwork();
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

        closeSheet();
        toast('Your Paperwork record is deleted.');
        router.visit('/bureaucracy', { replace: true });
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
