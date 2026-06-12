export function WelcomeStep() {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="pt-8 pb-6">
                <div className="mb-4 text-5xl">👋</div>
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
                    emoji="📋"
                    title="Your exact paperwork path"
                    description="Only the tasks that apply to your situation — with every document named."
                />
                <BenefitCard
                    emoji="⏰"
                    title="Deadlines that find you"
                    description="We compute them from your arrival date and remind you before they bite."
                />
                <BenefitCard
                    emoji="🌳"
                    title="A city to enjoy meanwhile"
                    description="Parks, museums and people-meeting events around your Veedel."
                />
            </div>

            <PrivacyNote text="Your answers stay in your profile. They only personalize your checklist — never shared, and you can change them anytime in Settings." />
        </div>
    );
}

function BenefitCard({
    emoji,
    title,
    description,
}: {
    emoji: string;
    title: string;
    description: string;
}) {
    return (
        <div className="flex gap-3.5 rounded-xl border border-border bg-card p-4">
            <span className="text-2xl">{emoji}</span>
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
            <span aria-hidden>🔒</span>
            <span>{text}</span>
        </div>
    );
}
