import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { IconUser, IconPalette, IconLogout } from '@tabler/icons-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { ICON_STROKE } from '@/constants/icons';

export function MobileTopBar({ title }: { title?: string }) {
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
        <>
            <header className="fixed top-0 right-0 left-0 z-50 flex items-center justify-between border-b border-border bg-background/96 px-5 py-[11px] backdrop-blur-2xl md:hidden">
                <div className="flex items-center gap-2.5">
                    <AppLogoIcon className="!size-7 !rounded-lg !text-sm" />
                    <span className="font-display text-[21px] font-medium tracking-tight">
                        <span className="text-primary">E</span>xpadu
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    {title && (
                        <span className="text-sm font-semibold text-primary">
                            {title}
                        </span>
                    )}
                    <button
                        onClick={() => setMenuOpen(!menuOpen)}
                        className="flex size-8 items-center justify-center rounded-full bg-[#1A4CD4] text-[11px] font-bold text-white"
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
                    <div className="fixed top-[52px] right-4 z-[9999] w-56 overflow-hidden rounded-[12px] border border-[#E2DFD6] bg-white shadow-[0_8px_32px_rgba(0,0,0,0.12)] md:hidden dark:border-[#3A3930] dark:bg-[#1E1D15]">
                        {/* User info */}
                        <div className="border-b border-[#E2DFD6] px-4 py-3 dark:border-[#3A3930]">
                            <div style={{ fontSize: 13, fontWeight: 600 }}>
                                {auth?.user?.name}
                            </div>
                            <div style={{ fontSize: 11, color: '#AAA89F' }}>
                                {auth?.user?.email}
                            </div>
                        </div>

                        {/* Menu items */}
                        <a
                            href="/profile"
                            onClick={() => setMenuOpen(false)}
                            className="flex w-full items-center gap-2.5 border-b border-[#E2DFD6] px-4 py-3 text-left transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                        >
                            <IconUser
                                size={16}
                                stroke={ICON_STROKE}
                                className="text-[#6B6860]"
                            />
                            <span style={{ fontSize: 13, fontWeight: 500 }}>
                                Profile
                            </span>
                        </a>
                        <a
                            href="/settings/appearance"
                            onClick={() => setMenuOpen(false)}
                            className="flex w-full items-center gap-2.5 border-b border-[#E2DFD6] px-4 py-3 text-left transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                        >
                            <IconPalette
                                size={16}
                                stroke={ICON_STROKE}
                                className="text-[#6B6860]"
                            />
                            <span style={{ fontSize: 13, fontWeight: 500 }}>
                                Appearance
                            </span>
                        </a>
                        <button
                            onClick={() => {
                                setMenuOpen(false);
                                router.post('/logout');
                            }}
                            className="flex w-full items-center gap-2.5 px-4 py-3 text-left transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920]"
                        >
                            <IconLogout
                                size={16}
                                stroke={ICON_STROKE}
                                className="text-[#C4271A]"
                            />
                            <span
                                style={{
                                    fontSize: 13,
                                    fontWeight: 500,
                                    color: '#C4271A',
                                }}
                            >
                                Log out
                            </span>
                        </button>
                    </div>
                </>
            )}
        </>
    );
}
