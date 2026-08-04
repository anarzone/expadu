import {
    IconExternalLink,
    IconLock,
    IconShieldCheck,
    IconX,
} from '@tabler/icons-react';
import { useEffect, useRef } from 'react';
import { ICON_STROKE } from '@/constants/icons';

export function AiConsentSheet({
    open,
    processorName,
    privacyUrl,
    processing,
    onAccept,
    onDecline,
}: {
    open: boolean;
    processorName: string;
    privacyUrl: string;
    processing: boolean;
    onAccept: () => void;
    onDecline: () => void;
}) {
    const acceptButton = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        acceptButton.current?.focus();

        function closeOnEscape(event: KeyboardEvent): void {
            if (event.key === 'Escape' && !processing) {
                onDecline();
            }
        }

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [onDecline, open, processing]);

    if (!open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-[100] flex items-end justify-center bg-[#18170F]/45 p-0 backdrop-blur-[2px] sm:items-center sm:p-5 dark:bg-black/65"
            role="presentation"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="ai-consent-title"
                aria-describedby="ai-consent-description"
                className="w-full max-w-[520px] rounded-t-[22px] border border-[#D9D1BE] bg-[#FFFDF8] shadow-[0_-16px_50px_rgba(27,22,13,0.18)] sm:rounded-[20px] dark:border-[#4A4638] dark:bg-[#1E1D15]"
            >
                <div className="flex items-center justify-between gap-3 border-b border-[#E8E2D5] px-5 py-4 dark:border-[#3A3930]">
                    <span className="flex size-9 items-center justify-center rounded-full bg-primary-soft text-primary dark:bg-primary/15">
                        <IconShieldCheck size={19} stroke={ICON_STROKE} />
                    </span>
                    <button
                        type="button"
                        aria-label="Close consent details"
                        disabled={processing}
                        onClick={onDecline}
                        className="ml-auto flex size-11 cursor-pointer items-center justify-center rounded-full border-0 bg-transparent text-[#77736B] transition hover:bg-[#F1EEE6] hover:text-[#18170F] disabled:cursor-wait disabled:opacity-50 dark:text-[#AAA89F] dark:hover:bg-[#302E24] dark:hover:text-white"
                    >
                        <IconX size={19} stroke={ICON_STROKE} />
                    </button>
                </div>

                <div className="p-5 sm:p-6">
                    <p className="text-[10.5px] font-bold tracking-[0.08em] text-primary uppercase">
                        Before the first text check
                    </p>
                    <h2
                        id="ai-consent-title"
                        className="mt-2 font-display text-[26px] leading-tight font-medium tracking-[-0.02em] text-[#18170F] dark:text-[#F6F5F1]"
                    >
                        Let {processorName} interpret one answer?
                    </h2>
                    <p
                        id="ai-consent-description"
                        className="mt-3 text-[13.5px] leading-6 text-[#6B6860] dark:text-[#AAA89F]"
                    >
                        Expadu sends the current, server-written question and
                        the answer you type. The model may only suggest one
                        structured value for you to confirm. It cannot decide
                        your route or write legal guidance.
                    </p>

                    <div className="mt-5 grid gap-2.5">
                        <div className="flex items-start gap-3 rounded-[12px] border border-[#E4DED1] bg-white p-3.5 dark:border-[#454339] dark:bg-[#25241C]">
                            <IconLock
                                size={17}
                                stroke={ICON_STROKE}
                                className="mt-0.5 shrink-0 text-[#766D59] dark:text-[#C5BDA9]"
                            />
                            <p className="text-[12.5px] leading-5 text-[#514E46] dark:text-[#C9C6BC]">
                                Your raw text is encrypted in Expadu and deleted
                                within 30 days. It is used only for this case
                                question.
                            </p>
                        </div>
                        <div className="flex items-start gap-3 rounded-[12px] border border-[#E4DED1] bg-white p-3.5 dark:border-[#454339] dark:bg-[#25241C]">
                            <IconShieldCheck
                                size={17}
                                stroke={ICON_STROKE}
                                className="mt-0.5 shrink-0 text-[#766D59] dark:text-[#C5BDA9]"
                            />
                            <p className="text-[12.5px] leading-5 text-[#514E46] dark:text-[#C9C6BC]">
                                Nothing changes until you confirm the extracted
                                answer. The verified rules still build your
                                plan.
                            </p>
                        </div>
                    </div>

                    <a
                        href={privacyUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-4 inline-flex min-h-11 items-center gap-1.5 py-2 text-xs font-bold text-[#5F5A4E] underline decoration-[#B9B3A8] underline-offset-4 hover:text-primary dark:text-[#C5C1B5]"
                    >
                        Read {processorName}&apos;s privacy policy
                        <IconExternalLink size={14} stroke={ICON_STROKE} />
                    </a>

                    <div className="mt-5 grid gap-2 sm:grid-cols-[1fr_auto]">
                        <button
                            ref={acceptButton}
                            type="button"
                            disabled={processing}
                            onClick={onAccept}
                            className="min-h-12 cursor-pointer rounded-[11px] border-0 bg-primary px-5 text-[13px] font-bold text-white transition hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:ring-offset-[#1E1D15]"
                        >
                            {processing ? 'Saving choice…' : 'Allow this check'}
                        </button>
                        <button
                            type="button"
                            disabled={processing}
                            onClick={onDecline}
                            className="min-h-12 cursor-pointer rounded-[11px] border border-[#DCD7CC] bg-white px-5 text-[13px] font-bold text-[#514E46] transition hover:border-[#B9B3A8] hover:bg-[#F7F4ED] disabled:cursor-wait disabled:opacity-60 dark:border-[#454339] dark:bg-[#25241C] dark:text-[#E7E4DA] dark:hover:bg-[#302E24]"
                        >
                            Not now
                        </button>
                    </div>
                </div>
            </section>
        </div>
    );
}
