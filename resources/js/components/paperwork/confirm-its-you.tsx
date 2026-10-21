import { usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { confirm, send as sendCode } from '@/routes/email-confirmation';
import { store } from '@/routes/password/confirm';
import type { Auth } from '@/types';
import { send } from './api';

/**
 * "Confirm it's you", asked in place when a record action answers 423
 * (password.confirm has gone stale): the password, or, for an account made
 * with Google that has none, a code emailed to the account (EXP-174). Calls
 * onConfirmed once the session is confirmed, so the action can go again.
 */
export function ConfirmItsYou({ onConfirmed }: { onConfirmed: () => void }) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return auth.has_password ? (
        <WithPassword onConfirmed={onConfirmed} />
    ) : (
        <WithEmailCode email={auth.user.email} onConfirmed={onConfirmed} />
    );
}

/** Posts through Paperwork's own JSON command; null on success, else what to say. */
async function post(
    url: string,
    body: Record<string, unknown> = {},
): Promise<string | null> {
    try {
        await send('POST', url, body);

        return null;
    } catch (e) {
        return e instanceof Error
            ? e.message
            : 'Something went wrong on our side. Try again in a moment.';
    }
}

function WithPassword({ onConfirmed }: { onConfirmed: () => void }) {
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        const failed = await post(store.url(), { password });
        setBusy(false);
        setPassword('');
        setError(failed);

        if (!failed) {
            onConfirmed();
        }
    }

    return (
        <form className="confirm-its-you" onSubmit={submit}>
            <p className="muted">
                Enter your password to go on. It isn’t asked again for a while.
            </p>
            <label className="form-field">
                Password
                <input
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    aria-invalid={error ? true : undefined}
                    onChange={(e) => setPassword(e.target.value)}
                />
            </label>
            {error ? (
                <p className="case-inline-error" role="alert">
                    {error}
                </p>
            ) : null}
            <div className="actions">
                <button
                    type="submit"
                    className="button primary"
                    disabled={busy}
                >
                    Confirm
                </button>
            </div>
        </form>
    );
}

function WithEmailCode({
    email,
    onConfirmed,
}: {
    email: string;
    onConfirmed: () => void;
}) {
    const [sent, setSent] = useState(false);
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function ask() {
        setBusy(true);
        const failed = await post(sendCode.url());
        setBusy(false);
        setError(failed);

        if (!failed) {
            setSent(true);
            setCode('');
        }
    }

    async function submit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        const failed = await post(confirm.url(), { code });
        setBusy(false);
        setCode('');
        setError(failed);

        if (!failed) {
            onConfirmed();
        }
    }

    const problem = error ? (
        <p className="case-inline-error" role="alert">
            {error}
        </p>
    ) : null;

    if (!sent) {
        return (
            <div className="confirm-its-you">
                <p className="muted">
                    Your account signs in with Google, so we’ll email a code to{' '}
                    <strong>{email}</strong>.
                </p>
                {problem}
                <div className="actions">
                    <button
                        type="button"
                        className="button primary"
                        disabled={busy}
                        onClick={ask}
                    >
                        Email me a code
                    </button>
                </div>
            </div>
        );
    }

    return (
        <form className="confirm-its-you" onSubmit={submit}>
            <p className="muted">
                We sent a 6-digit code to <strong>{email}</strong>. It works
                once, for 10 minutes.
            </p>
            <label className="form-field">
                Code
                <input
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    required
                    value={code}
                    aria-invalid={error ? true : undefined}
                    onChange={(e) => setCode(e.target.value)}
                />
            </label>
            {problem}
            <div className="actions">
                <button
                    type="button"
                    className="plain-button"
                    disabled={busy}
                    onClick={ask}
                >
                    Send a new code
                </button>
                <button
                    type="submit"
                    className="button primary"
                    disabled={busy}
                >
                    Confirm
                </button>
            </div>
        </form>
    );
}
