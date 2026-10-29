import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ComposerPaperworkPermissionController from '@/actions/App/Http/Controllers/ComposerPaperworkPermissionController';
import AccountExportController from '@/actions/App/Http/Controllers/Settings/AccountExportController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import {
    AiOrderChange,
    agreeToAiOrder,
} from '@/components/personalisation/ai-order-paused';
import type { AiOrderPaused } from '@/components/personalisation/ai-order-paused';
import type { IconName } from '@/components/ui/icon';
import { csrfHeaders } from '@/lib/csrf';
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
        <section className="settings-panel" aria-labelledby="account-title">
            <div className="settings-head">
                <span className="settings-chip account">
                    <SettingsIcon name="user" />
                </span>
                <div>
                    <h2 id="account-title">Your account</h2>
                    <p>How you sign in, and what Expadu keeps about you.</p>
                </div>
            </div>
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

/** Where personalisation learns from (PersonalisationWording, the second wording). */
const LEARNS_FROM: [IconName, string][] = [
    ['pin', 'Places'],
    ['calendar', 'Events'],
    ['bell', 'Today alerts'],
    ['train', 'Travel stops'],
    ['spark', 'Composer topics'],
];

/** Personalised suggestions: the opt-in (EXP-182); off erases what was learned. */
export function LearnPanel() {
    const { userSettings, todayRankPaused } = usePage<{
        userSettings?: Record<string, boolean | string>;
        /** Today's AI order, waiting for them to agree to a change (TodayRankPause). */
        todayRankPaused?: AiOrderPaused;
    }>().props;
    const [on, setOn] = useState<boolean>(
        (userSettings?.personalisation as boolean) ?? false,
    );
    // On under the older wording (places and events only): Today, Travel
    // and Composer join once they turn it on again under this one
    // (PersonalisationWording).
    const older = on && userSettings?.personalisation_current === false;
    const [asking, setAsking] = useState(false);
    const [reviewing, setReviewing] = useState(false);
    const [agreeing, setAgreeing] = useState(false);
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

    // Agreed to the change: Settings reads Today's AI order afresh.
    const agreed = () => {
        setReviewing(false);
        router.reload({ only: ['todayRank', 'todayRankPaused'] });
    };

    async function agreeNow(paused: NonNullable<AiOrderPaused>) {
        if (agreeing) {
            return;
        }

        setAgreeing(true);
        setError('');

        try {
            await agreeToAiOrder(paused);
            agreed();
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'That didn’t go through. Try again.',
            );
        } finally {
            setAgreeing(false);
        }
    }

    return (
        <section className="settings-panel" aria-labelledby="learn-title">
            <div className="settings-head">
                <span className="settings-chip learn">
                    <SettingsIcon name="spark-small" />
                </span>
                <div>
                    <h2 id="learn-title">Personalised suggestions</h2>
                    <p>
                        Let Expadu learn from the places, events, Today alerts,
                        Travel stops and Composer topics you open, search, save,
                        skip or dismiss, and let AI (Claude Haiku) put Today in
                        order from that.
                    </p>
                </div>
            </div>
            <div className="settings-tiles">
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
            </div>
            {older && !asking ? (
                <p className="settings-learn-older">
                    It learns only from places and events for now.{' '}
                    <button
                        type="button"
                        className="settings-link"
                        aria-busy={busy || undefined}
                        onClick={() => void save(true)}
                    >
                        Turn on again
                    </button>{' '}
                    to include Today, Travel and Composer.
                </p>
            ) : null}
            {on && !older && !asking && todayRankPaused ? (
                <div className="ai-order-note" role="status">
                    <SettingsIcon name="clock" />
                    <div>
                        <strong>Today’s AI order is paused</strong>
                        <p>
                            We changed how the AI that puts Today in order
                            works. Until you agree to the change, Today shows
                            the order everyone sees. What Expadu learned is
                            kept.
                        </p>
                        <div className="settings-actions">
                            <button
                                type="button"
                                className="settings-primary"
                                aria-busy={agreeing || undefined}
                                onClick={() => void agreeNow(todayRankPaused)}
                            >
                                Agree to the change
                            </button>
                            <button
                                type="button"
                                className="settings-quiet"
                                onClick={() => setReviewing(true)}
                            >
                                See what changed
                            </button>
                        </div>
                    </div>
                    <AiOrderChange
                        paused={todayRankPaused}
                        open={reviewing}
                        onClose={() => setReviewing(false)}
                        onAgreed={agreed}
                        notNow={() => setReviewing(false)}
                    />
                </div>
            ) : null}
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
            {/* What it learns from, as the text above says it: under the
                older wording, places and events only. */}
            {on ? (
                <>
                    <h3 className="settings-sub">It learns from</h3>
                    <ul className="settings-learns">
                        {(older ? LEARNS_FROM.slice(0, 2) : LEARNS_FROM).map(
                            ([icon, label]) => (
                                <li key={label}>
                                    <SettingsIcon name={icon} />
                                    {label}
                                </li>
                            ),
                        )}
                    </ul>
                </>
            ) : null}
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
            <div className="settings-tiles">
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
                                setErrors({
                                    city: 'The city field is required.',
                                });
                                focusSoon('#city');

                                return;
                            }

                            send(
                                'patch',
                                ProfileController.update.url(),
                                {
                                    city:
                                        draft === 'cologne' ? 'Cologne' : name,
                                },
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
                            city === ''
                                ? 'Not set yet'
                                : covered
                                  ? 'Cologne'
                                  : city
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
                            setDraft(
                                city === '' || covered ? 'cologne' : 'other',
                            );
                            setErrors({});
                            setSaved('');
                            onEdit('city');
                        }}
                    />
                )}
            </div>
            <Status text={edit === 'city' ? '' : saved} />
        </section>
    );
}

/**
 * The settings Composer reads before it changes something for you (owner,
 * 23 Oct 2026, "Composer does things" canvas). Off, it makes the change and
 * offers Undo; on, it proposes the change and waits for Apply. Composer's
 * Ask first chip links here (/settings/composer).
 */
const ASK_FIRST = [
    {
        key: 'composer_ask_first_everyday',
        id: 'ask-everyday',
        label: 'Ask first for everyday changes',
        off: 'Off: saves, My day, Home and Work change right away, with Undo',
        always: null,
        fallback: false,
    },
    {
        key: 'composer_ask_first_paperwork',
        id: 'ask-paperwork',
        label: 'Ask first for Paperwork changes',
        off: 'Off: Paperwork steps and reports change right away, with Undo',
        // Paperwork takes an answer read from someone's words only once
        // they confirm it, so Apply is that confirmation (AI session).
        always: 'Changes to your answers always ask first.',
        fallback: true,
    },
] as const;

type AskFirstKey = (typeof ASK_FIRST)[number]['key'];

type PaperworkPermissionState = {
    enabled: boolean;
    available: boolean;
    has_record: boolean;
};

/**
 * Composer's remembered Allow for their Paperwork record (coordinator, 27 Oct
 * 2026): shown only while Paperwork's AI is on and they have a record in use.
 * Turning it off needs no OK; turning it on is Composer's own card, which
 * shows the notice it is given under.
 */
function PaperworkPermission() {
    const { composerPaperwork } = usePage<{
        composerPaperwork?: PaperworkPermissionState;
    }>().props;
    const [state, setState] = useState(composerPaperwork ?? null);
    const [busy, setBusy] = useState(false);
    const [note, setNote] = useState('');
    const [error, setError] = useState('');

    if (!state?.available || !state.has_record) {
        return null;
    }

    async function turnOff() {
        if (busy) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            const body = await writeJson<{ message?: string }>(
                ComposerPaperworkPermissionController.update.url(),
                'PUT',
                { enabled: false },
            );
            setState((now) => (now ? { ...now, enabled: false } : now));
            setNote(body.message ?? '');
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

    return (
        <div className="settings-tile" data-paperwork-permission>
            <span className="settings-tile-copy">
                <strong>Composer can use your Paperwork record</strong>
                <span className="settings-tile-note" role="status">
                    {state.enabled
                        ? 'On: remembered until you turn it off'
                        : note ||
                          'Off: Composer asks on a card when it needs your record'}
                </span>
                {error ? (
                    <span className="settings-error" role="alert">
                        {error}
                    </span>
                ) : null}
            </span>
            {state.enabled ? (
                <button
                    type="button"
                    className="settings-quiet"
                    aria-busy={busy || undefined}
                    onClick={() => void turnOff()}
                >
                    Turn off
                </button>
            ) : null}
        </div>
    );
}

export function ComposerPanel() {
    const { userSettings } = usePage<{
        userSettings?: Record<string, boolean | string>;
    }>().props;
    const [on, setOn] = useState<Record<AskFirstKey, boolean>>(
        () =>
            Object.fromEntries(
                ASK_FIRST.map((s) => [
                    s.key,
                    (userSettings?.[s.key] as boolean | undefined) ??
                        s.fallback,
                ]),
            ) as Record<AskFirstKey, boolean>,
    );
    const [busy, setBusy] = useState<AskFirstKey | null>(null);
    const [error, setError] = useState('');

    async function save(key: AskFirstKey, next: boolean) {
        if (busy) {
            return;
        }

        setBusy(key);
        setError('');

        try {
            await writeJson('/user-settings', 'PUT', {
                settings: { [key]: next },
            });
            setOn((now) => ({ ...now, [key]: next }));
            router.reload({ only: ['userSettings'] });
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            setBusy(null);
        }
    }

    return (
        <section className="settings-panel" aria-labelledby="composer-title">
            <div className="settings-head">
                <span className="settings-chip composer">
                    <SettingsIcon name="spark-small" />
                </span>
                <div>
                    <h2 id="composer-title">Composer</h2>
                    <p>
                        Composer can make changes for you, each with Undo. With
                        Ask first, it proposes the change and waits for your OK.
                    </p>
                </div>
            </div>
            <div className="settings-tiles">
                {ASK_FIRST.map((s) => (
                    <div key={s.key} className="settings-tile">
                        <span className="settings-tile-copy">
                            <strong id={`${s.id}-label`}>{s.label}</strong>
                            <span
                                className="settings-tile-note"
                                id={`${s.id}-state`}
                            >
                                {on[s.key]
                                    ? 'On: Composer proposes the change and waits for your OK'
                                    : s.off}
                            </span>
                            {s.always ? (
                                <span className="settings-tile-note">
                                    {s.always}
                                </span>
                            ) : null}
                        </span>
                        <button
                            type="button"
                            className="settings-switch"
                            role="switch"
                            aria-checked={on[s.key]}
                            aria-labelledby={`${s.id}-label`}
                            aria-describedby={`${s.id}-state`}
                            aria-busy={busy === s.key || undefined}
                            onClick={() => void save(s.key, !on[s.key])}
                        />
                    </div>
                ))}
                <PaperworkPermission />
            </div>
            <p className="settings-hint">
                Either way, a change Composer makes can be undone for 24 hours.
            </p>
            {error ? (
                <p className="settings-error" role="alert">
                    {error}
                </p>
            ) : null}
        </section>
    );
}

/**
 * Transit & tickets: the Deutschlandticket (the profile, as Travel's own
 * switch sets it) and location sharing for departures (/user-settings).
 * Turning sharing off deletes the locations kept (LocationDataRetention::
 * purgeUser), so it asks first, once; turning it on is one tap.
 */
export function TransitPanel() {
    const { auth, userSettings } = usePage<{
        auth: Auth;
        userSettings?: Record<string, boolean | string>;
    }>().props;
    const [ticket, setTicket] = useState<boolean>(
        Boolean(auth.user.has_deutschlandticket),
    );
    const [share, setShare] = useState<boolean>(
        (userSettings?.share_location as boolean | undefined) ?? true,
    );
    const [busy, setBusy] = useState<'ticket' | 'location' | null>(null);
    const [error, setError] = useState('');
    const [asking, setAsking] = useState(false);
    const [erased, setErased] = useState(false);

    const keepSharing = () => {
        setAsking(false);
        focusSoon('[data-share-location]');
    };

    async function save(kind: 'ticket' | 'location') {
        if (busy) {
            return;
        }

        setBusy(kind);
        setError('');

        try {
            if (kind === 'ticket') {
                await writeJson(ProfileController.update.url(), 'PATCH', {
                    has_deutschlandticket: !ticket,
                });
                setTicket(!ticket);
            } else {
                await writeJson('/user-settings', 'PUT', {
                    settings: { share_location: !share },
                });
                setErased(share);
                setShare(!share);
                setAsking(false);
                focusSoon('[data-share-location]');
            }

            router.reload({ only: ['userSettings', 'auth'] });
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            setBusy(null);
        }
    }

    const rows = [
        {
            id: 'ticket',
            label: 'I have a Deutschlandticket',
            note: 'Shows trips as covered instead of a single fare',
            on: ticket,
        },
        {
            id: 'location',
            label: 'Share location for transit',
            note:
                erased && !share
                    ? 'Off. Kept locations are deleted.'
                    : 'Improves departure-time accuracy near you',
            on: share,
        },
    ] as const;

    return (
        <section className="settings-panel" aria-labelledby="transit-title">
            <div className="settings-head">
                <span className="settings-chip transit">
                    <SettingsIcon name="train" />
                </span>
                <div>
                    <h2 id="transit-title">Transit &amp; tickets</h2>
                    <p>
                        Your ticket and location, so fares and departure times
                        show up right.
                    </p>
                </div>
            </div>
            <div className="settings-tiles">
                {rows.map((row) => (
                    <div key={row.id} className="settings-tile">
                        <span className="settings-tile-copy">
                            <strong id={`${row.id}-label`}>{row.label}</strong>
                            <span
                                className="settings-tile-note"
                                id={`${row.id}-note`}
                            >
                                {row.note}
                            </span>
                        </span>
                        <button
                            type="button"
                            className="settings-switch"
                            role="switch"
                            aria-checked={row.on}
                            aria-labelledby={`${row.id}-label`}
                            aria-describedby={`${row.id}-note`}
                            aria-busy={busy === row.id || undefined}
                            data-share-location={
                                row.id === 'location' || undefined
                            }
                            onClick={() => {
                                if (row.id === 'location' && share) {
                                    setAsking(true);
                                    focusSoon('[data-keep-sharing]');
                                } else {
                                    void save(row.id);
                                }
                            }}
                        />
                    </div>
                ))}
            </div>
            {asking ? (
                <div
                    className="settings-confirm"
                    role="group"
                    aria-labelledby="stop-sharing-title"
                    onKeyDown={(event) =>
                        event.key === 'Escape' && keepSharing()
                    }
                >
                    <strong id="stop-sharing-title">
                        Stop sharing and delete kept locations?
                    </strong>
                    <p>
                        The locations Expadu kept to find stops near you (the
                        last few from this device and the one you confirmed) are
                        deleted, and can’t be brought back.
                    </p>
                    <div className="settings-actions">
                        <button
                            type="button"
                            className="settings-quiet"
                            data-keep-sharing
                            onClick={keepSharing}
                        >
                            Keep sharing
                        </button>
                        <button
                            type="button"
                            className="settings-primary"
                            aria-busy={busy === 'location' || undefined}
                            onClick={() => void save('location')}
                        >
                            Stop and delete
                        </button>
                    </div>
                </div>
            ) : null}
            <p className="settings-hint">
                Travel shows the same Deutschlandticket switch where you plan a
                trip.
            </p>
            {error ? (
                <p className="settings-error" role="alert">
                    {error}
                </p>
            ) : null}
        </section>
    );
}

type ExportState = {
    id: string;
    status: 'queued' | 'ready' | 'failed';
    bytes: number | null;
    expires_at: string | null;
    url: string | null;
} | null;

const megabytes = (bytes: number | null) =>
    bytes === null
        ? ''
        : bytes < 1_000_000
          ? `${Math.max(1, Math.round(bytes / 1000))} KB`
          : `${(bytes / 1_000_000).toFixed(1)} MB`;

const until = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('en-GB', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
              timeZone: 'Europe/Berlin',
          })
        : '';

/**
 * Your data: one ZIP of everything Expadu keeps about you (a JSON file per
 * area and a README naming each). Asking confirms it's you as deleting the
 * account does; the file is built in the background and can be downloaded
 * for 24 hours, then it's deleted.
 */
export function DataPanel() {
    const { user, has_password: hasPassword } = usePageAuth();
    const [exportState, setExportState] = useState<ExportState>(null);
    const [asking, setAsking] = useState(false);
    const [codeSent, setCodeSent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const queued = exportState?.status === 'queued';

    // The latest request, and while one is being built, its progress.
    useEffect(() => {
        let live = true;
        const read = () =>
            fetch(AccountExportController.show.url(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((body) => live && body && setExportState(body.export))
                .catch(() => {});

        read();
        const timer = queued ? window.setInterval(read, 3000) : undefined;

        return () => {
            live = false;
            window.clearInterval(timer);
        };
    }, [queued]);

    const keep = () => {
        setAsking(false);
        setCodeSent(false);
        setErrors({});
        focusSoon('[data-export]');
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
                    focusSoon('#export_code');
                },
                onError: (e) => setErrors(e),
            },
        );
    }

    async function ask(secret: string) {
        setBusy(true);
        setErrors({});

        try {
            const response = await fetch(AccountExportController.store.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify(
                    hasPassword ? { password: secret } : { code: secret },
                ),
            });
            const body = await response.json().catch(() => ({}));

            if (response.status === 422) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(
                            (body.errors ?? {}) as Record<string, string[]>,
                        ).map(([key, list]) => [key, list[0]]),
                    ),
                );
                focusSoon('#export_code, #export_password');

                return;
            }

            if (!response.ok) {
                throw new Error();
            }

            setExportState(body.export);
            setAsking(false);
            setCodeSent(false);
        } catch {
            setErrors({
                export: 'We couldn’t start that. Check your connection and try again.',
            });
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="settings-panel" aria-labelledby="data-title">
            <div className="settings-head">
                <span className="settings-chip data">
                    <SettingsIcon name="lock" />
                </span>
                <div>
                    <h2 id="data-title">Your data</h2>
                    <p>
                        Download everything Expadu keeps about you, in one file.
                    </p>
                </div>
            </div>
            {asking ? (
                <form
                    className="settings-confirm is-calm"
                    data-form="export"
                    role="group"
                    aria-label="Download your data"
                    noValidate
                    onKeyDown={(event) => event.key === 'Escape' && keep()}
                    onSubmit={(event) => {
                        event.preventDefault();
                        const secret =
                            event.currentTarget.querySelector<HTMLInputElement>(
                                '#export_code, #export_password',
                            );

                        if (!secret) {
                            focusSoon('[data-export-code]');

                            return;
                        }

                        void ask(secret.value);
                    }}
                >
                    {hasPassword ? (
                        <Field
                            id="export_password"
                            label="Enter your password to confirm"
                            className="short"
                            type="password"
                            autoComplete="current-password"
                            error={errors.password}
                        />
                    ) : codeSent ? (
                        <>
                            <Field
                                id="export_code"
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
                                data-export-code
                                onClick={emailCode}
                            >
                                Send a new code
                            </button>
                        </>
                    ) : (
                        <>
                            <p>
                                To confirm, enter the code we email to{' '}
                                {user.email}.
                            </p>
                            <button
                                type="button"
                                className="settings-quiet"
                                style={{ justifySelf: 'start' }}
                                data-export-code
                                onClick={emailCode}
                            >
                                Email me a code
                            </button>
                            <p className="settings-error" hidden={!errors.code}>
                                {errors.code}
                            </p>
                        </>
                    )}
                    {errors.export ? (
                        <p className="settings-error" role="alert">
                            {errors.export}
                        </p>
                    ) : null}
                    <div className="settings-actions">
                        <button
                            type="button"
                            className="settings-quiet"
                            onClick={keep}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="settings-primary"
                            aria-busy={busy || undefined}
                        >
                            Prepare my file
                        </button>
                    </div>
                </form>
            ) : exportState?.status === 'ready' && exportState.url ? (
                <div className="settings-tile" role="status">
                    <span className="settings-tile-copy">
                        <strong>Your file is ready</strong>
                        <span className="settings-tile-note">
                            {`ZIP, ${megabytes(exportState.bytes)} · until ${until(exportState.expires_at)}`}
                        </span>
                    </span>
                    <a
                        className="settings-primary"
                        href={exportState.url}
                        download
                    >
                        Download
                    </a>
                </div>
            ) : queued ? (
                <div className="settings-tile" aria-busy="true">
                    <span className="settings-tile-copy">
                        <strong>Preparing your file…</strong>
                        <span className="settings-tile-note">
                            This takes a minute. You can leave this page; the
                            file waits here for 24 hours.
                        </span>
                    </span>
                </div>
            ) : (
                <>
                    {exportState?.status === 'failed' ? (
                        <p className="settings-error" role="alert">
                            That file couldn’t be made. Try again.
                        </p>
                    ) : null}
                    <div className="settings-tiles">
                        <div className="settings-tile">
                            <span className="settings-tile-copy">
                                <strong id="export-title">
                                    Download your data
                                </strong>
                                <span className="settings-tile-note">
                                    A ZIP with your profile, settings, plans,
                                    places, Composer conversations and Paperwork
                                    record. It can be downloaded for 24 hours,
                                    then it’s deleted.
                                </span>
                            </span>
                            <button
                                type="button"
                                className="settings-quiet"
                                aria-describedby="export-title"
                                data-export
                                onClick={() => {
                                    setAsking(true);
                                    focusSoon(
                                        hasPassword
                                            ? '#export_password'
                                            : '[data-export-code]',
                                    );
                                }}
                            >
                                Prepare my file
                            </button>
                        </div>
                    </div>
                </>
            )}
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
                <div className="settings-tiles">
                    <div className="settings-tile">
                        <span className="settings-tile-copy">
                            <strong>Delete account</strong>
                            <span className="settings-tile-note">
                                Download your data first if you want a copy.
                            </span>
                        </span>
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
                    </div>
                </div>
            )}
        </section>
    );
}
