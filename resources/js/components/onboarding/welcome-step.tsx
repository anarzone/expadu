export function WelcomeStep() {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-8 text-center">
                <div className="mb-4 text-6xl">🌍</div>
                <h1 className="mb-2.5 font-display text-[32px] leading-tight font-medium">
                    Welcome to Expadu
                </h1>
                <p className="mx-auto max-w-[300px] text-[15px] leading-relaxed text-muted-foreground">
                    Your complete companion for expat life in Germany. Let's
                    personalise your experience in 2 minutes.
                </p>
            </div>

            <div className="flex flex-col gap-3">
                <BenefitCard
                    emoji="🗺️"
                    title="Navigate bureaucracy"
                    description="Personalised checklists and appointment booking"
                />
                <BenefitCard
                    emoji="🗣️"
                    title="Learn German faster"
                    description="Language exchange partners matched to your level"
                />
                <BenefitCard
                    emoji="🏘️"
                    title="Find your city"
                    description="Events, work spots, and expat community nearby"
                />
            </div>
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
