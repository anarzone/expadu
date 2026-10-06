export function ComposerLoading({
    label = 'Working on it…',
    compact = false,
}: {
    label?: string;
    compact?: boolean;
}) {
    return (
        <span
            role="status"
            className={`composer-loading ${compact ? 'is-compact' : ''}`}
        >
            <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <path
                    className="spark-outline"
                    d="m16 2 4 10 10 4-10 4-4 10-4-10-10-4 10-4Z"
                />
                <g className="spark-tips">
                    <path d="m12 12 4-10 4 10" />
                    <path d="m20 12 10 4-10 4" />
                    <path d="m20 20-4 10-4-10" />
                    <path d="m12 20-10-4 10-4" />
                </g>
            </svg>
            <span className={compact ? 'sr-only' : ''}>{label}</span>
        </span>
    );
}
