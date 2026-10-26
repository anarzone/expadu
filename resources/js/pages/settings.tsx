import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    NotificationsPanel,
    notificationsLine,
} from '@/components/settings-page/notifications-panel';
import type { NotificationState } from '@/components/settings-page/notifications-panel';
import {
    AccountPanel,
    CityPanel,
    ComposerPanel,
    DataPanel,
    DeletePanel,
    LearnPanel,
    TransitPanel,
} from '@/components/settings-page/panels';
import type { EditKey } from '@/components/settings-page/panels';
import { SettingsIcon } from '@/components/settings-page/parts';
import type { IconName } from '@/components/ui/icon';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import AppLayout from '@/layouts/app-layout';
import { clearPrivateBrowserData } from '@/lib/private-browser-data';
import { logout } from '@/routes';
import type { Auth } from '@/types';
import '../../css/settings.css';

/**
 * Settings (owner, 26 Oct 2026: direction A, the UI/UX Designer's canvas):
 * one page, every section at /settings/{section}. From 1000px a side menu
 * lists them all with where each stands, and the section opens beside it
 * (Your account at /settings); on a phone /settings is that list and a
 * section opens on its own under "Settings". Each value is what the props
 * say, and follows each save.
 */

type SectionId =
    | 'account'
    | 'city'
    | 'suggestions'
    | 'composer'
    | 'notifications'
    | 'transit'
    | 'data'
    | 'delete';

const GROUPS: { label: string; ids: SectionId[] }[] = [
    { label: 'You', ids: ['account', 'city'] },
    {
        label: 'Expadu for you',
        ids: ['suggestions', 'composer', 'notifications', 'transit'],
    },
    { label: 'Your data', ids: ['data', 'delete'] },
];

const SECTIONS: Record<
    SectionId,
    { label: string; icon: IconName; tone: string }
> = {
    account: { label: 'Your account', icon: 'user', tone: 'account' },
    city: { label: 'City', icon: 'pin-narrow', tone: 'place' },
    suggestions: {
        label: 'Personalised suggestions',
        icon: 'spark-small',
        tone: 'learn',
    },
    composer: { label: 'Composer', icon: 'spark-small', tone: 'composer' },
    notifications: { label: 'Notifications', icon: 'bell', tone: 'notify' },
    transit: { label: 'Transit & tickets', icon: 'train', tone: 'transit' },
    data: { label: 'Your data', icon: 'lock', tone: 'data' },
    delete: { label: 'Delete account', icon: 'trash', tone: 'delete' },
};

/** The city names Paperwork reads as Cologne (as CityPanel does). */
const isCologne = (city: string) => /^(cologne|köln|koeln)$/i.test(city.trim());

export default function Settings() {
    const { section, notifications, work, todayRank, auth, userSettings } =
        usePage<{
            section: SectionId | null;
            notifications: NotificationState;
            work: { leave_at: string | null } | null;
            todayRank: boolean;
            auth: Auth;
            userSettings?: Record<string, boolean | string>;
        }>().props;
    const current: SectionId = section ?? 'account';
    // One tile open at a time, kept to its section: another section opens
    // with none open.
    const [open, setOpen] = useState<{ in: SectionId; key: EditKey }>({
        in: current,
        key: null,
    });
    const edit = open.in === current ? open.key : null;
    const setEdit = (key: EditKey) => setOpen({ in: current, key });
    const push = usePushSubscription();

    const user = auth.user;
    const google = user.social_provider === 'google' && !auth.has_password;
    const city = String(user.city ?? '').trim();
    const everyday = Boolean(
        userSettings?.composer_ask_first_everyday ?? false,
    );
    const paperwork = Boolean(
        userSettings?.composer_ask_first_paperwork ?? true,
    );
    const values: Record<SectionId, string> = {
        account: `${user.name} · ${google ? 'Google' : 'email and password'}`,
        city: city === '' ? 'Not set yet' : isCologne(city) ? 'Cologne' : city,
        suggestions: !userSettings?.personalisation
            ? 'Off'
            : userSettings.personalisation_current === false
              ? 'On · places and events only'
              : todayRank
                ? 'On · AI puts Today in order'
                : 'On',
        composer:
            everyday && paperwork
                ? 'Asks first for everything'
                : paperwork
                  ? 'Asks first for Paperwork'
                  : everyday
                    ? 'Asks first for everyday changes'
                    : 'Makes changes, with Undo',
        notifications: notificationsLine(notifications, push.isSubscribed),
        transit: user.has_deutschlandticket
            ? 'Deutschlandticket'
            : 'No Deutschlandticket',
        data: 'Download a copy',
        delete: 'Asks first',
    };

    const panels: Record<SectionId, ReactNode> = {
        account: <AccountPanel edit={edit} onEdit={setEdit} />,
        city: <CityPanel edit={edit} onEdit={setEdit} />,
        suggestions: <LearnPanel />,
        composer: <ComposerPanel />,
        notifications: (
            <NotificationsPanel state={notifications} work={work} push={push} />
        ),
        transit: <TransitPanel />,
        data: <DataPanel />,
        delete: <DeletePanel />,
    };

    return (
        <AppLayout>
            <Head title={section ? SECTIONS[section].label : 'Settings'} />
            <div
                className="settings-page"
                data-view={section ? 'section' : 'list'}
            >
                <nav className="settings-menu" aria-label="Settings">
                    <h1 className="settings-title">
                        <span className="settings-chip settings">
                            <SettingsIcon name="sliders" />
                        </span>
                        Settings
                    </h1>
                    {GROUPS.map((group) => (
                        <div key={group.label} className="settings-group">
                            <h2 className="settings-group-label">
                                {group.label}
                            </h2>
                            <ul className="settings-items">
                                {group.ids.map((id) => (
                                    <li key={id}>
                                        <Link
                                            href={`/settings/${id}`}
                                            preserveState
                                            preserveScroll
                                            only={['section']}
                                            className="settings-item"
                                            aria-current={
                                                current === id
                                                    ? 'page'
                                                    : undefined
                                            }
                                        >
                                            <span
                                                className={`settings-chip ${SECTIONS[id].tone}`}
                                                aria-hidden="true"
                                            >
                                                <SettingsIcon
                                                    name={SECTIONS[id].icon}
                                                />
                                            </span>
                                            <span className="settings-item-copy">
                                                <strong>
                                                    {SECTIONS[id].label}
                                                </strong>
                                                <span>{values[id]}</span>
                                            </span>
                                            <SettingsIcon
                                                name="next"
                                                className="settings-item-go"
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                    {/* The phone's list ends with Sign out (Your account has it too). */}
                    <Link
                        href={logout()}
                        as="button"
                        className="settings-quiet settings-list-out"
                        onClick={() => router.flushAll()}
                        onSuccess={() => {
                            router.clearHistory();
                            void clearPrivateBrowserData();
                        }}
                    >
                        <SettingsIcon name="sign-out" />
                        Sign out
                    </Link>
                </nav>
                <main className="settings-content">
                    <Link
                        href="/settings"
                        preserveState
                        preserveScroll
                        only={['section']}
                        className="settings-back"
                    >
                        <SettingsIcon name="back" />
                        Settings
                    </Link>
                    <div key={current} className="settings-section">
                        {panels[current]}
                    </div>
                </main>
            </div>
        </AppLayout>
    );
}
