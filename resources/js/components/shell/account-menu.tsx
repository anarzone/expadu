import { Link, router, usePage } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { useState } from 'react';
import StepFreeNeedController from '@/actions/App/Http/Controllers/Settings/StepFreeNeedController';
import { showToast } from '@/components/ui/toast';
import { clearPrivateBrowserData } from '@/lib/private-browser-data';
import { writeJson } from '@/lib/reliable-write';
import { alerts, bureaucracy, logout, settings } from '@/routes';

/**
 * The account popover at the header's end (the mock's header-account
 * popover): who is signed in, their Travel needs, the account's own pages
 * and Sign out. Alerts lives here until the design session says where it
 * goes (build plan call #6).
 */
export function AccountMenu() {
    const { auth, unreadAlertCount, needs } = usePage().props;
    // A response without the shared props (no `auth`) crashed the preview's
    // SSR (26 Oct 2026): with nobody signed in there's no menu to show.
    const user = auth?.user;
    const unread = Number(unreadAlertCount) || 0;
    // Shown at once; put back if the save fails.
    const [stepFree, setStepFree] = useState<boolean | null>(null);
    const needsStepFree = stepFree ?? needs?.step_free ?? false;

    // Step-free is a saved need (owner, 21 Oct 2026; the mock's
    // connected/needs.js): Plan a trip starts with Step-free on, and a lift
    // out makes the next trip "No step-free way". One writer for it,
    // SetStepFreeNeed, which Composer's set_needs uses too.
    const saveStepFree = (next: boolean) => {
        setStepFree(next);
        writeJson(StepFreeNeedController.url(), 'PUT', { step_free: next })
            .then(() =>
                // An open Travel page plans with it from now on.
                router.reload({ only: ['needs', 'stepFreeNeed', 'day'] }),
            )
            .catch((error: Error) => {
                setStepFree(!next);
                showToast(error.message, { error: true });
            });
    };

    if (!user) {
        return null;
    }

    return (
        <Menu.Root modal={false}>
            <Menu.Trigger asChild>
                <button
                    type="button"
                    className="shell-icon-button shell-account"
                    aria-label="Your account"
                    title="Your account"
                >
                    {/* The initial: no picture is kept, and none is loaded from a sign-in service. */}
                    <span aria-hidden="true">
                        {user.name.trim().charAt(0).toUpperCase()}
                    </span>
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
                    <section
                        className="shell-account-needs"
                        aria-labelledby="shell-account-needs-title"
                    >
                        <h3 id="shell-account-needs-title">Travel needs</h3>
                        <Menu.CheckboxItem
                            className="shell-account-need"
                            checked={needsStepFree}
                            // The menu stays open: it's a setting, not a link.
                            onSelect={(event) => event.preventDefault()}
                            onCheckedChange={(on) => saveStepFree(on === true)}
                        >
                            <span>
                                <strong>I need step-free ways</strong>
                                <small>
                                    Lifts, no stairs. Travel plans your trips
                                    with it.
                                </small>
                            </span>
                            <i aria-hidden="true" />
                        </Menu.CheckboxItem>
                    </section>
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
                            <Link href={settings()}>Settings</Link>
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
