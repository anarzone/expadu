import { router } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconCalendar,
    IconCheck,
    IconChevronDown,
    IconCircleCheck,
    IconClock,
    IconExternalLink,
    IconFileText,
    IconInfoCircle,
    IconListCheck,
    IconRosetteDiscountCheck,
    IconScale,
} from '@tabler/icons-react';
import { useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { update as updateCaseTask } from '@/routes/bureaucracy/case-task';
import { update as updateCaseTaskDocuments } from '@/routes/bureaucracy/case-task/documents';
import type {
    CasePlanItem,
    CasePlanSectionKey,
    CasePlanTaskStatus,
} from './case-plan-types';
import { documentLabel } from './case-plan-types';

const STATUS_STYLES: Record<CasePlanTaskStatus, string> = {
    not_started:
        'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]',
    in_progress:
        'bg-[#E5EEFA] text-[#315D91] dark:bg-[#315D91]/20 dark:text-[#82A9D5]',
    submitted:
        'bg-[#FDF0D4] text-[#9B650D] dark:bg-[#C47D0E]/20 dark:text-[#E8A958]',
    done: 'bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#67C39D]',
};

const URGENCY_LABELS: Record<string, string> = {
    critical: 'Time-sensitive',
    high: 'Important',
    medium: 'Plan ahead',
    low: 'Good to know',
};

function formatDate(value: string): string {
    return new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString(
        'en-GB',
        {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        },
    );
}

function statusActionLabel(status: CasePlanTaskStatus): string {
    return status === 'done' ? 'Reopen task' : 'Mark done';
}

function nextStatus(status: CasePlanTaskStatus): CasePlanTaskStatus {
    return status === 'done' ? 'not_started' : 'done';
}

function safeExternalUrl(value: string | null): string | null {
    if (!value) {
        return null;
    }

    try {
        const parsed = new URL(value);

        return parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

export function CasePlanTaskCard({
    item,
    section,
    initiallyExpanded = false,
}: {
    item: CasePlanItem;
    section: CasePlanSectionKey;
    initiallyExpanded?: boolean;
}) {
    const [expanded, setExpanded] = useState(initiallyExpanded);
    const [sourcesOpen, setSourcesOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [documentProcessing, setDocumentProcessing] = useState(false);
    const [checkedDocuments, setCheckedDocuments] = useState<string[]>(
        item.documents_checked ?? [],
    );
    const status = item.status ?? 'not_started';

    if (item.kind === 'coverage_notice') {
        return <CoverageNotice />;
    }

    if (section === 'information_needed') {
        return <InformationNeededNotice item={item} />;
    }

    const documents = item.documents_required ?? [];
    const steps = item.how_to_steps ?? [];
    const decisions = item.decision_options ?? [];
    const sources = item.legal_sources ?? [];
    const hasDetails = Boolean(
        item.description ||
        documents.length ||
        steps.length ||
        decisions.length ||
        sources.length ||
        item.verified_at,
    );
    const actionable =
        Boolean(item.key) && item.type !== 'info' && section !== 'not_covered';

    function updateStatus(next: CasePlanTaskStatus): void {
        if (!item.key || processing) {
            return;
        }

        setProcessing(true);
        router.patch(
            updateCaseTask.url({ task: item.key }),
            { status: next },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    }

    function toggleDocument(label: string): void {
        if (!item.key || documentProcessing) {
            return;
        }

        const previous = checkedDocuments;
        const next = previous.includes(label)
            ? previous.filter((document) => document !== label)
            : [...previous, label];

        setCheckedDocuments(next);
        setDocumentProcessing(true);
        router.patch(
            updateCaseTaskDocuments.url({ task: item.key }),
            { documents_checked: next },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setCheckedDocuments(previous),
                onFinish: () => setDocumentProcessing(false),
            },
        );
    }

    return (
        <article
            className={`overflow-hidden rounded-[14px] border bg-white transition-shadow dark:bg-[#1E1D15] ${
                item.high_impact
                    ? 'border-[#D9CDB4] shadow-[0_7px_24px_rgba(56,43,18,0.06)] dark:border-[#524A39]'
                    : 'border-[#E2DFD6] dark:border-[#3A3930]'
            }`}
        >
            <button
                type="button"
                aria-expanded={expanded}
                onClick={() => hasDetails && setExpanded((value) => !value)}
                className={`flex w-full items-start gap-3 border-0 bg-transparent p-4 text-left sm:p-[18px] ${
                    hasDetails ? 'cursor-pointer' : 'cursor-default'
                }`}
            >
                <span
                    className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full ${
                        status === 'done'
                            ? 'bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#67C39D]'
                            : item.type === 'info'
                              ? 'bg-[#E9E5DA] text-[#716A5B] dark:bg-[#302E24] dark:text-[#C5BDA9]'
                              : 'bg-primary-soft text-primary dark:bg-primary/15'
                    }`}
                >
                    {status === 'done' ? (
                        <IconCheck size={17} stroke={ICON_STROKE} />
                    ) : item.type === 'info' ? (
                        <IconInfoCircle size={17} stroke={ICON_STROKE} />
                    ) : (
                        <IconListCheck size={17} stroke={ICON_STROKE} />
                    )}
                </span>

                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5">
                        {item.status && (
                            <span
                                className={`rounded-full px-2 py-0.5 text-[9.5px] font-bold tracking-[0.05em] uppercase ${STATUS_STYLES[status]}`}
                            >
                                {item.status_label ?? status.replace('_', ' ')}
                            </span>
                        )}
                        {item.urgency && URGENCY_LABELS[item.urgency] && (
                            <span
                                className={`rounded-full px-2 py-0.5 text-[9.5px] font-bold tracking-[0.05em] uppercase ${
                                    item.urgency === 'critical'
                                        ? 'bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/20 dark:text-[#FF7D70]'
                                        : 'bg-[#F4F0E6] text-[#766D59] dark:bg-[#302E24] dark:text-[#C5BDA9]'
                                }`}
                            >
                                {URGENCY_LABELS[item.urgency]}
                            </span>
                        )}
                    </span>
                    <h3 className="mt-1.5 text-[15px] leading-[1.35] font-bold text-[#18170F] dark:text-[#F6F5F1]">
                        {item.title ?? 'Case guidance'}
                    </h3>
                    {item.deadline && (
                        <span className="mt-1.5 flex items-center gap-1.5 text-[11.5px] font-semibold text-[#9B650D] dark:text-[#E8A958]">
                            <IconCalendar size={14} stroke={ICON_STROKE} />
                            Check or act by {formatDate(item.deadline)}
                        </span>
                    )}
                </span>

                {hasDetails && (
                    <IconChevronDown
                        size={18}
                        stroke={ICON_STROKE}
                        className={`mt-1 shrink-0 text-[#AAA89F] transition-transform motion-reduce:transition-none ${expanded ? 'rotate-180' : ''}`}
                    />
                )}
            </button>

            {expanded && hasDetails && (
                <div className="border-t border-[#EEEAE0] px-4 py-4 sm:px-[18px] dark:border-[#35342C]">
                    {item.description && (
                        <p className="text-[13px] leading-[1.65] whitespace-pre-line text-[#5F5B52] dark:text-[#BBB7AC]">
                            {item.description.trim()}
                        </p>
                    )}

                    {documents.length > 0 && (
                        <DetailBlock
                            title="Documents to prepare"
                            icon={
                                <IconFileText size={16} stroke={ICON_STROKE} />
                            }
                        >
                            <ul className="space-y-2">
                                {documents.map((document, index) => {
                                    const label = documentLabel(document);
                                    const note =
                                        typeof document === 'string'
                                            ? null
                                            : document.note;
                                    const warning =
                                        typeof document !== 'string' &&
                                        document.tone === 'warn';

                                    return (
                                        <li
                                            key={`${documentLabel(document)}-${index}`}
                                            className="flex items-start gap-2 text-[12.5px] leading-5"
                                        >
                                            {actionable ? (
                                                <button
                                                    type="button"
                                                    role="checkbox"
                                                    aria-checked={checkedDocuments.includes(
                                                        label,
                                                    )}
                                                    aria-label={`${checkedDocuments.includes(label) ? 'Mark as not ready' : 'Mark as ready'}: ${label}`}
                                                    disabled={
                                                        documentProcessing
                                                    }
                                                    onClick={() =>
                                                        toggleDocument(label)
                                                    }
                                                    className="group -my-3 -ml-3 flex size-11 shrink-0 cursor-pointer items-center justify-center border-0 bg-transparent disabled:cursor-wait disabled:opacity-60"
                                                >
                                                    <span
                                                        className={`flex size-5 items-center justify-center rounded-[5px] border transition motion-reduce:transition-none ${
                                                            checkedDocuments.includes(
                                                                label,
                                                            )
                                                                ? 'border-[#0A7C52] bg-[#0A7C52] text-white dark:border-[#67C39D] dark:bg-[#0A7C52]'
                                                                : 'border-[#BDB7AA] bg-white text-transparent group-hover:border-primary dark:border-[#625E53] dark:bg-[#292820]'
                                                        }`}
                                                    >
                                                        <IconCheck
                                                            size={13}
                                                            stroke={ICON_STROKE}
                                                        />
                                                    </span>
                                                </button>
                                            ) : (
                                                <IconCircleCheck
                                                    size={15}
                                                    stroke={ICON_STROKE}
                                                    className="mt-0.5 shrink-0 text-[#0A7C52] dark:text-[#67C39D]"
                                                />
                                            )}
                                            <span>
                                                <span
                                                    className={`font-semibold text-[#38362F] dark:text-[#E4E1D8] ${
                                                        checkedDocuments.includes(
                                                            label,
                                                        )
                                                            ? 'line-through opacity-60'
                                                            : ''
                                                    }`}
                                                >
                                                    {label}
                                                </span>
                                                {note && (
                                                    <span
                                                        className={`mt-0.5 block text-[11.5px] leading-[1.55] ${
                                                            warning
                                                                ? 'text-[#A55E0A] dark:text-[#E8A958]'
                                                                : 'text-[#77736B] dark:text-[#AAA89F]'
                                                        }`}
                                                    >
                                                        {note}
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </DetailBlock>
                    )}

                    {steps.length > 0 && (
                        <DetailBlock
                            title="How to do it"
                            icon={
                                <IconListCheck size={16} stroke={ICON_STROKE} />
                            }
                        >
                            <ol className="space-y-3">
                                {steps.map((step, index) => {
                                    const title =
                                        typeof step === 'string'
                                            ? step
                                            : step.title;
                                    const body =
                                        typeof step === 'string'
                                            ? null
                                            : step.body;
                                    const link = safeExternalUrl(
                                        typeof step === 'string'
                                            ? null
                                            : (step.link ?? null),
                                    );

                                    return (
                                        <li
                                            key={`${title}-${index}`}
                                            className="flex items-start gap-2.5"
                                        >
                                            <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-[#EFEDE7] text-[10px] font-bold text-[#6B6860] dark:bg-[#302E24] dark:text-[#C5BDA9]">
                                                {index + 1}
                                            </span>
                                            <span className="text-[12.5px] leading-5">
                                                <span className="font-semibold text-[#38362F] dark:text-[#E4E1D8]">
                                                    {title}
                                                </span>
                                                {body && (
                                                    <span className="mt-0.5 block text-[11.5px] leading-[1.55] text-[#77736B] dark:text-[#AAA89F]">
                                                        {body}
                                                    </span>
                                                )}
                                                {link && (
                                                    <a
                                                        href={link}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="mt-1 inline-flex items-center gap-1 text-[11.5px] font-bold text-primary no-underline hover:underline"
                                                    >
                                                        Open linked page
                                                        <IconExternalLink
                                                            size={12}
                                                            stroke={ICON_STROKE}
                                                        />
                                                    </a>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ol>
                        </DetailBlock>
                    )}

                    {decisions.length > 0 && (
                        <DetailBlock
                            title="Options to compare"
                            icon={<IconScale size={16} stroke={ICON_STROKE} />}
                        >
                            <div className="grid gap-2 sm:grid-cols-2">
                                {decisions.map((decision) => (
                                    <div
                                        key={decision.label}
                                        className="rounded-[10px] border border-[#E6E1D6] bg-[#FAF8F3] p-3 dark:border-[#403E34] dark:bg-[#28271F]"
                                    >
                                        <div className="text-xs font-bold">
                                            {decision.label}
                                        </div>
                                        {decision.body && (
                                            <p className="mt-1 text-[11.5px] leading-[1.55] text-[#6B6860] dark:text-[#AAA89F]">
                                                {decision.body}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </DetailBlock>
                    )}

                    {(sources.length > 0 || item.verified_at) && (
                        <div className="mt-4 border-t border-[#EEEAE0] pt-3 dark:border-[#35342C]">
                            <button
                                type="button"
                                aria-expanded={sourcesOpen}
                                onClick={() =>
                                    setSourcesOpen((value) => !value)
                                }
                                className="flex min-h-11 w-full cursor-pointer items-center gap-2 border-0 bg-transparent py-2 text-left text-[12px] font-bold text-[#4B4942] dark:text-[#D5D1C7]"
                            >
                                <IconRosetteDiscountCheck
                                    size={16}
                                    stroke={ICON_STROKE}
                                    className="text-[#0A7C52] dark:text-[#67C39D]"
                                />
                                Official sources & verification
                                <IconChevronDown
                                    size={14}
                                    stroke={ICON_STROKE}
                                    className={`ml-auto text-[#AAA89F] transition-transform motion-reduce:transition-none ${sourcesOpen ? 'rotate-180' : ''}`}
                                />
                            </button>

                            {sourcesOpen && (
                                <div className="mt-3 rounded-[10px] bg-[#F6F5F1] p-3 dark:bg-[#28271F]">
                                    {item.verified_at && (
                                        <p className="flex items-center gap-1.5 text-[11px] font-semibold text-[#6B6860] dark:text-[#AAA89F]">
                                            <IconClock
                                                size={13}
                                                stroke={ICON_STROKE}
                                            />
                                            Verified on{' '}
                                            {formatDate(item.verified_at)}
                                        </p>
                                    )}
                                    {sources.length > 0 && (
                                        <ul className="mt-2 space-y-1.5">
                                            {sources.map((source) => (
                                                <li
                                                    key={`${source.kind}-${source.url}`}
                                                >
                                                    <a
                                                        href={source.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-start gap-1.5 text-[11.5px] leading-4 font-semibold text-primary no-underline hover:underline"
                                                    >
                                                        {source.label}
                                                        <IconExternalLink
                                                            size={12}
                                                            stroke={ICON_STROKE}
                                                            className="mt-0.5 shrink-0"
                                                        />
                                                    </a>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {item.high_impact && (
                                        <p className="mt-2.5 flex items-start gap-1.5 border-t border-[#E3DFD5] pt-2.5 text-[10.5px] leading-4 text-[#77736B] dark:border-[#403E34] dark:text-[#AAA89F]">
                                            <IconAlertTriangle
                                                size={13}
                                                stroke={ICON_STROKE}
                                                className="mt-0.5 shrink-0 text-[#A8670A] dark:text-[#E8A958]"
                                            />
                                            Before a deadline, payment, or
                                            application, recheck the linked
                                            official page or ask the responsible
                                            authority.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {actionable && (
                <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[#EEEAE0] bg-[#FCFBF7] px-4 py-3 dark:border-[#35342C] dark:bg-[#222119]">
                    {status === 'not_started' && (
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() => updateStatus('in_progress')}
                            className="min-h-11 cursor-pointer rounded-[9px] border border-[#DCD7CC] bg-white px-3 text-[12px] font-bold text-[#555149] transition hover:border-primary hover:text-primary disabled:cursor-wait disabled:opacity-60 motion-reduce:transition-none dark:border-[#454339] dark:bg-[#292820] dark:text-[#D8D4CA]"
                        >
                            Start
                        </button>
                    )}
                    {status === 'in_progress' && (
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() => updateStatus('submitted')}
                            className="min-h-11 cursor-pointer rounded-[9px] border border-[#DCD7CC] bg-white px-3 text-[12px] font-bold text-[#555149] transition hover:border-primary hover:text-primary disabled:cursor-wait disabled:opacity-60 motion-reduce:transition-none dark:border-[#454339] dark:bg-[#292820] dark:text-[#D8D4CA]"
                        >
                            Mark submitted
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={processing}
                        onClick={() => updateStatus(nextStatus(status))}
                        className={`min-h-11 cursor-pointer rounded-[9px] border-0 px-3 text-[12px] font-bold transition disabled:cursor-wait disabled:opacity-60 motion-reduce:transition-none ${
                            status === 'done'
                                ? 'bg-[#EFEDE7] text-[#555149] hover:bg-[#E5E2DB] dark:bg-[#302E24] dark:text-[#D8D4CA]'
                                : 'bg-primary text-white hover:bg-primary/90'
                        }`}
                    >
                        {statusActionLabel(status)}
                    </button>
                </div>
            )}
        </article>
    );
}

function DetailBlock({
    title,
    icon,
    children,
}: {
    title: string;
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="mt-4">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-[0.06em] text-[#777166] uppercase dark:text-[#B9B4A8]">
                {icon}
                {title}
            </div>
            {children}
        </div>
    );
}

function CoverageNotice() {
    return (
        <article className="rounded-[14px] border border-[#D9CDB4] bg-[#FFF9EC] p-4 dark:border-[#5C5037] dark:bg-[#292419]">
            <div className="flex items-start gap-3">
                <IconAlertTriangle
                    size={20}
                    stroke={ICON_STROKE}
                    className="mt-0.5 shrink-0 text-[#A8670A] dark:text-[#E8A958]"
                />
                <div>
                    <h3 className="text-[14px] font-bold text-[#3B3528] dark:text-[#F0E9DA]">
                        This part needs a reviewed rule
                    </h3>
                    <p className="mt-1 text-[12.5px] leading-5 text-[#6B6252] dark:text-[#BEB5A5]">
                        We cannot currently verify a complete workflow for your
                        situation. We will continue showing the steps that are
                        independently confirmed, but we will not guess about the
                        unresolved part.
                    </p>
                </div>
            </div>
        </article>
    );
}

function InformationNeededNotice({ item }: { item: CasePlanItem }) {
    return (
        <article className="rounded-[14px] border border-[#DED8C8] bg-[#FAF7EF] p-4 dark:border-[#4A4638] dark:bg-[#25231A]">
            <div className="flex items-start gap-3">
                <IconInfoCircle
                    size={19}
                    stroke={ICON_STROKE}
                    className="mt-0.5 shrink-0 text-[#766D59] dark:text-[#C5BDA9]"
                />
                <div>
                    <h3 className="text-[13.5px] font-bold text-[#38362F] dark:text-[#E7E3D8]">
                        A possible step needs more information
                    </h3>
                    <p className="mt-1 text-[12px] leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                        We are not treating this as applicable until the
                        relevant detail is confirmed.
                    </p>
                    {(item.questions ?? []).length > 0 && (
                        <ul className="mt-2.5 space-y-2 border-t border-[#E7E1D4] pt-2.5 dark:border-[#403D32]">
                            {(item.questions ?? []).map((question) => (
                                <li key={question.question}>
                                    <p className="text-[11.5px] font-semibold text-[#4B4942] dark:text-[#D8D4CA]">
                                        {question.question}
                                    </p>
                                    <p className="mt-0.5 text-[10.5px] leading-4 text-[#77736B] dark:text-[#AAA89F]">
                                        {question.why}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </article>
    );
}
