import { IconCheck, IconListCheck, IconLock } from '@tabler/icons-react';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';

/**
 * The cover screen. It used to cost a click and give nothing back: three
 * equal-weight cards, two of which hedged rather than promised ("a practical
 * starting point, with official sources to check before you act" is a
 * disclaimer wearing a benefit's clothes), and an orange privacy box that was
 * the loudest thing on the page while the call to action sat quiet below it.
 *
 * It now answers the two questions someone actually has before starting a form
 * — how long is this, and what will you ask me — by listing the questions
 * themselves. That is both more informative and shorter than the cards were.
 */
const QUESTIONS: Array<{ label: string; detail: string }> = [
    { label: 'Your situation', detail: 'work, study, family or freelance' },
    {
        label: 'Where you live',
        detail: "your Veedel — Cologne's word for neighbourhood",
    },
    { label: 'When you arrived', detail: 'or when you plan to' },
];

export function WelcomeStep() {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="pt-8 pb-6">
                <div className="mb-4 text-primary">
                    {/* Not a raised palm: that reads as "stop" on a welcome screen. */}
                    <OnboardingIcon icon={IconListCheck} size="xl" />
                </div>
                <h1 className="mb-2.5 font-display text-[28px] leading-tight font-medium">
                    Moving to Cologne is a lot.
                    <br />
                    Let's make it a list.
                </h1>
                <p className="max-w-[430px] text-[15px] leading-relaxed text-muted-foreground">
                    Three questions, about a minute. Skip anything you're not
                    sure about — you can add it later.
                </p>
            </div>

            <div className="mb-2.5 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                What we'll ask
            </div>

            <div className="rounded-xl border border-border bg-card px-4 py-1.5">
                {QUESTIONS.map((question, index) => (
                    <div
                        key={question.label}
                        className="flex flex-wrap items-center gap-x-3 gap-y-0.5 border-b border-border py-2.5 last:border-b-0"
                    >
                        <span className="flex size-[22px] shrink-0 items-center justify-center rounded-full bg-secondary text-[11px] font-semibold text-muted-foreground">
                            {index + 1}
                        </span>
                        <span className="text-sm font-semibold">
                            {question.label}
                        </span>
                        <span className="ml-auto text-[13px] text-muted-foreground">
                            {question.detail}
                        </span>
                    </div>
                ))}
            </div>

            <p className="mt-2.5 text-[13px] text-muted-foreground">
                Then one optional screen you can skip in a click.
            </p>

            {/* Two things the engine genuinely does, said plainly. The second is
                the difference between this and asking a forum, so it belongs
                here as a promise rather than buried as a caveat. */}
            <div className="mt-5 flex flex-col gap-2.5">
                <Promise
                    text="Only what applies to you."
                    detail="Rules that don't match your situation stay hidden."
                />
                <Promise
                    text="Every figure has a source."
                    detail="Fees and deadlines link to stadt-koeln.de, BAMF or the law itself."
                />
            </div>

            <PrivacyNote text="Your answers stay in your Expadu profile and are never sold." />
        </div>
    );
}

function Promise({ text, detail }: { text: string; detail: string }) {
    return (
        <div className="flex items-start gap-2.5">
            <span className="mt-0.5 shrink-0 text-primary">
                <OnboardingIcon icon={IconCheck} size="sm" />
            </span>
            <p className="text-[13px] leading-relaxed text-muted-foreground">
                <span className="font-semibold text-foreground">{text}</span>{' '}
                {detail}
            </p>
        </div>
    );
}

/**
 * The disclaimer used to trail off the end of a sentence about selling data,
 * in orange, competing with the primary button. It is the most important
 * sentence in the flow, so it gets its own line and its own weight — and the
 * accent colour goes back to the call to action, which is the only thing on
 * the screen the user is meant to press.
 */
export function PrivacyNote({ text }: { text: string }) {
    return (
        <div className="mt-5 flex items-start gap-2 rounded-[10px] bg-secondary px-3.5 py-3 text-[12.5px] leading-normal">
            <span className="mt-0.5 shrink-0 text-muted-foreground">
                <OnboardingIcon icon={IconLock} size="sm" />
            </span>
            <div className="flex flex-col gap-1">
                <span className="text-muted-foreground">{text}</span>
                <span className="font-semibold">
                    Expadu is not legal advice.
                </span>
            </div>
        </div>
    );
}
