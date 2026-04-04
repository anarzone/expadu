import { usePage } from '@inertiajs/react';

export function ServiceErrorBanner() {
    const { serviceErrors } = usePage<{
        serviceErrors?: string[];
    }>().props;

    if (!serviceErrors || serviceErrors.length === 0) {
        return null;
    }

    return (
        <div className="border-b border-[#C4271A]/20 bg-[#FDE8E6] px-4 py-2.5 dark:border-[#C4271A]/30 dark:bg-[#C4271A]/10">
            {serviceErrors.map((err, i) => (
                <div
                    key={i}
                    className="flex items-center gap-2 text-[12px] text-[#C4271A]"
                >
                    <span className="shrink-0">⚠</span>
                    <span>{err}</span>
                </div>
            ))}
        </div>
    );
}
