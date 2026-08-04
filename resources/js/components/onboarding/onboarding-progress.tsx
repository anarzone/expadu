import { IconChevronLeft } from '@tabler/icons-react';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';

export function OnboardingProgress({
    step,
    total,
    onBack,
}: {
    step: number;
    total: number;
    onBack: () => void;
}) {
    const progress = (step / total) * 100;

    return (
        <div className="sticky top-0 z-10 bg-background px-6 pt-5">
            <div className="mx-auto flex max-w-[600px] items-center gap-3">
                <button
                    type="button"
                    onClick={onBack}
                    disabled={step <= 1}
                    aria-label="Go back"
                    className="flex size-9 items-center justify-center rounded-full border border-border bg-card text-muted-foreground transition-colors hover:bg-secondary disabled:opacity-30"
                >
                    <OnboardingIcon icon={IconChevronLeft} size="md" />
                </button>
                <div
                    className="flex-1 overflow-hidden rounded-sm bg-border"
                    style={{ height: 4 }}
                >
                    <div
                        className="h-full rounded-sm bg-primary transition-all duration-400"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                <span className="shrink-0 text-[11px] font-semibold text-muted-foreground">
                    {step} of {total}
                </span>
            </div>
        </div>
    );
}
