import { Link, usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import type { MouseEvent } from 'react';
import { ExpaduMark } from '@/components/app-logo-icon';
import { AccountMenu } from '@/components/shell/account-menu';
import { CityPicker } from '@/components/shell/city-picker';
import {
    openComposerTray,
    openComposerView,
    useComposerWaiting,
} from '@/components/shell/composer-tray';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { sectionAgain } from '@/hooks/use-shell-scroll';
import { bureaucracy, composer, dashboard, events, travel } from '@/routes';

const sections: {
    key: string;
    label: string;
    icon: IconName;
    url: string;
}[] = [
    { key: 'today', label: 'Today', icon: 'home', url: dashboard.url() },
    { key: 'events', label: 'Events', icon: 'calendar', url: events.url() },
    {
        key: 'paperwork',
        label: 'Paperwork',
        icon: 'document',
        url: bureaucracy.url(),
    },
    { key: 'travel', label: 'Travel', icon: 'train', url: travel.url() },
];

const within = (path: string, url: string) =>
    path === url || path.startsWith(`${url}/`);

// ⌘K on Apple devices, Ctrl K elsewhere. The server renders Ctrl K and the
// browser corrects it after hydration.
const noChange = () => () => {};
const useMac = () =>
    useSyncExternalStore(
        noChange,
        () => /Mac|iPhone|iPad/.test(navigator.platform || ''),
        () => false,
    );

/**
 * The one header every section shares (the mock's #shell): the logo, the
 * sections as a capsule, and Composer, the city, Saved and the account.
 * On phones the sections float above the page instead, beside Composer's
 * round button (composer-tray.tsx; shell.css).
 */
export function ShellHeader() {
    const { url } = usePage();
    const path = url.split('?')[0];
    const mac = useMac();
    // A conversation kept after closing Composer: a dot on its button (B).
    const kept = useComposerWaiting();
    // On Composer its button says you are here (the mock's selected tint).
    const onComposer = within(path, composer.url());
    const shortcut = mac ? '⌘K' : 'Ctrl K';

    const again = (event: MouseEvent) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey) {
            return;
        }

        if (sectionAgain()) {
            event.preventDefault();
        }
    };

    return (
        <div className="shell">
            <header className="shell-header">
                <Link
                    href={dashboard()}
                    className="shell-brand"
                    aria-label="Expadu home"
                >
                    <ExpaduMark />
                    <span>Expadu</span>
                </Link>
                <nav className="shell-nav" aria-label="Main navigation">
                    {sections.map((section) => {
                        const current = within(path, section.url);

                        return (
                            <Link
                                key={section.key}
                                href={section.url}
                                data-section={section.key}
                                aria-current={current ? 'page' : undefined}
                                onClick={current ? again : undefined}
                            >
                                <Icon name={section.icon} />
                                <span>{section.label}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="shell-utilities">
                    <button
                        type="button"
                        className={
                            kept
                                ? 'shell-composer has-conversation'
                                : 'shell-composer'
                        }
                        aria-current={onComposer ? 'page' : undefined}
                        aria-label={
                            onComposer
                                ? 'Composer, where you are. Write your request'
                                : 'Ask Composer'
                        }
                        aria-keyshortcuts={mac ? 'Meta+K' : 'Control+K'}
                        title={`${onComposer ? 'Composer' : 'Ask Composer'} (${shortcut})`}
                        data-composer-opener
                        onClick={openComposerTray}
                    >
                        <span className="shell-composer-mark">
                            <Icon name="spark" variant="line" />
                        </span>
                        <span className="shell-composer-text">
                            Ask Composer
                        </span>
                        <kbd>{shortcut}</kbd>
                    </button>
                    <CityPicker />
                    <button
                        type="button"
                        className="shell-icon-button shell-saved"
                        aria-label="Saved"
                        title="Saved"
                        data-composer-opener
                        onClick={() => openComposerView('saved')}
                    >
                        <Icon name="bookmark" />
                    </button>
                    <AccountMenu />
                </div>
            </header>
        </div>
    );
}
