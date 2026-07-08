import {
    IconCalendarEvent,
    IconFileText,
    IconSettings,
} from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';

/** The Quick-actions links shown in the profile right rail. */
const ACTIONS = [
    {
        href: '/events',
        Icon: IconCalendarEvent,
        title: 'My events',
        sub: 'Browse and manage events',
    },
    {
        href: '/bureaucracy',
        Icon: IconFileText,
        title: 'Bureaucracy',
        sub: 'Your settling-in checklist',
    },
    {
        href: '/settings/profile',
        Icon: IconSettings,
        title: 'Settings',
        sub: 'Profile, notifications, privacy',
    },
];

export function ProfileRightPanel() {
    return (
        <>
            {/* Quick actions */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3 dark:border-[#3A3930]">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Quick actions
                    </span>
                </div>
                {ACTIONS.map(({ href, Icon, title, sub }, i) => (
                    <a
                        key={href}
                        href={href}
                        className={`flex cursor-pointer items-start gap-2.5 px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920] ${
                            i < ACTIONS.length - 1
                                ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]'
                                : ''
                        }`}
                    >
                        <Icon
                            size={17}
                            stroke={ICON_STROKE}
                            className="mt-px shrink-0 text-muted-foreground"
                        />
                        <div className="min-w-0 flex-1">
                            <div
                                style={{
                                    fontSize: 12,
                                    fontWeight: 600,
                                    marginBottom: 1,
                                }}
                            >
                                {title}
                            </div>
                            <div
                                className="text-[#6B6860] dark:text-[#AAA89F]"
                                style={{ fontSize: 11 }}
                            >
                                {sub}
                            </div>
                        </div>
                    </a>
                ))}
            </div>
        </>
    );
}
