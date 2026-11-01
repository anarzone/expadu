import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    AccountFlash,
    AccountLabel,
    AccountField,
    AccountPassword,
    AccountSocial,
} from '@/components/account/account-parts';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    socialProviders: string[];
    /** The marketing site's privacy policy. */
    privacyUrl: string;
};

/** Create an account (the mock's #create): one password with Show, sent as
    its own confirmation too. */
export default function Register({ socialProviders, privacyUrl }: Props) {
    const form = useForm({
        name: '',
        email: '',
        password: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            password_confirmation: data.password,
        }));
        form.post(store.url(), {
            onFinish: () => form.reset('password'),
        });
    }

    return (
        <AuthLayout
            switchTo={{
                href: login.url(),
                lead: 'Have an account?',
                action: 'Sign in',
            }}
        >
            <Head title="Create an account" />
            <form onSubmit={submit}>
                <AccountLabel>Willkommen in Köln</AccountLabel>
                <h1 tabIndex={-1}>New in Cologne? Start here.</h1>
                <p className="account-lead">Free to start. Cologne, for now.</p>
                <AccountFlash />
                <AccountSocial providers={socialProviders} />
                <AccountField
                    id="name"
                    label="Name"
                    autoComplete="name"
                    value={form.data.name}
                    onChange={(v) => form.setData('name', v)}
                    error={form.errors.name}
                />
                <AccountField
                    id="email"
                    label="Email"
                    type="email"
                    autoComplete="email"
                    value={form.data.email}
                    onChange={(v) => form.setData('email', v)}
                    error={form.errors.email}
                />
                <AccountPassword
                    id="password"
                    label="Password"
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
                    Create account
                </button>
                <p className="account-note">
                    We handle your details as our{' '}
                    <a href={privacyUrl} title="The app’s privacy policy">
                        privacy policy
                    </a>{' '}
                    describes.
                </p>
            </form>
        </AuthLayout>
    );
}
