import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    AccountFlash,
    AccountLabel,
    AccountMessage,
    AccountPassword,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
};

/** Choose a new password (the mock's #new-password): one field, sent as its
    own confirmation too; then sign in with it. */
export default function ResetPassword({ token, email }: Props) {
    const form = useForm({ token, email, password: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            password_confirmation: data.password,
        }));
        form.post(update.url(), { onFinish: () => form.reset('password') });
    }

    return (
        <AuthLayout
            switchTo={{
                href: login.url(),
                lead: 'Have an account?',
                action: 'Sign in',
            }}
        >
            <Head title="Choose a new password" />
            <form onSubmit={submit}>
                <AccountLabel>Neues Passwort</AccountLabel>
                <h1 tabIndex={-1}>Choose a new password</h1>
                <p className="account-lead">
                    For <b>{email}</b>.
                </p>
                <AccountFlash />
                {/* An expired or used link comes back on the email. */}
                {form.errors.email ? (
                    <AccountMessage kind="mismatch">
                        {form.errors.email}
                    </AccountMessage>
                ) : null}
                <AccountPassword
                    id="password"
                    label="New password"
                    autoComplete="new-password"
                    hint
                    value={form.data.password}
                    onChange={(v) => form.setData('password', v)}
                    error={form.errors.password}
                />
                <button
                    className="account-primary"
                    type="submit"
                    disabled={form.processing}
                >
                    Save new password
                </button>
                <p className="account-note">
                    Then sign in with it. The link works once, for 60 minutes.
                </p>
            </form>
        </AuthLayout>
    );
}
