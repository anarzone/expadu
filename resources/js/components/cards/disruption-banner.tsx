import { useState } from 'react';
import type { DisruptionBannerData } from '@/types/home-feed';

export function DisruptionBanner({ data }: { data: DisruptionBannerData }) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) {
        return null;
    }

    return (
        <div className="flex items-start gap-2.5 rounded-lg border border-warn/20 bg-warn-soft p-3">
            <span className="mt-px shrink-0 text-sm">⚠️</span>
            <div className="min-w-0 flex-1 text-[13px] leading-relaxed text-[#7C4A00] dark:text-amber-200">
                <strong>{data.title}</strong>
                {' — '}
                {data.description}
            </div>
            <button
                onClick={() => setDismissed(true)}
                className="shrink-0 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                ✕
            </button>
        </div>
    );
}
