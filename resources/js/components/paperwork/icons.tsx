import {
    IconAdjustmentsHorizontal,
    IconArrowLeft,
    IconArrowRight,
    IconBriefcase,
    IconBuildingBank,
    IconCalendar,
    IconCheck,
    IconChevronDown,
    IconClock,
    IconFileText,
    IconHeartbeat,
    IconHome,
    IconId,
    IconInfoCircle,
    IconLayoutGrid,
    IconPencil,
    IconPlus,
    IconReceiptTax,
    IconSchool,
    IconUsers,
    IconX,
} from '@tabler/icons-react';
import type { ComponentType } from 'react';

// The prototype's icon names, drawn with the app's Tabler set.
const icons = {
    sliders: IconAdjustmentsHorizontal,
    back: IconArrowLeft,
    calendar: IconCalendar,
    check: IconCheck,
    down: IconChevronDown,
    arrow: IconArrowRight,
    clock: IconClock,
    document: IconFileText,
    info: IconInfoCircle,
    grid: IconLayoutGrid,
    pencil: IconPencil,
    plus: IconPlus,
    people: IconUsers,
    close: IconX,
} satisfies Record<
    string,
    ComponentType<{ stroke?: number; 'aria-hidden'?: boolean }>
>;

export type IconName = keyof typeof icons;

export function Icon({ name }: { name: IconName }) {
    const Component = icons[name];

    return <Component stroke={1.7} aria-hidden />;
}

// A task's icon and tint come from its topic.
const topics: Record<
    string,
    {
        icon: ComponentType<{ stroke?: number; 'aria-hidden'?: boolean }>;
        tone: string;
    }
> = {
    residence: { icon: IconId, tone: 'blue' },
    address: { icon: IconHome, tone: 'peach' },
    tax: { icon: IconReceiptTax, tone: 'yellow' },
    health: { icon: IconHeartbeat, tone: 'green' },
    money: { icon: IconBuildingBank, tone: 'yellow' },
    family: { icon: IconUsers, tone: 'peach' },
    work: { icon: IconBriefcase, tone: 'blue' },
    education: { icon: IconSchool, tone: 'green' },
};

export function TopicIcon({
    topic,
    tone,
}: {
    topic: string | null | undefined;
    tone?: string;
}) {
    const entry = topics[topic ?? ''] ?? { icon: IconFileText, tone: '' };
    const Component = entry.icon;

    return (
        <span className={`topic-icon ${tone ?? entry.tone}`}>
            <Component stroke={1.7} aria-hidden />
        </span>
    );
}
