import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    AccountField,
    AccountFlash,
    AccountLabel,
    AccountMessage,
    AccountPassword,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { confirm, send } from '@/routes/email-confirmation';
import { store } from '@/routes/password/confirm';
import type { Auth } from '@/types';

/** Your password again before a sensitive change, on the same poster. An
    account made with Google has none, so it gets a code by email instead. */
export default function ConfirmPassword() {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <AuthLayout>
            <Head title="Confirm it’s you" />
            {auth.has_password ? (
                <WithPassword />
            ) : (
                <WithEmailCode email={auth.user.email} />
            )}
        </AuthLayout>
    );
}

function WithPassword() {
    const form = useForm({ password: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(store.url(), { onFinish: () => form.reset('password') });
    }

    return (
        <form onSubmit={submit}>
            <AccountLabel>Nur zur Sicherheit</AccountLabel>
            <h1 tabIndex={-1}>Confirm your password</h1>
            <p className="account-lead">
                This part of your account asks for it once more.
            </p>
            <AccountFlash />
            <AccountPassword
                id="password"
                label="Password"
                autoComplete="current-password"
                value={form.data.password}
                onChange={(v) => form.setData('password', v)}
                error={form.errors.password}
            />
            <button
                className="account-primary"
                type="submit"
                disabled={form.processing}
            >
                Confirm
            </button>
        </form>
    );
}

function WithEmailCode({ email }: { email: string }) {
    const [sent, setSent] = useState(false);
    const [again, setAgain] = useState(false);
    // Sends nothing; a failed send comes back as a `code` error.
    const request = useForm<{ code?: string }>({});
    const form = useForm({ code: '' });

    function ask() {
        request.post(send.url(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setAgain(sent);
                setSent(true);
                form.reset('code');
                form.clearErrors();
            },
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(confirm.url(), {
            preserveState: true,
            onError: () => form.reset('code'),
        });
    }

    if (!sent) {
        return (
            <div className="account-body">
                <AccountLabel>Nur zur Sicherheit</AccountLabel>
                <h1 tabIndex={-1}>Confirm it’s you</h1>
                <p className="account-mail">
                    Your account signs in with Google, so we’ll email a code to{' '}
                    <b>{email}</b>.
                </p>
                <AccountFlash />
                {request.errors.code ? (
                    <AccountMessage kind="mismatch">
                        {request.errors.code}
                    </AccountMessage>
                ) : null}
                <button
                    className="account-primary"
                    type="button"
                    disabled={request.processing}
                    onClick={ask}
                >
                    Email me a code
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit}>
            <AccountLabel>Nur zur Sicherheit</AccountLabel>
            <h1 tabIndex={-1}>Enter your code</h1>
            <p className="account-mail">
                We sent a 6-digit code to <b>{email}</b>.
            </p>
            <p className="account-note">
                It works once, for 10 minutes. Not there? Look in spam.
            </p>
            <AccountFlash />
            {again ? (
                <AccountMessage>A new code is on its way.</AccountMessage>
            ) : null}
            <AccountField
                id="code"
                label="Code"
                inputMode="numeric"
                maxLength={6}
                autoComplete="one-time-code"
                value={form.data.code}
                onChange={(v) => form.setData('code', v)}
                error={form.errors.code ?? request.errors.code}
            />
            <button
                className="account-primary"
                type="submit"
                disabled={form.processing}
            >
                Confirm
            </button>
            <button
                className="account-secondary"
                type="button"
                disabled={request.processing}
                onClick={ask}
            >
                Send a new code
            </button>
        </form>
    );
}
