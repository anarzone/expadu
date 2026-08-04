import {
    IconClipboardList,
    IconClockHour4,
    IconHandStop,
    IconLock,
    IconTree,
} from '@tabler/icons-react';
import type { TablerIcon } from '@tabler/icons-react';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';

export function WelcomeStep() {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="pt-8 pb-6">
                <div className="mb-4">
                    <OnboardingIcon icon={IconHandStop} size="xl" />
                </div>
                <h1 className="mb-2.5 font-display text-[28px] leading-tight font-medium">
                    Moving to Cologne is a lot.
                    <br />
                    Let's make it a list.
                </h1>
                <p className="max-w-[400px] text-[15px] leading-relaxed text-muted-foreground">
                    Four quick questions and Expadu builds your personal plan —
                    the offices, the documents, the deadlines. Nothing generic.
                </p>
            </div>

            <div className="flex flex-col gap-2.5">
                <BenefitCard
                    icon={IconClipboardList}
                    title="Your exact paperwork path"
                    description="Only the tasks that apply to your situation — with every document named."
                />
                <BenefitCard
                    icon={IconClockHour4}
                    title="Deadlines that find you"
                    description="We compute them from your arrival date and remind you before they bite."
                />
                <BenefitCard
                    icon={IconTree}
                    title="A city to enjoy meanwhile"
                    description="Parks, museums and people-meeting events around your Veedel."
                />
            </div>

            <PrivacyNote text="Your answers are stored in your Expadu profile to personalise your plan. They are never sold. Expadu is not legal advice." />
        </div>
    );
}

function BenefitCard({
    icon: Icon,
    title,
    description,
}: {
    icon: TablerIcon;
    title: string;
    description: string;
}) {
    return (
        <div className="flex gap-3.5 rounded-xl border border-border bg-card p-4">
            <OnboardingIcon icon={Icon} size="lg" />
            <div>
                <div className="text-sm font-semibold">{title}</div>
                <div className="mt-0.5 text-[13px] text-muted-foreground">
                    {description}
                </div>
            </div>
        </div>
    );
}

export function PrivacyNote({ text }: { text: string }) {
    return (
        <div className="mt-5 flex items-start gap-2 rounded-[10px] bg-accent-soft px-3.5 py-3 text-[12.5px] leading-normal font-medium text-primary">
            <OnboardingIcon
                icon={IconLock}
                size="sm"
                className="mt-0.5 shrink-0"
            />
            <span>{text}</span>
        </div>
    );
}
