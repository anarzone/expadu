import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { clearPrivateBrowserData } from '@/lib/private-browser-data';
import { writeJson } from '@/lib/reliable-write';
import { logout } from '@/routes';
import { send as sendCode } from '@/routes/email-confirmation';
import { update as updatePassword } from '@/routes/user-password';
import type { Auth } from '@/types';
import {
    Actions,
    EditForm,
    Field,
    SettingsIcon,
    Status,
    Tile,
    focusSoon,
} from './parts';

/*
 * Settings' panels, as the mock's settings-next draws them (owner, 22 Oct
 * 2026). Everything shown is the account's own data; each change goes
 * through the endpoint that already makes it: the profile, the password,
 * /user-settings, the emailed code and delete. Two-factor is left out for
 * now (owner's call; Fortify's backend stays).
 */

export type EditKey = 'name' | 'email' | 'password' | 'city' | null;

type Errors = Record<string, string>;

const usePageAuth = () => usePage<{ auth: Auth }>().props.auth;

/** The city names Paperwork reads as Cologne (config bureaucracy_catalogue). */
const isCologne = (city: string) => /^(cologne|köln|koeln)$/i.test(city.trim());

/** Save through Inertia, in place; errors come back as Laravel says them. */
function send(
    method: 'patch' | 'put',
    url: string,
    data: Record<string, string>,
    form: HTMLFormElement | null,
    onSaved: () => void,
    onErrors: (errors: Errors) => void,
) {
    router[method](url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: onSaved,
        onError: (errors) => {
            onErrors(errors);
            requestAnimationFrame(() =>
                form
                    ?.querySelector<HTMLInputElement>('[aria-invalid="true"]')
                    ?.focus(),
            );
        },
    });
}

/** Your account: name, email, how you sign in, the password, sign out. */
export function AccountPanel({
    edit,
    onEdit,
}: {
    edit: EditKey;
    onEdit: (key: EditKey) => void;
}) {
    const { user, has_password: hasPassword } = usePageAuth();
    const google = user.social_provider === 'google' && !hasPassword;
    const [errors, setErrors] = useState<Errors>({});
    const [saved, setSaved] = useState('');

    const open = (key: EditKey) => {
        setErrors({});
        setSaved('');
        onEdit(key);
    };
    // Back to the tile, focus on it; "Saved." when it saved.
    const close = (key: Exclude<EditKey, null>, message = '') => {
        onEdit(null);
        setErrors({});
        setSaved(message);
        focusSoon(`[data-open="${key}"]`);
    };
    const save =
        (key: 'name' | 'email' | 'password') => (form: HTMLFormElement) => {
            const data = Object.fromEntries(
                [...new FormData(form)].map(([k, v]) => [k, String(v)]),
            );
            send(
                key === 'password' ? 'put' : 'patch',
                key === 'password'
                    ? updatePassword.url()
                    : ProfileController.update.url(),
                key === 'password' ? data : { [key]: data[key].trim() },
                form,
                () => close(key, 'Saved.'),
                setErrors,
            );
        };

    return (
        <section
            className="settings-panel settings-account"
            aria-labelledby="settings-title"
        >
            <div className="settings-eyebrow">
                <SettingsIcon name="sliders" />
                Settings
            </div>
            <h1 id="settings-title">Your account</h1>
            <p>How you sign in, and what Expadu keeps about you.</p>
            <div className="settings-tiles">
                {edit === 'name' ? (
                    <EditForm
                        name="name"
                        onSave={save('name')}
                        onCancel={() => close('name')}
                    >
                        <Field
                            id="name"
                            label="Name"
                            autoComplete="name"
                            placeholder="Full name"
                            defaultValue={user.name}
                            error={errors.name}
                        />
                        <Actions onCancel={() => close('name')} />
                    </EditForm>
                ) : (
                    <Tile
                        id="name"
                        icon="user"
                        label="Name"
                        value={user.name}
                        editLabel="Edit"
                        onOpen={() => open('name')}
                    />
                )}
                {edit === 'email' ? (
                    <EditForm
                        name="email"
                        onSave={save('email')}
                        onCancel={() => close('email')}
                    >
                        <Field
                            id="email"
                            label="Email address"
                            type="email"
                            autoComplete="email"
                            defaultValue={user.email}
                            error={errors.email}
                        />
                        <p className="settings-hint">
                            We email a link to the new address to confirm it.
                        </p>
                        <Actions onCancel={() => close('email')} />
                    </EditForm>
                ) : (
                    <Tile
                        id="email"
                        icon="mail"
                        label="Email address"
                        value={user.email}
                        editLabel="Edit"
                        onOpen={() => open('email')}
                    />
                )}
                <div className="settings-tile">
                    <SettingsIcon name="key" />
                    <span className="settings-tile-copy">
                        <small>Sign-in</small>
                        <strong>
                            {google ? 'Google' : 'Email and password'}
                        </strong>
                        <span className="settings-tile-note">
                            {google
                                ? 'You sign in with Google, so there’s no Expadu password.'
                                : `With ${user.email}`}
                        </span>
                    </span>
                </div>
                {/* An account made with Google has no password to change. */}
                {!hasPassword ? null : edit === 'password' ? (
                    <EditForm
                        name="password"
                        onSave={save('password')}
                        onCancel={() => close('password')}
                    >
                        <strong>Change password</strong>
                        <Field
                            id="current_password"
                            label="Current password"
                            type="password"
                            autoComplete="current-password"
                            error={errors.current_password}
                        />
                        <Field
                            id="password"
                            label="New password"
                            type="password"
                            autoComplete="new-password"
                            error={errors.password}
                        />
                        <Field
                            id="password_confirmation"
                            label="Confirm password"
                            type="password"
                            autoComplete="new-password"
                            error={errors.password_confirmation}
                        />
                        <Actions
                            save="Save password"
                            onCancel={() => close('password')}
                        />
                    </EditForm>
                ) : (
                    <Tile
                        id="password"
                        icon="lock"
                        label="Password"
                        value="••••••••"
                        editLabel="Change password"
                        secret
                        onOpen={() => open('password')}
                    />
                )}
            </div>
            <Status text={edit ? '' : saved} />
            <Link
                href={logout()}
                as="button"
                className="settings-quiet settings-out"
                onClick={() => router.flushAll()}
                onSuccess={() => {
                    router.clearHistory();
                    void clearPrivateBrowserData();
                }}
            >
                <SettingsIcon name="sign-out" />
                Sign out
            </Link>
        </section>
    );
}

/** Personalised suggestions: the opt-in (EXP-182); off erases what was learned. */
export function LearnPanel() {
    const { userSettings } = usePage<{
        userSettings?: Record<string, boolean | string>;
    }>().props;
    const [on, setOn] = useState<boolean>(
        (userSettings?.personalisation as boolean) ?? false,
    );
    const [asking, setAsking] = useState(false);
    const [erased, setErased] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function save(next: boolean) {
        if (busy) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await writeJson('/user-settings', 'PUT', {
                settings: { personalisation: next },
            });
            setOn(next);
            setAsking(false);
            setErased(!next);
            router.reload({ only: ['userSettings'] });
            focusSoon('[data-learn]');
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            setBusy(false);
        }
    }

    const keep = () => {
        setAsking(false);
        focusSoon('[data-learn]');
    };

    return (
        <section className="settings-panel" aria-labelledby="learn-title">
            <div className="settings-head">
                <span className="settings-chip learn">
                    <SettingsIcon name="spark-small" />
                </span>
                <div>
                    <h2 id="learn-title">Personalised suggestions</h2>
                    <p>
                        Let Expadu learn from the places and events you open,
                        save or skip.
                    </p>
                </div>
            </div>
            <div className="settings-tile">
                <span className="settings-tile-copy">
                    <strong id="learn-label">Learn what I like</strong>
                    <span className="settings-tile-note" id="learn-state">
                        {on
                            ? 'On: suggestions follow what you open and save'
                            : 'Off: everyone sees the same suggestions'}
                    </span>
                </span>
                <button
                    type="button"
                    className="settings-switch"
                    role="switch"
                    aria-checked={on}
                    aria-labelledby="learn-label"
                    aria-describedby="learn-state"
                    aria-busy={busy || undefined}
                    data-learn
                    onClick={() => {
                        if (on) {
                            setAsking(true);
                            focusSoon('[data-keep-on]');
                        } else {
                            void save(true);
                        }
                    }}
                />
            </div>
            {asking ? (
                <div
                    className="settings-confirm"
                    role="group"
                    aria-labelledby="off-title"
                    onKeyDown={(event) => event.key === 'Escape' && keep()}
                >
                    <strong id="off-title">
                        Turn off and delete what was learned?
                    </strong>
                    <p>
                        What Expadu learned from what you opened, saved or
                        skipped is deleted, and can’t be brought back.
                        Suggestions go back to the same ones everyone sees.
                    </p>
                    <div className="settings-actions">
                        <button
                            type="button"
                            className="settings-quiet"
                            data-keep-on
                            onClick={keep}
                        >
                            Keep it on
                        </button>
                        <button
                            type="button"
                            className="settings-primary"
                            aria-busy={busy || undefined}
                            onClick={() => void save(false)}
                        >
                            Turn off and delete
                        </button>
                    </div>
                </div>
            ) : null}
            {error ? (
                <p className="settings-error" role="alert">
                    {error}
                </p>
            ) : null}
            <Status
                text={
                    erased && !on
                        ? 'Off. What Expadu had learned is deleted.'
                        : ''
                }
            />
            <div className="settings-learns">
                <span>It learns from</span>
                <span>What you open</span>
                <span>What you save</span>
                <span>What you skip</span>
            </div>
        </section>
    );
}

/** City: Cologne, or another city Paperwork doesn't cover yet (users.city). */
export function CityPanel({
    edit,
    onEdit,
}: {
    edit: EditKey;
    onEdit: (key: EditKey) => void;
}) {
    const { user } = usePageAuth();
    const city = String(user.city ?? '').trim();
    const covered = isCologne(city);
    const [draft, setDraft] = useState<'cologne' | 'other'>('cologne');
    const [errors, setErrors] = useState<Errors>({});
    const [saved, setSaved] = useState('');

    const close = (message = '') => {
        onEdit(null);
        setErrors({});
        setSaved(message);
        focusSoon('[data-open="city"]');
    };

    return (
        <section className="settings-panel" aria-labelledby="city-title">
            <div className="settings-head">
                <span className="settings-chip place">
                    <SettingsIcon name="pin-narrow" />
                </span>
                <div>
                    <h2 id="city-title">City</h2>
                    <p>
                        Paperwork’s guidance depends on it. Expadu covers
                        Cologne for now.
                    </p>
                </div>
            </div>
            {edit === 'city' ? (
                <EditForm
                    name="city"
                    onCancel={() => close()}
                    onSave={(form) => {
                        const name = String(
                            new FormData(form).get('city') ?? '',
                        ).trim();

                        // Laravel takes an empty city, so this one is asked here.
                        if (draft === 'other' && name === '') {
                            setErrors({ city: 'The city field is required.' });
                            focusSoon('#city');

                            return;
                        }

                        send(
                            'patch',
                            ProfileController.update.url(),
                            { city: draft === 'cologne' ? 'Cologne' : name },
                            form,
                            () => close('Saved.'),
                            setErrors,
                        );
                    }}
                >
                    <fieldset className="settings-choices">
                        <legend>Your city</legend>
                        <label className="settings-choice">
                            <input
                                type="radio"
                                name="city-kind"
                                value="cologne"
                                checked={draft === 'cologne'}
                                onChange={() => setDraft('cologne')}
                            />
                            <span>
                                <strong>Cologne</strong>
                                Reviewed Paperwork guidance
                            </span>
                        </label>
                        <label className="settings-choice">
                            <input
                                type="radio"
                                name="city-kind"
                                value="other"
                                checked={draft === 'other'}
                                onChange={() => setDraft('other')}
                            />
                            <span>
                                <strong>Another city</strong>
                                No reviewed guidance yet
                            </span>
                        </label>
                    </fieldset>
                    {draft === 'other' ? (
                        <>
                            <Field
                                id="city"
                                label="City name"
                                autoComplete="address-level2"
                                placeholder="Your city"
                                maxLength={255}
                                defaultValue={covered ? '' : city}
                                error={errors.city}
                            />
                            <p className="settings-hint paper">
                                Paperwork covers Cologne so far, and doesn’t
                                guess for other cities. Your answers stay.
                            </p>
                        </>
                    ) : null}
                    <Actions onCancel={() => close()} />
                </EditForm>
            ) : (
                <Tile
                    id="city"
                    label="Your city"
                    value={
                        city === '' ? 'Not set yet' : covered ? 'Cologne' : city
                    }
                    editLabel="Change your city"
                    extra={
                        city === '' ? null : (
                            <span
                                className={
                                    covered
                                        ? 'settings-badge'
                                        : 'settings-badge none'
                                }
                            >
                                {covered
                                    ? 'Reviewed guidance'
                                    : 'No reviewed guidance'}
                            </span>
                        )
                    }
                    onOpen={() => {
                        setDraft(city === '' || covered ? 'cologne' : 'other');
                        setErrors({});
                        setSaved('');
                        onEdit('city');
                    }}
                />
            )}
            <Status text={edit === 'city' ? '' : saved} />
        </section>
    );
}

/** A part that comes later: dashed, with a label saying when, offering nothing. */
export function LaterPanel({
    id,
    icon,
    title,
    label,
    children,
}: {
    id: string;
    icon: 'bell' | 'sliders';
    title: string;
    label: string;
    children: string;
}) {
    return (
        <section
            className="settings-panel is-later"
            aria-labelledby={`${id}-title`}
        >
            <div className="settings-head">
                <span className="settings-chip">
                    <SettingsIcon name={icon} />
                </span>
                <h2 id={`${id}-title`}>{title}</h2>
                <span className="settings-later-label">{label}</span>
            </div>
            <p>{children}</p>
        </section>
    );
}

/**
 * Delete account: permanent, so it asks first, in place. Confirmed with the
 * password, or for an account made with Google an emailed code (EXP-174).
 */
export function DeletePanel() {
    const { user, has_password: hasPassword } = usePageAuth();
    const [asking, setAsking] = useState(false);
    const [codeSent, setCodeSent] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const keep = () => {
        setAsking(false);
        setCodeSent(false);
        setErrors({});
        focusSoon('[data-delete]');
    };

    function emailCode() {
        setErrors({});
        router.post(
            sendCode.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setCodeSent(true);
                    focusSoon('#code');
                },
                onError: (e) => setErrors(e),
            },
        );
    }

    return (
        <section className="settings-panel" aria-labelledby="delete-title">
            <div className="settings-head">
                <span className="settings-chip delete">
                    <SettingsIcon name="trash" />
                </span>
                <div>
                    <h2 id="delete-title">Delete account</h2>
                    <p>
                        Delete your account and all of its resources. This can’t
                        be undone.
                    </p>
                </div>
            </div>
            {asking ? (
                <form
                    className="settings-confirm"
                    data-form="delete"
                    role="group"
                    aria-labelledby="sure-title"
                    noValidate
                    onKeyDown={(event) => event.key === 'Escape' && keep()}
                    onSubmit={(event) => {
                        event.preventDefault();
                        const form = event.currentTarget;
                        const secret = form.querySelector<HTMLInputElement>(
                            '#code, #delete_password',
                        );

                        if (!secret) {
                            focusSoon('[data-send-code]');

                            return;
                        }

                        setErrors({});
                        router.delete(ProfileController.destroy.url(), {
                            data: hasPassword
                                ? { password: secret.value }
                                : { code: secret.value },
                            preserveScroll: true,
                            onError: (e) => {
                                setErrors(e);
                                focusSoon('#code, #delete_password');
                            },
                            onSuccess: () => {
                                router.clearHistory();
                                void clearPrivateBrowserData();
                            },
                        });
                    }}
                >
                    <strong id="sure-title">
                        Are you sure you want to delete your account?
                    </strong>
                    <p>
                        Once your account is deleted, all of its resources and
                        data will also be permanently deleted.
                    </p>
                    {hasPassword ? (
                        <Field
                            id="delete_password"
                            label="Enter your password to confirm"
                            className="short"
                            type="password"
                            autoComplete="current-password"
                            error={errors.password}
                        />
                    ) : (
                        <>
                            <p>
                                To confirm, enter the code we email to{' '}
                                {user.email}.
                            </p>
                            {codeSent ? (
                                <>
                                    <Field
                                        id="code"
                                        label="Code from your email"
                                        className="code"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        error={errors.code}
                                    />
                                    <button
                                        type="button"
                                        className="settings-link"
                                        data-send-code
                                        onClick={emailCode}
                                    >
                                        Send a new code
                                    </button>
                                </>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        className="settings-quiet"
                                        style={{ justifySelf: 'start' }}
                                        data-send-code
                                        onClick={emailCode}
                                    >
                                        Email me a code
                                    </button>
                                    <p
                                        className="settings-error"
                                        hidden={!errors.code}
                                    >
                                        {errors.code}
                                    </p>
                                </>
                            )}
                        </>
                    )}
                    <div className="settings-actions">
                        <button
                            type="button"
                            className="settings-quiet"
                            onClick={keep}
                        >
                            Keep my account
                        </button>
                        <button type="submit" className="settings-danger">
                            Delete account
                        </button>
                    </div>
                </form>
            ) : (
                <button
                    type="button"
                    className="settings-danger-outline"
                    data-delete
                    onClick={() => {
                        setAsking(true);
                        focusSoon(
                            hasPassword
                                ? '#delete_password'
                                : '[data-send-code]',
                        );
                    }}
                >
                    Delete account
                </button>
            )}
        </section>
    );
}
