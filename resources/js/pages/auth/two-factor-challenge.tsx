import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    AccountField,
    AccountFlash,
    AccountLabel,
} from '@/components/account/account-parts';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/two-factor/login';

/** The second step of signing in, on the same poster: the authenticator's
    code, or one of the saved recovery codes. */
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) =>
            recovery
                ? { recovery_code: data.recovery_code }
                : { code: data.code },
        );
        form.post(store.url(), { onError: () => form.reset() });
    }

    function toggle() {
        setRecovery((r) => !r);
        form.clearErrors();
        form.reset();
    }

    return (
        <AuthLayout>
            <Head title="Two-step sign-in" />
            <form onSubmit={submit}>
                <AccountLabel>Noch ein Schritt</AccountLabel>
                <h1 tabIndex={-1}>
                    {recovery ? 'Use a recovery code' : 'Enter your code'}
                </h1>
                <p className="account-lead">
                    {recovery
                        ? 'One of the recovery codes you saved when you turned on two-step sign-in.'
                        : 'The code your authenticator app shows for Expadu.'}
                </p>
                <AccountFlash />
                {recovery ? (
                    <AccountField
                        key="recovery"
                        id="recovery_code"
                        label="Recovery code"
                        autoComplete="off"
                        value={form.data.recovery_code}
                        onChange={(v) => form.setData('recovery_code', v)}
                        error={form.errors.recovery_code}
                    />
                ) : (
                    <AccountField
                        key="code"
                        id="code"
                        label={`${OTP_MAX_LENGTH}-digit code`}
                        type="text"
                        autoComplete="one-time-code"
                        value={form.data.code}
                        onChange={(v) =>
                            form.setData(
                                'code',
                                v.replace(/\D/g, '').slice(0, OTP_MAX_LENGTH),
                            )
                        }
                        error={form.errors.code}
                    />
                )}
                <button
                    className="account-primary"
                    type="submit"
                    disabled={form.processing}
                >
                    Continue
                </button>
                <button
                    className="account-quiet"
                    type="button"
                    onClick={toggle}
                >
                    {recovery
                        ? 'Use the code from your app'
                        : 'Use a recovery code instead'}
                </button>
            </form>
        </AuthLayout>
    );
}
