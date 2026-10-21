import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    AccountFlash,
    AccountLabel,
    AccountMessage,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { login, logout, register } from '@/routes';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

/** Check your email (the mock's #check-email): where the link went, sending
    it again, or starting again with another address. */
export default function VerifyEmail({ status }: { status?: string }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const resend = useForm({});

    return (
        <AuthLayout
            switchTo={{
                href: login.url(),
                lead: 'Have an account?',
                action: 'Sign in',
            }}
        >
            <Head title="Check your email" />
            <div className="account-body">
                <AccountLabel>Fast geschafft</AccountLabel>
                <h1 tabIndex={-1}>Check your email</h1>
                <p className="account-mail">
                    We sent a link to <b>{auth.user?.email}</b>. Open it to
                    finish creating your account.
                </p>
                <p className="account-note">
                    It works for 60 minutes. Not there? Look in spam.
                </p>
                <AccountFlash />
                {status === 'verification-link-sent' ? (
                    <AccountMessage>A new link is on its way.</AccountMessage>
                ) : null}
                <button
                    className="account-secondary"
                    type="button"
                    disabled={resend.processing}
                    onClick={() => resend.post(send.url())}
                >
                    Send the link again
                </button>
                <button
                    className="account-quiet"
                    type="button"
                    onClick={() =>
                        router.post(
                            logout.url(),
                            {},
                            { onSuccess: () => router.visit(register.url()) },
                        )
                    }
                >
                    Wrong email? Start again
                </button>
            </div>
        </AuthLayout>
    );
}
