import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useRef } from 'react';
import {
    AccountLabel,
    AccountField,
    AccountMessage,
    AccountPassword,
    AccountSocial,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    /** A sign-in with Google that didn't finish. */
    error?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    socialProviders: string[];
};

/** lang/en/auth.php's `failed`, as the poster begins it. */
const MISMATCH = 'That email and password don’t match.';

/** Sign in (the mock's #sign-in and #mismatch): the email stays when the
    details don't match, and the password takes the focus. */
export default function Login({
    status,
    error,
    canResetPassword,
    canRegister,
    socialProviders,
}: Props) {
    const form = useForm({
        email: '',
        password: '',
        remember: true,
    });
    const password = useRef<HTMLInputElement>(null);
    const mismatch = form.errors.email;

    useEffect(() => {
        if (mismatch) {
            password.current?.focus();
        }
    }, [mismatch]);

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(store.url(), {
            onFinish: () => form.reset('password'),
        });
    }

    return (
        <AuthLayout
            switchTo={
                canRegister
                    ? {
                          href: register.url(),
                          lead: 'New here?',
                          action: 'Create an account',
                      }
                    : undefined
            }
        >
            <Head title="Sign in" />
            <form onSubmit={submit}>
                <AccountLabel>Hallo again</AccountLabel>
                <h1 tabIndex={-1}>Welcome back</h1>
                {mismatch ? (
                    <AccountMessage kind="mismatch">
                        {/* After lang/en/auth.php's `failed` (not its
                            throttle line), the way out; each run of text one
                            node, as the mock's, so it shapes the same. */}
                        {mismatch.startsWith(MISMATCH) && canResetPassword ? (
                            <>
                                {`${mismatch} Check both, or `}
                                <a href={request.url()}>reset your password</a>
                                {'.'}
                            </>
                        ) : (
                            mismatch
                        )}
                    </AccountMessage>
                ) : (
                    <>
                        <p className="account-lead">Sign in to see your day.</p>
                        {error ? (
                            <AccountMessage kind="mismatch">
                                {error}
                            </AccountMessage>
                        ) : status ? (
                            <AccountMessage kind="done">
                                {status}
                            </AccountMessage>
                        ) : null}
                    </>
                )}
                <AccountSocial providers={socialProviders} />
                <AccountField
                    id="email"
                    label="Email"
                    type="email"
                    autoComplete="email"
                    value={form.data.email}
                    onChange={(v) => form.setData('email', v)}
                />
                <AccountPassword
                    id="password"
                    label="Password"
                    autoComplete="current-password"
                    value={form.data.password}
                    onChange={(v) => form.setData('password', v)}
                    forgotHref={canResetPassword ? request.url() : undefined}
                    error={form.errors.password}
                    inputRef={password}
                />
                <label className="account-keep">
                    <input
                        type="checkbox"
                        name="remember"
                        checked={form.data.remember}
                        onChange={(e) =>
                            form.setData('remember', e.target.checked)
                        }
                    />
                    Keep me signed in
                </label>
                <button
                    className="account-primary"
                    type="submit"
                    disabled={form.processing}
                >
                    Sign in
                </button>
            </form>
        </AuthLayout>
    );
}
