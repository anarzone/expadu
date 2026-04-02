import { useRef, useEffect, type MouseEvent } from 'react';

export type StepAction = { label: string; url: string };
export type StepTip = { text: string; author: string };
export type StepTag = { label: string; bg: string; color: string };

export type StepData = {
    id: number;
    phase: string;
    done: boolean;
    skipped: boolean;
    urgent: boolean;
    upcoming: boolean;
    title: string;
    desc: string;
    tag: StepTag;
    timing: string;
    link: string | null;
    instructions: string[];
    docs: string[];
    tip: StepTip | null;
    actions: StepAction[];
};

export function StepCard({
    step,
    expanded,
    animationDelay,
    onToggle,
    onCheckbox,
    onMarkDone,
    onSkip,
}: {
    step: StepData;
    expanded: boolean;
    animationDelay: number;
    onToggle: () => void;
    onCheckbox: (e: MouseEvent) => void;
    onMarkDone: () => void;
    onSkip: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (expanded && ref.current) {
            setTimeout(
                () =>
                    ref.current?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                    }),
                50,
            );
        }
    }, [expanded]);

    const cbClass = step.done ? 'done' : step.skipped ? 'skipped' : '';
    const titleClass = step.done ? 'done-text' : '';

    return (
        <div
            ref={ref}
            onClick={() => {
                if (!step.upcoming) onToggle();
            }}
            style={{
                display: 'flex',
                alignItems: 'flex-start',
                gap: 14,
                padding: '16px 24px',
                borderBottom: '1px solid #E2DFD6',
                cursor: step.upcoming ? 'default' : 'pointer',
                transition: 'background 0.15s',
                opacity: step.upcoming ? 0.55 : 1,
                animationDelay: `${animationDelay}s`,
            }}
            className="step-card-row"
            onMouseEnter={(e) => {
                if (!step.upcoming)
                    (e.currentTarget as HTMLDivElement).style.background =
                        '#EFEDE7';
            }}
            onMouseLeave={(e) => {
                (e.currentTarget as HTMLDivElement).style.background =
                    'transparent';
            }}
        >
            {/* Checkbox */}
            <Checkbox
                state={cbClass}
                upcoming={step.upcoming}
                onClick={onCheckbox}
            />

            {/* Body */}
            <div style={{ flex: 1, minWidth: 0 }}>
                <div
                    style={{
                        fontSize: 14,
                        fontWeight: 600,
                        marginBottom: 3,
                        transition: 'color 0.2s',
                        color: step.done ? '#AAA89F' : '#18170F',
                        textDecoration: step.done ? 'line-through' : 'none',
                    }}
                >
                    {step.title}
                </div>
                <div
                    style={{
                        fontSize: 13,
                        color: '#6B6860',
                        lineHeight: 1.5,
                        marginBottom: 6,
                    }}
                >
                    {step.desc}
                </div>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        flexWrap: 'wrap',
                    }}
                >
                    <span
                        style={{
                            fontSize: 10,
                            fontWeight: 700,
                            padding: '2px 7px',
                            borderRadius: 20,
                            textTransform: 'uppercase',
                            letterSpacing: '0.04em',
                            background: step.tag.bg,
                            color: step.tag.color,
                        }}
                    >
                        {step.tag.label}
                    </span>
                    <span
                        style={{
                            fontSize: 11,
                            color: '#AAA89F',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 4,
                        }}
                    >
                        ⏱ {step.timing}
                    </span>
                    {step.link && !step.done && (
                        <a
                            href={step.link}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={(e) => e.stopPropagation()}
                            style={{
                                fontSize: 12,
                                color: '#1A4CD4',
                                fontWeight: 500,
                                cursor: 'pointer',
                                textDecoration: 'none',
                            }}
                            onMouseEnter={(e) => {
                                (
                                    e.currentTarget as HTMLAnchorElement
                                ).style.textDecoration = 'underline';
                            }}
                            onMouseLeave={(e) => {
                                (
                                    e.currentTarget as HTMLAnchorElement
                                ).style.textDecoration = 'none';
                            }}
                        >
                            Book now ↗
                        </a>
                    )}
                </div>

                {/* Expanded details */}
                {expanded && !step.upcoming && (
                    <ExpandedDetails
                        step={step}
                        onMarkDone={onMarkDone}
                        onSkip={onSkip}
                    />
                )}
            </div>

            {/* Arrow or lock */}
            {step.upcoming ? (
                <span
                    style={{
                        fontSize: 14,
                        color: '#AAA89F',
                        flexShrink: 0,
                        marginTop: 2,
                    }}
                >
                    🔒
                </span>
            ) : (
                <span
                    style={{
                        fontSize: 14,
                        color: '#AAA89F',
                        flexShrink: 0,
                        marginTop: 1,
                        transition: 'transform 0.2s',
                    }}
                    className="step-arrow"
                >
                    {expanded ? '↑' : '›'}
                </span>
            )}
        </div>
    );
}

function Checkbox({
    state,
    upcoming,
    onClick,
}: {
    state: string;
    upcoming: boolean;
    onClick: (e: MouseEvent) => void;
}) {
    const baseStyle: React.CSSProperties = {
        width: 22,
        height: 22,
        borderRadius: 6,
        border: '2px solid #E2DFD6',
        flexShrink: 0,
        marginTop: 1,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        transition: 'all 0.25s cubic-bezier(0.32, 1, 0.4, 1)',
        cursor: upcoming ? 'default' : 'pointer',
    };

    if (state === 'done') {
        baseStyle.background = '#0A7C52';
        baseStyle.borderColor = '#0A7C52';
    } else if (state === 'skipped') {
        baseStyle.background = '#EFEDE7';
        baseStyle.borderColor = '#E2DFD6';
    }

    return (
        <div
            style={baseStyle}
            onClick={(e) => {
                if (!upcoming) onClick(e);
            }}
            onMouseEnter={(e) => {
                if (!upcoming && state !== 'done')
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        '#1A4CD4';
            }}
            onMouseLeave={(e) => {
                if (state === 'done')
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        '#0A7C52';
                else if (state === 'skipped')
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        '#E2DFD6';
                else
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        '#E2DFD6';
            }}
        >
            {state === 'done' && (
                <span style={{ color: 'white', fontSize: 12, fontWeight: 700 }}>
                    ✓
                </span>
            )}
            {state === 'skipped' && (
                <span
                    style={{ color: '#AAA89F', fontSize: 11, fontWeight: 700 }}
                >
                    —
                </span>
            )}
        </div>
    );
}

function ExpandedDetails({
    step,
    onMarkDone,
    onSkip,
}: {
    step: StepData;
    onMarkDone: () => void;
    onSkip: () => void;
}) {
    return (
        <div
            style={{
                background: '#EBF0FD',
                borderRadius: 9,
                padding: 14,
                marginTop: 10,
                borderLeft: '3px solid #1A4CD4',
            }}
        >
            <div
                style={{
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#1A4CD4',
                    marginBottom: 8,
                }}
            >
                How to do this
            </div>
            <ol
                style={{
                    listStyle: 'none',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 7,
                    marginBottom: 10,
                    padding: 0,
                }}
            >
                {step.instructions.map((inst, i) => (
                    <li
                        key={i}
                        style={{
                            fontSize: 13,
                            color: '#6B6860',
                            display: 'flex',
                            gap: 8,
                            alignItems: 'flex-start',
                            lineHeight: 1.5,
                        }}
                    >
                        <span
                            style={{
                                width: 18,
                                height: 18,
                                borderRadius: '50%',
                                background: '#1A4CD4',
                                color: 'white',
                                fontSize: 10,
                                fontWeight: 700,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                flexShrink: 0,
                                marginTop: 1,
                            }}
                        >
                            {i + 1}
                        </span>
                        <span>{inst}</span>
                    </li>
                ))}
            </ol>

            {step.docs.length > 0 && (
                <>
                    <div
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.06em',
                            color: '#AAA89F',
                            marginBottom: 6,
                        }}
                    >
                        Documents needed
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            gap: 5,
                            flexWrap: 'wrap',
                            marginBottom: 10,
                        }}
                    >
                        {step.docs.map((doc, i) => (
                            <span
                                key={i}
                                style={{
                                    fontSize: 11,
                                    padding: '3px 8px',
                                    borderRadius: 20,
                                    background: '#FFFFFF',
                                    border: '1px solid #E2DFD6',
                                    color: '#6B6860',
                                }}
                            >
                                {doc}
                            </span>
                        ))}
                    </div>
                </>
            )}

            {step.tip && (
                <div
                    style={{
                        background: '#FFFFFF',
                        border: '1px solid #E2DFD6',
                        borderRadius: 9,
                        padding: '10px 12px',
                        marginTop: 10,
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: 8,
                    }}
                >
                    <span style={{ fontSize: 14, flexShrink: 0 }}>💬</span>
                    <div>
                        <div
                            style={{
                                fontSize: 12,
                                color: '#6B6860',
                                lineHeight: 1.5,
                                flex: 1,
                            }}
                        >
                            {step.tip.text}
                        </div>
                        <div
                            style={{
                                fontSize: 10,
                                color: '#AAA89F',
                                marginTop: 3,
                            }}
                        >
                            {step.tip.author} · Community tip
                        </div>
                    </div>
                </div>
            )}

            <div
                style={{
                    display: 'flex',
                    gap: 7,
                    flexWrap: 'wrap',
                    marginTop: 10,
                }}
            >
                {step.actions.map((a, i) => (
                    <button
                        key={i}
                        onClick={(e) => {
                            e.stopPropagation();
                            window.open(a.url, '_blank', 'noopener');
                        }}
                        style={{
                            padding: '7px 13px',
                            borderRadius: 9,
                            fontSize: 12,
                            fontWeight: 600,
                            cursor: 'pointer',
                            transition: 'all 0.2s',
                            border: 'none',
                            fontFamily: "'Geist', sans-serif",
                            background: '#1A4CD4',
                            color: 'white',
                        }}
                        onMouseEnter={(e) => {
                            (
                                e.currentTarget as HTMLButtonElement
                            ).style.background = '#1540B8';
                        }}
                        onMouseLeave={(e) => {
                            (
                                e.currentTarget as HTMLButtonElement
                            ).style.background = '#1A4CD4';
                        }}
                    >
                        {a.label} ↗
                    </button>
                ))}
                {!step.done && (
                    <>
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                onMarkDone();
                            }}
                            style={{
                                padding: '7px 13px',
                                borderRadius: 9,
                                fontSize: 12,
                                fontWeight: 600,
                                cursor: 'pointer',
                                transition: 'all 0.2s',
                                border: '1px solid #E2DFD6',
                                fontFamily: "'Geist', sans-serif",
                                background: '#FFFFFF',
                                color: '#6B6860',
                            }}
                            onMouseEnter={(e) => {
                                (
                                    e.currentTarget as HTMLButtonElement
                                ).style.background = '#EFEDE7';
                            }}
                            onMouseLeave={(e) => {
                                (
                                    e.currentTarget as HTMLButtonElement
                                ).style.background = '#FFFFFF';
                            }}
                        >
                            Mark done ✓
                        </button>
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                onSkip();
                            }}
                            style={{
                                padding: '7px 13px',
                                borderRadius: 9,
                                fontSize: 12,
                                fontWeight: 600,
                                cursor: 'pointer',
                                transition: 'all 0.2s',
                                border: '1px solid #E2DFD6',
                                fontFamily: "'Geist', sans-serif",
                                background: '#FFFFFF',
                                color: '#6B6860',
                            }}
                            onMouseEnter={(e) => {
                                (
                                    e.currentTarget as HTMLButtonElement
                                ).style.background = '#EFEDE7';
                            }}
                            onMouseLeave={(e) => {
                                (
                                    e.currentTarget as HTMLButtonElement
                                ).style.background = '#FFFFFF';
                            }}
                        >
                            Skip
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}
