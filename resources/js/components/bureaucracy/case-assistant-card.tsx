import { Link, router, usePage } from '@inertiajs/react';
import {
    IconAlertCircle,
    IconArrowRight,
    IconCheck,
    IconInfoCircle,
    IconLock,
    IconMessageQuestion,
    IconPencil,
    IconSend2,
    IconSparkles,
} from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { onboarding } from '@/routes';
import { update as updateAiConsent } from '@/routes/bureaucracy/case/ai-consent';
import { store as storeCaseMessage } from '@/routes/bureaucracy/case/messages';
import { answer as answerCaseQuestion } from '@/routes/bureaucracy/case-question';
import { AiConsentSheet } from './ai-consent-sheet';
import type { CasePlanAi, CasePlanQuestion } from './case-plan-types';

type AnswerValue = string | number | boolean;

type Interpretation =
    | {
          outcome: 'candidate';
          value: AnswerValue;
          label: string;
          message: string;
      }
    | {
          outcome:
              | 'unknown'
              | 'off_topic'
              | 'unavailable'
              | 'invalid'
              | 'limited';
          message: string;
      };

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function jsonRequest<T>(
    url: string,
    method: 'POST' | 'PUT',
    body: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const payload = (await response.json().catch(() => null)) as T | null;

    if (payload !== null && (response.ok || response.status === 429)) {
        return payload;
    }

    throw new Error(`Request failed with status ${response.status}`);
}

export function CaseAssistantCard({
    question,
    ai,
    blockedByConflict = false,
}: {
    question: CasePlanQuestion | null;
    ai: CasePlanAi;
    blockedByConflict?: boolean;
}) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;
    const textarea = useRef<HTMLTextAreaElement>(null);
    const [message, setMessage] = useState('');
    const [structuredValue, setStructuredValue] = useState('');
    const [processing, setProcessing] = useState(false);
    const [textProcessing, setTextProcessing] = useState(false);
    const [consentProcessing, setConsentProcessing] = useState(false);
    const [consentOpen, setConsentOpen] = useState(false);
    const [consented, setConsented] = useState(ai.consented);
    const [remainingQuota, setRemainingQuota] = useState(ai.remaining_quota);
    const [interpretation, setInterpretation] = useState<Interpretation | null>(
        null,
    );
    const [deferred, setDeferred] = useState<'unknown' | 'skipped' | null>(
        null,
    );

    useEffect(() => {
        setMessage('');
        setStructuredValue('');
        setInterpretation(null);
        setDeferred(null);
    }, [question?.id]);

    const closeConsent = useCallback(() => {
        if (!consentProcessing) {
            setConsentOpen(false);
        }
    }, [consentProcessing]);

    function submit(value: AnswerValue): void {
        if (!question || processing) {
            return;
        }

        setProcessing(true);
        router.post(
            answerCaseQuestion.url(question.id),
            { value },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    }

    function submitInput(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (!question || structuredValue === '') {
            return;
        }

        submit(
            question.type === 'integer'
                ? Number(structuredValue)
                : structuredValue,
        );
    }

    async function interpretMessage(): Promise<void> {
        if (
            !question ||
            !ai.available ||
            message.trim() === '' ||
            textProcessing ||
            remainingQuota <= 0
        ) {
            return;
        }

        setTextProcessing(true);
        setInterpretation(null);

        try {
            const result = await jsonRequest<Interpretation>(
                storeCaseMessage.url(),
                'POST',
                {
                    question_id: question.id,
                    message: message.trim(),
                },
            );

            setInterpretation(result);

            if (result.outcome !== 'limited') {
                setRemainingQuota((remaining) => Math.max(0, remaining - 1));
            }
        } catch {
            setInterpretation({
                outcome: 'unavailable',
                message:
                    'The text assistant is unavailable right now. You can still use the choices below.',
            });
        } finally {
            setTextProcessing(false);
        }
    }

    async function submitText(
        event: FormEvent<HTMLFormElement>,
    ): Promise<void> {
        event.preventDefault();

        if (!consented) {
            setConsentOpen(true);

            return;
        }

        await interpretMessage();
    }

    async function acceptConsent(): Promise<void> {
        if (consentProcessing) {
            return;
        }

        setConsentProcessing(true);

        try {
            await jsonRequest<{ consented: boolean }>(
                updateAiConsent.url(),
                'PUT',
                { consent: true },
            );
            setConsented(true);
            setConsentOpen(false);
            await interpretMessage();
        } catch {
            setConsentOpen(false);
            setInterpretation({
                outcome: 'unavailable',
                message:
                    'The text assistant is unavailable right now. You can still use the choices below.',
            });
        } finally {
            setConsentProcessing(false);
        }
    }

    const validationError = errors?.value;
    const canUseText = ai.available && remainingQuota > 0;

    return (
        <>
            <aside className="overflow-hidden rounded-[16px] border border-[#D9D1BE] bg-[#FFFDF8] shadow-[0_8px_30px_rgba(43,35,20,0.06)] dark:border-[#4A4638] dark:bg-[#1E1D15]">
                <div className="flex items-center gap-2 border-b border-[#E8E2D5] bg-[#F4F0E6] px-4 py-3 dark:border-[#3A3930] dark:bg-[#29271D]">
                    <span className="flex items-center gap-2 text-[11px] font-bold tracking-[0.08em] text-[#766D59] uppercase dark:text-[#C5BDA9]">
                        <IconMessageQuestion size={16} stroke={ICON_STROKE} />
                        Case assistant
                    </span>
                    <span className="ml-auto rounded-full border border-[#D8D1C1] bg-white px-2 py-0.5 text-[9.5px] font-bold tracking-[0.04em] text-[#77736B] uppercase dark:border-[#4A4638] dark:bg-[#242218] dark:text-[#AAA89F]">
                        Verified rules
                    </span>
                </div>

                <div className="p-4 sm:p-5">
                    {blockedByConflict ? (
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/15 dark:text-[#FF9B90]">
                                <IconAlertCircle
                                    size={18}
                                    stroke={ICON_STROKE}
                                />
                            </span>
                            <div>
                                <h2 className="font-display text-[21px] leading-tight font-medium text-[#18170F] dark:text-[#F6F5F1]">
                                    Resolve the detail above first
                                </h2>
                                <p className="mt-1.5 text-[12.5px] leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                                    The assistant will continue after you choose
                                    which confirmed answer is current.
                                </p>
                            </div>
                        </div>
                    ) : !question ? (
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#67C39D]">
                                <IconCheck size={18} stroke={ICON_STROKE} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h2 className="font-display text-[21px] leading-tight font-medium text-[#18170F] dark:text-[#F6F5F1]">
                                    Your plan has enough confirmed information
                                </h2>
                                <p className="mt-1.5 text-[12.5px] leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                                    If your visa, permit, household, or goal
                                    changes, update your answers and Expadu will
                                    rebuild the verified plan.
                                </p>
                                <Link
                                    href={onboarding.url()}
                                    className="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-[9px] border border-[#DCD7CC] bg-white px-3 py-2 text-xs font-bold text-[#514E46] transition hover:border-primary hover:text-primary dark:border-[#454339] dark:bg-[#25241C] dark:text-[#E7E4DA]"
                                >
                                    Update my answers
                                    <IconArrowRight
                                        size={14}
                                        stroke={ICON_STROKE}
                                    />
                                </Link>
                            </div>
                        </div>
                    ) : deferred ? (
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-[#F4F0E6] text-[#6B6860] dark:bg-[#302E24] dark:text-[#C5C1B5]">
                                <IconInfoCircle
                                    size={18}
                                    stroke={ICON_STROKE}
                                />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] leading-5 font-semibold text-[#38362F] dark:text-[#E7E3D8]">
                                    {deferred === 'unknown'
                                        ? "That's okay — we won't guess."
                                        : 'You can answer this later.'}
                                </p>
                                <p className="mt-0.5 text-xs leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                                    Your confirmed steps remain visible. Add
                                    this detail later to improve the plan.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => setDeferred(null)}
                                    className="mt-2 min-h-11 cursor-pointer border-0 bg-transparent p-0 text-xs font-bold text-primary hover:underline"
                                >
                                    Answer now
                                </button>
                            </div>
                        </div>
                    ) : (
                        <>
                            <h2 className="font-display text-[22px] leading-tight font-medium tracking-[-0.01em] text-[#18170F] dark:text-[#F6F5F1]">
                                One detail will improve your plan
                            </h2>
                            <p className="mt-3 text-[15px] leading-6 font-semibold text-[#292820] dark:text-[#E7E4DA]">
                                {question.question}
                            </p>
                            <p className="mt-1.5 text-[12.5px] leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                                {question.why}
                            </p>

                            {canUseText && (
                                <div className="mt-4 rounded-[13px] border border-[#DED5C2] bg-[#F8F3E8] p-3.5 dark:border-[#514B3C] dark:bg-[#28251B]">
                                    <div className="flex items-start gap-2.5">
                                        <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-white text-primary shadow-sm dark:bg-[#332F23]">
                                            <IconSparkles
                                                size={15}
                                                stroke={ICON_STROKE}
                                            />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <label
                                                htmlFor={`case-assistant-text-${question.id}`}
                                                className="text-[12.5px] font-bold text-[#38362F] dark:text-[#E7E3D8]"
                                            >
                                                Or describe your answer
                                            </label>
                                            <p className="mt-0.5 text-[10.5px] leading-4 text-[#77736B] dark:text-[#AAA89F]">
                                                The text checker only converts
                                                your words into one answer for
                                                you to confirm.
                                            </p>
                                        </div>
                                    </div>

                                    {interpretation?.outcome === 'candidate' ? (
                                        <div className="mt-3 rounded-[11px] border border-[#B8DDCE] bg-white p-3 dark:border-[#2E6B55] dark:bg-[#202B25]">
                                            <p className="text-[10px] font-bold tracking-[0.06em] text-[#0A7C52] uppercase dark:text-[#67C39D]">
                                                Check this answer
                                            </p>
                                            <p className="mt-1 text-[14px] font-bold text-[#25241D] dark:text-[#F0EDE5]">
                                                {interpretation.label}
                                            </p>
                                            <p className="mt-1 text-[11px] leading-4 text-[#6B6860] dark:text-[#AAA89F]">
                                                {interpretation.message}
                                            </p>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    disabled={processing}
                                                    onClick={() =>
                                                        submit(
                                                            interpretation.value,
                                                        )
                                                    }
                                                    className="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-[9px] border-0 bg-primary px-3.5 text-xs font-bold text-white transition hover:bg-primary/90 disabled:cursor-wait disabled:opacity-60"
                                                >
                                                    <IconCheck
                                                        size={14}
                                                        stroke={ICON_STROKE}
                                                    />
                                                    Confirm answer
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={processing}
                                                    onClick={() => {
                                                        setInterpretation(null);
                                                        textarea.current?.focus();
                                                    }}
                                                    className="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-[9px] border border-[#DCD7CC] bg-white px-3.5 text-xs font-bold text-[#514E46] transition hover:border-primary hover:text-primary disabled:cursor-wait disabled:opacity-60 dark:border-[#4A4638] dark:bg-[#25241C] dark:text-[#E7E4DA]"
                                                >
                                                    <IconPencil
                                                        size={14}
                                                        stroke={ICON_STROKE}
                                                    />
                                                    Edit response
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <form
                                            onSubmit={(event) =>
                                                void submitText(event)
                                            }
                                            className="mt-3"
                                        >
                                            <textarea
                                                ref={textarea}
                                                id={`case-assistant-text-${question.id}`}
                                                value={message}
                                                maxLength={2000}
                                                rows={3}
                                                disabled={textProcessing}
                                                onChange={(event) =>
                                                    setMessage(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="For example: I have a Blue Card and passed B1."
                                                className="min-h-24 w-full resize-y rounded-[10px] border border-[#D8D1C1] bg-white px-3 py-2.5 text-[13px] leading-5 text-[#292820] transition outline-none placeholder:text-[#AAA89F] focus:border-primary focus:ring-2 focus:ring-primary/15 disabled:cursor-wait disabled:opacity-60 dark:border-[#514B3C] dark:bg-[#201F18] dark:text-[#F0EDE5]"
                                            />
                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                <button
                                                    type="submit"
                                                    disabled={
                                                        textProcessing ||
                                                        message.trim() === ''
                                                    }
                                                    className="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-[9px] border-0 bg-[#2F2D26] px-3.5 text-xs font-bold text-white transition hover:bg-[#18170F] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-[#E7E3D8] dark:text-[#201F18] dark:hover:bg-white"
                                                >
                                                    <IconSend2
                                                        size={14}
                                                        stroke={ICON_STROKE}
                                                    />
                                                    {textProcessing
                                                        ? 'Checking…'
                                                        : 'Check my answer'}
                                                </button>
                                                <span className="text-[10px] text-[#8B877E] dark:text-[#96938A]">
                                                    {remainingQuota} text checks
                                                    left today
                                                </span>
                                            </div>
                                        </form>
                                    )}

                                    {interpretation &&
                                        interpretation.outcome !==
                                            'candidate' && (
                                            <div
                                                role="status"
                                                className="mt-3 flex items-start gap-2 rounded-[9px] border border-[#E0D8C7] bg-white p-3 text-[11.5px] leading-5 text-[#5F5A4E] dark:border-[#4A4638] dark:bg-[#201F18] dark:text-[#C5C1B5]"
                                            >
                                                <IconInfoCircle
                                                    size={15}
                                                    stroke={ICON_STROKE}
                                                    className="mt-0.5 shrink-0"
                                                />
                                                {interpretation.message}
                                            </div>
                                        )}
                                </div>
                            )}

                            {!ai.available && (
                                <div className="mt-4 flex items-start gap-2 rounded-[10px] border border-[#E4DED1] bg-[#F8F6F0] p-3 text-[11.5px] leading-5 text-[#6B6860] dark:border-[#454339] dark:bg-[#25241C] dark:text-[#AAA89F]">
                                    <IconInfoCircle
                                        size={15}
                                        stroke={ICON_STROKE}
                                        className="mt-0.5 shrink-0"
                                    />
                                    Text checking is not available right now.
                                    Use the verified choices below.
                                </div>
                            )}

                            {ai.available && remainingQuota <= 0 && (
                                <div className="mt-4 flex items-start gap-2 rounded-[10px] border border-[#E4DED1] bg-[#F8F6F0] p-3 text-[11.5px] leading-5 text-[#6B6860] dark:border-[#454339] dark:bg-[#25241C] dark:text-[#AAA89F]">
                                    <IconInfoCircle
                                        size={15}
                                        stroke={ICON_STROKE}
                                        className="mt-0.5 shrink-0"
                                    />
                                    Today&apos;s text-check limit is reached.
                                    The verified choices remain available.
                                </div>
                            )}

                            <div className="mt-4 flex items-center gap-3">
                                <span className="h-px flex-1 bg-[#E8E2D5] dark:bg-[#3A3930]" />
                                <span className="text-[9.5px] font-bold tracking-[0.06em] text-[#9A958A] uppercase">
                                    Answer directly
                                </span>
                                <span className="h-px flex-1 bg-[#E8E2D5] dark:bg-[#3A3930]" />
                            </div>

                            {question.type === 'enum' && (
                                <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                    {question.options.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            disabled={processing}
                                            onClick={() => submit(option.value)}
                                            className="group flex min-h-11 cursor-pointer items-center justify-between gap-2 rounded-[10px] border border-[#DCD7CC] bg-white px-3 py-2.5 text-left text-[13px] font-semibold text-[#292820] transition hover:border-primary hover:bg-primary-soft disabled:cursor-wait disabled:opacity-60 dark:border-[#454339] dark:bg-[#25241C] dark:text-[#F0EDE5] dark:hover:border-primary dark:hover:bg-primary/10"
                                        >
                                            {option.label}
                                            <IconArrowRight
                                                size={15}
                                                stroke={ICON_STROKE}
                                                className="shrink-0 text-[#AAA89F] transition group-hover:translate-x-0.5 group-hover:text-primary"
                                            />
                                        </button>
                                    ))}
                                </div>
                            )}

                            {question.type === 'boolean' && (
                                <div className="mt-4 grid grid-cols-2 gap-2">
                                    {[
                                        { label: 'Yes', value: true },
                                        { label: 'No', value: false },
                                    ].map((option) => (
                                        <button
                                            key={option.label}
                                            type="button"
                                            disabled={processing}
                                            onClick={() => submit(option.value)}
                                            className="min-h-11 cursor-pointer rounded-[10px] border border-[#DCD7CC] bg-white px-3 py-2.5 text-[13px] font-semibold transition hover:border-primary hover:bg-primary-soft disabled:cursor-wait disabled:opacity-60 dark:border-[#454339] dark:bg-[#25241C] dark:hover:border-primary dark:hover:bg-primary/10"
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {(question.type === 'date' ||
                                question.type === 'integer') && (
                                <form
                                    onSubmit={submitInput}
                                    className="mt-4 flex gap-2"
                                >
                                    <input
                                        type={
                                            question.type === 'date'
                                                ? 'date'
                                                : 'number'
                                        }
                                        min={
                                            question.type === 'integer'
                                                ? 0
                                                : undefined
                                        }
                                        inputMode={
                                            question.type === 'integer'
                                                ? 'numeric'
                                                : undefined
                                        }
                                        value={structuredValue}
                                        onChange={(event) =>
                                            setStructuredValue(
                                                event.target.value,
                                            )
                                        }
                                        aria-label={question.question}
                                        required
                                        className="min-h-11 min-w-0 flex-1 rounded-[10px] border border-[#DCD7CC] bg-white px-3 text-[14px] transition outline-none focus:border-primary focus:ring-2 focus:ring-primary/15 dark:border-[#454339] dark:bg-[#25241C]"
                                    />
                                    <button
                                        type="submit"
                                        disabled={
                                            processing || structuredValue === ''
                                        }
                                        className="min-h-11 cursor-pointer rounded-[10px] border-0 bg-primary px-4 text-[13px] font-bold text-white transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Continue
                                    </button>
                                </form>
                            )}

                            {validationError && (
                                <p
                                    role="alert"
                                    className="mt-3 text-xs font-semibold text-[#C4271A] dark:text-[#FF7D70]"
                                >
                                    {validationError}
                                </p>
                            )}

                            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-[#EEE9DE] pt-3 dark:border-[#3A3930]">
                                <button
                                    type="button"
                                    onClick={() => setDeferred('unknown')}
                                    className="min-h-11 cursor-pointer rounded-[8px] border-0 bg-transparent px-2 py-2 text-xs font-semibold text-[#6B6860] hover:bg-[#F1EEE6] hover:text-[#18170F] dark:text-[#AAA89F] dark:hover:bg-[#302E24] dark:hover:text-white"
                                >
                                    I don&apos;t know
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setDeferred('skipped')}
                                    className="min-h-11 cursor-pointer rounded-[8px] border-0 bg-transparent px-2 py-2 text-xs font-semibold text-[#6B6860] hover:bg-[#F1EEE6] hover:text-[#18170F] dark:text-[#AAA89F] dark:hover:bg-[#302E24] dark:hover:text-white"
                                >
                                    Skip for now
                                </button>
                                <span className="ml-auto flex items-center gap-1 text-[10.5px] text-[#8B877E] dark:text-[#96938A]">
                                    <IconLock size={12} stroke={ICON_STROKE} />
                                    Used only for your case
                                </span>
                            </div>
                        </>
                    )}
                </div>
            </aside>

            <AiConsentSheet
                open={consentOpen}
                processorName={ai.processor_name ?? 'the text processor'}
                privacyUrl={ai.processor_privacy_url ?? '#'}
                processing={consentProcessing}
                onAccept={() => void acceptConsent()}
                onDecline={closeConsent}
            />
        </>
    );
}
