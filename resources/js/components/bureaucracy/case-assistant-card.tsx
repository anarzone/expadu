import { router, usePage } from '@inertiajs/react';
import {
    IconArrowRight,
    IconInfoCircle,
    IconLock,
    IconMessageQuestion,
} from '@tabler/icons-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { answer as answerCaseQuestion } from '@/routes/bureaucracy/case-question';
import type { CasePlanQuestion } from './case-plan-types';

type AnswerValue = string | number | boolean;

export function CaseAssistantCard({
    question,
}: {
    question: CasePlanQuestion;
}) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;
    const [inputValue, setInputValue] = useState('');
    const [processing, setProcessing] = useState(false);
    const [deferred, setDeferred] = useState<'unknown' | 'skipped' | null>(
        null,
    );

    function submit(value: AnswerValue): void {
        if (processing) {
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

        if (inputValue === '') {
            return;
        }

        submit(question.type === 'integer' ? Number(inputValue) : inputValue);
    }

    if (deferred) {
        return (
            <aside className="rounded-[14px] border border-[#DED8C8] bg-[#F4F0E6] p-4 dark:border-[#4A4638] dark:bg-[#242218]">
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-[#6B6860] shadow-sm dark:bg-[#302E24] dark:text-[#C5C1B5]">
                        <IconInfoCircle size={17} stroke={ICON_STROKE} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-[13px] leading-5 font-semibold text-[#38362F] dark:text-[#E7E3D8]">
                            {deferred === 'unknown'
                                ? "That's okay — we won't guess."
                                : 'You can answer this later.'}
                        </p>
                        <p className="mt-0.5 text-xs leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                            Your confirmed steps remain visible. Answer this
                            detail when you have it to improve the rest of the
                            plan.
                        </p>
                        <button
                            type="button"
                            onClick={() => setDeferred(null)}
                            className="mt-2 cursor-pointer border-0 bg-transparent p-0 text-xs font-bold text-primary hover:underline"
                        >
                            Answer now
                        </button>
                    </div>
                </div>
            </aside>
        );
    }

    const validationError = errors?.value;

    return (
        <aside className="overflow-hidden rounded-[16px] border border-[#D9D1BE] bg-[#FFFDF8] shadow-[0_8px_30px_rgba(43,35,20,0.06)] dark:border-[#4A4638] dark:bg-[#1E1D15]">
            <div className="border-b border-[#E8E2D5] bg-[#F4F0E6] px-4 py-3 dark:border-[#3A3930] dark:bg-[#29271D]">
                <div className="flex items-center gap-2 text-[11px] font-bold tracking-[0.08em] text-[#766D59] uppercase dark:text-[#C5BDA9]">
                    <IconMessageQuestion size={16} stroke={ICON_STROKE} />
                    Case assistant
                </div>
            </div>

            <div className="p-4 sm:p-5">
                <h2 className="font-display text-[22px] leading-tight font-medium tracking-[-0.01em] text-[#18170F] dark:text-[#F6F5F1]">
                    One detail will improve your plan
                </h2>
                <p className="mt-3 text-[15px] leading-6 font-semibold text-[#292820] dark:text-[#E7E4DA]">
                    {question.question}
                </p>
                <p className="mt-1.5 text-[12.5px] leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                    {question.why}
                </p>

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

                {(question.type === 'date' || question.type === 'integer') && (
                    <form onSubmit={submitInput} className="mt-4 flex gap-2">
                        <input
                            type={question.type === 'date' ? 'date' : 'number'}
                            min={question.type === 'integer' ? 0 : undefined}
                            inputMode={
                                question.type === 'integer'
                                    ? 'numeric'
                                    : undefined
                            }
                            value={inputValue}
                            onChange={(event) =>
                                setInputValue(event.target.value)
                            }
                            aria-label={question.question}
                            required
                            className="min-h-11 min-w-0 flex-1 rounded-[10px] border border-[#DCD7CC] bg-white px-3 text-[14px] transition outline-none focus:border-primary focus:ring-2 focus:ring-primary/15 dark:border-[#454339] dark:bg-[#25241C]"
                        />
                        <button
                            type="submit"
                            disabled={processing || inputValue === ''}
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
                        I don't know
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
            </div>
        </aside>
    );
}
