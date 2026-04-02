import type { OnboardingData } from '@/pages/onboarding';

const situationLabels: Record<string, string> = {
    non_eu_employee: 'Non-EU employee',
    eu_employee: 'EU employee',
    student: 'Student',
    freelancer: 'Freelancer',
    family_reunification: 'Family reunification',
    digital_nomad: 'Digital nomad',
    other: 'Other',
};

const germanLabels: Record<string, string> = {
    none: 'None yet',
    a1: 'A1',
    a2: 'A2',
    b1: 'B1',
    b2: 'B2',
    c1: 'C1',
    c2: 'C2',
};

const cityLabels: Record<string, string> = {
    cologne: 'Cologne',
    berlin: 'Berlin',
    munich: 'Munich',
    hamburg: 'Hamburg',
    frankfurt: 'Frankfurt',
    other: 'Other city',
};

export function ConfirmationStep({ data }: { data: OnboardingData }) {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-10 text-center">
                <div className="mb-4 text-6xl">🎉</div>
                <h2 className="mb-2.5 font-display text-[28px] font-medium">
                    You're all set!
                </h2>
                <p className="mx-auto max-w-[280px] text-[15px] leading-relaxed text-muted-foreground">
                    Expadu is personalised for you. We've set up your checklist
                    and found language partners nearby.
                </p>
            </div>

            <div className="flex flex-col gap-2.5">
                <SummaryRow
                    emoji="✅"
                    text={`Personalised checklist ready — ${situationLabels[data.situation] || 'Not set'}`}
                />
                <SummaryRow
                    emoji="🗣️"
                    text={`Language partners matched for ${germanLabels[data.german_level] || 'Not set'} German`}
                />
                <SummaryRow
                    emoji="📍"
                    text={`Local content set to ${cityLabels[data.city] || 'Not set'}`}
                />
            </div>
        </div>
    );
}

function SummaryRow({ emoji, text }: { emoji: string; text: string }) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-border bg-card p-3.5">
            <span className="text-xl">{emoji}</span>
            <span className="flex-1 text-[13px] text-muted-foreground">
                {text}
            </span>
        </div>
    );
}
