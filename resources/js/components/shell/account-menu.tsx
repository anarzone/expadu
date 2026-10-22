import { Link, router, usePage } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { clearPrivateBrowserData } from '@/lib/private-browser-data';
import { alerts, bureaucracy, logout } from '@/routes';
import { edit } from '@/routes/profile';

/**
 * The account popover at the header's end (the mock's header-account
 * popover): who is signed in, the account's own pages and Sign out. Alerts
 * lives here until the design session says where it goes (build plan call
 * #6).
 */
export function AccountMenu() {
    const { auth, unreadAlertCount } = usePage().props;
    const user = auth.user;
    const unread = Number(unreadAlertCount) || 0;

    return (
        <Menu.Root modal={false}>
            <Menu.Trigger asChild>
                <button
                    type="button"
                    className="shell-icon-button shell-account"
                    aria-label="Your account"
                    title="Your account"
                >
                    {user.avatar ? (
                        <img src={user.avatar} alt="" />
                    ) : (
                        <span aria-hidden="true">
                            {user.name.trim().charAt(0).toUpperCase()}
                        </span>
                    )}
                </button>
            </Menu.Trigger>
            <Menu.Portal>
                <Menu.Content
                    align="end"
                    sideOffset={8}
                    collisionPadding={12}
                    className="shell-account-menu z-50"
                    aria-labelledby="shell-account-title"
                >
                    <h2 id="shell-account-title">Your account</h2>
                    <p>{user.name}</p>
                    <p>{user.email}</p>
                    <div className="shell-account-links">
                        <Menu.Item asChild>
                            {/* Paperwork's Your situation (?guide=details opens it). */}
                            <Link
                                href={bureaucracy({
                                    query: { guide: 'details' },
                                })}
                            >
                                Your situation
                            </Link>
                        </Menu.Item>
                        <Menu.Item asChild>
                            <Link href={edit()}>Settings</Link>
                        </Menu.Item>
                        <Menu.Item asChild>
                            <Link href={alerts()}>
                                Alerts
                                {unread > 0 && (
                                    <b aria-label={`${unread} unread`}>
                                        {unread}
                                    </b>
                                )}
                            </Link>
                        </Menu.Item>
                    </div>
                    <div className="shell-account-out">
                        <Menu.Item asChild>
                            <Link
                                href={logout()}
                                as="button"
                                data-test="logout-button"
                                onClick={() => router.flushAll()}
                                onSuccess={() => {
                                    router.clearHistory();
                                    void clearPrivateBrowserData();
                                }}
                            >
                                Sign out
                            </Link>
                        </Menu.Item>
                    </div>
                </Menu.Content>
            </Menu.Portal>
        </Menu.Root>
    );
}
