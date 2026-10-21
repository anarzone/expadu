import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    AccountFlash,
    AccountLabel,
    AccountPassword,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/password/confirm';

/** Your password again before a sensitive change, on the same poster. */
export default function ConfirmPassword() {
    const form = useForm({ password: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(store.url(), { onFinish: () => form.reset('password') });
    }

    return (
        <AuthLayout>
            <Head title="Confirm your password" />
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
        </AuthLayout>
    );
}
