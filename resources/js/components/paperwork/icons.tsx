import { Icon as SharedIcon } from '@/components/ui/icon';
import type { LineIconName } from '@/components/ui/icon';

// Paperwork draws the mock's line set (connected/icons.js), as its page does.
export type IconName = LineIconName;

export function Icon({ name }: { name: IconName }) {
    return <SharedIcon name={name} variant="line" />;
}

// A task's icon comes from its topic (bureaucracy-next/plan.js); every
// Paperwork icon is lavender with a violet glyph, whatever its tone.
const topics: Record<string, { icon: IconName; tone: string }> = {
    residence: { icon: 'document', tone: '' },
    address: { icon: 'pin', tone: 'peach' },
    tax: { icon: 'document', tone: 'yellow' },
    health: { icon: 'health', tone: 'green' },
    money: { icon: 'bank', tone: 'blue' },
    family: { icon: 'people', tone: 'violet' },
    work: { icon: 'briefcase', tone: 'blue' },
    education: { icon: 'book', tone: 'green' },
};

export function TopicIcon({
    topic,
    tone,
}: {
    topic: string | null | undefined;
    tone?: string;
}) {
    const entry = topics[topic ?? ''] ?? { icon: 'document', tone: '' };

    return (
        <span className={`topic-icon ${tone ?? entry.tone}`}>
            <Icon name={entry.icon} />
        </span>
    );
}
