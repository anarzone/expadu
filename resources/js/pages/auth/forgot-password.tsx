import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    AccountFlash,
    AccountLabel,
    AccountField,
    AccountMessage,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { login, register } from '@/routes';
import { email as sendLink } from '@/routes/password';

/** Reset your password (the mock's #forgot, then #sent once the link is on
    its way). */
export default function ForgotPassword({ status }: { status?: string }) {
    const form = useForm({ email: '' });
    const sent = !!status && !form.errors.email;

    function submit(e?: FormEvent) {
        e?.preventDefault();
        form.post(sendLink.url(), { preserveState: true });
    }

    return (
        <AuthLayout
            switchTo={{
                href: register.url(),
                lead: 'New here?',
                action: 'Create an account',
            }}
        >
            <Head title="Reset your password" />
            {sent ? (
                <div className="account-body">
                    <a className="account-back" href={login.url()}>
                        ← Back to sign in
                    </a>
                    <AccountLabel>Kein Problem</AccountLabel>
                    <h1 tabIndex={-1}>Reset your password</h1>
                    <AccountMessage>
                        {/* Each run of text one node, as the mock's, so it
                            shapes the same. */}
                        {form.data.email ? (
                            <>
                                {'The link is on its way to '}
                                <b>{form.data.email}</b>
                                {'. It works for 60 minutes.'}
                            </>
                        ) : (
                            'The link is on its way. It works for 60 minutes.'
                        )}
                    </AccountMessage>
                    <button
                        className="account-secondary"
                        type="button"
                        disabled={form.processing}
                        onClick={() => submit()}
                    >
                        Send it again
                    </button>
                </div>
            ) : (
                <form onSubmit={submit}>
                    <a className="account-back" href={login.url()}>
                        ← Back to sign in
                    </a>
                    <AccountLabel>Kein Problem</AccountLabel>
                    <h1 tabIndex={-1}>Reset your password</h1>
                    <p className="account-lead">
                        We’ll email you a link to choose a new one.
                    </p>
                    <AccountFlash />
                    <AccountField
                        id="email"
                        label="Email"
                        type="email"
                        autoComplete="email"
                        value={form.data.email}
                        onChange={(v) => form.setData('email', v)}
                        error={form.errors.email}
                    />
                    <button
                        className="account-primary"
                        type="submit"
                        disabled={form.processing}
                    >
                        Send the link
                    </button>
                </form>
            )}
        </AuthLayout>
    );
}
