import { Icon } from '@/components/ui/icon';

/** Marks what an AI wrote or did (AI Act Art. 50; the consent canvas's boards 3 and 4). */
export function AiBadge({ label = 'AI' }: { label?: string }) {
    return (
        <span className="ui-ai-badge">
            <Icon name="spark-small" variant="line" />
            {label}
        </span>
    );
}
