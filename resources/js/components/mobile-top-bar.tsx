import { router, usePage } from '@inertiajs/react';
import {
    IconArrowLeft,
    IconUser,
    IconSettings,
    IconLogout,
} from '@tabler/icons-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { ICON_STROKE } from '@/constants/icons';

export function MobileTopBar({
    title,
    showBack,
}: {
    title?: string;
    showBack?: boolean;
}) {
    const { auth } = usePage().props as any;
    const [menuOpen, setMenuOpen] = useState(false);

    const initials = auth?.user?.name
        ? auth.user.name
              .split(' ')
              .map((n: string) => n[0])
              .join('')
              .toUpperCase()
              .slice(0, 2)
        : '?';

    return (
        <div className="relative md:hidden">
            <header className="flex items-center justify-between border-b border-border bg-background px-5 py-[11px] shadow-[0_1px_2px_rgba(0,0,0,0.06)] dark:shadow-[0_1px_2px_rgba(0,0,0,0.3)]">
                <div className="flex items-center gap-2.5">
                    {showBack ? (
                        <button
                            onClick={() => window.history.back()}
                            className="flex items-center gap-1.5 text-primary"
                        >
                            <IconArrowLeft size={22} stroke={ICON_STROKE} />
                        </button>
                    ) : (
                        <a
                            href="/dashboard"
                            className="flex items-center gap-2.5"
                        >
                            <AppLogoIcon className="!size-7 !rounded-lg !text-sm" />
                            <span className="font-display text-[21px] font-medium tracking-tight">
                                <span className="text-primary">E</span>xpadu
                            </span>
                        </a>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {title && (
                        <span className="text-sm font-semibold text-primary">
                            {title}
                        </span>
                    )}
                    <button
                        onClick={() => setMenuOpen(!menuOpen)}
                        className="flex size-8 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground"
                    >
                        {initials}
                    </button>
                </div>
            </header>

            {/* Dropdown menu */}
            {menuOpen && (
                <>
                    <div
                        className="fixed inset-0 z-[9998] md:hidden"
                        onClick={() => setMenuOpen(false)}
                    />
                    <div className="absolute right-4 z-[9999] mt-1 w-56 overflow-hidden rounded-[12px] border border-border bg-card shadow-[0_8px_32px_rgba(0,0,0,0.12)] md:hidden">
                        {/* User info */}
                        <div className="border-b border-border px-4 py-3">
                            <div className="text-[13px] font-semibold">
                                {auth?.user?.name}
                            </div>
                            <div className="text-[11px] text-text-3">
                                {auth?.user?.email}
                            </div>
                        </div>

                        {/* Menu items */}
                        <a
                            href="/profile"
                            onClick={() => setMenuOpen(false)}
                            className="flex w-full items-center gap-2.5 border-b border-border px-4 py-3 text-left transition-colors hover:bg-secondary"
                        >
                            <IconUser
                                size={16}
                                stroke={ICON_STROKE}
                                className="text-text-2"
                            />
                            <span className="text-[13px] font-medium">
                                Profile
                            </span>
                        </a>
                        <a
                            href="/settings/profile"
                            onClick={() => setMenuOpen(false)}
                            className="flex w-full items-center gap-2.5 border-b border-border px-4 py-3 text-left transition-colors hover:bg-secondary"
                        >
                            <IconSettings
                                size={16}
                                stroke={ICON_STROKE}
                                className="text-text-2"
                            />
                            <span className="text-[13px] font-medium">
                                Settings
                            </span>
                        </a>
                        <button
                            onClick={() => {
                                setMenuOpen(false);
                                router.post('/logout');
                            }}
                            className="flex w-full items-center gap-2.5 px-4 py-3 text-left transition-colors hover:bg-danger-soft"
                        >
                            <IconLogout
                                size={16}
                                stroke={ICON_STROKE}
                                className="text-danger"
                            />
                            <span className="text-[13px] font-medium text-danger">
                                Log out
                            </span>
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
