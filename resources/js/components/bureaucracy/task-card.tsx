import type { TaskData } from '@/pages/bureaucracy';

export function TaskCard({
    task,
    expanded,
    onToggleExpand,
    onToggleDone,
}: {
    task: TaskData;
    expanded: boolean;
    onToggleExpand: () => void;
    onToggleDone: () => void;
}) {
    return (
        <div
            onClick={onToggleExpand}
            className="group flex cursor-pointer items-start gap-3.5 border-b transition-colors last:border-b-0"
            style={{
                padding: '16px 24px',
                borderColor: '#E2DFD6',
                opacity: task.done ? 0.55 : 1,
            }}
            onMouseEnter={(e) => {
                (e.currentTarget as HTMLDivElement).style.background = '#EFEDE7';
                if (task.done) (e.currentTarget as HTMLDivElement).style.opacity = '0.7';
            }}
            onMouseLeave={(e) => {
                (e.currentTarget as HTMLDivElement).style.background = 'transparent';
                if (task.done) (e.currentTarget as HTMLDivElement).style.opacity = '0.55';
            }}
        >
            {/* Checkbox */}
            <div
                onClick={(e) => {
                    e.stopPropagation();
                    onToggleDone();
                }}
                className="flex shrink-0 items-center justify-center"
                style={{
                    width: 22,
                    height: 22,
                    borderRadius: 6,
                    border: task.done ? '2px solid #0A7C52' : '2px solid #E2DFD6',
                    background: task.done ? '#0A7C52' : 'transparent',
                    marginTop: 1,
                    transition: 'all .25s cubic-bezier(.32,1,.4,1)',
                    cursor: 'pointer',
                }}
                onMouseEnter={(e) => {
                    if (!task.done) (e.currentTarget as HTMLDivElement).style.borderColor = '#1A4CD4';
                }}
                onMouseLeave={(e) => {
                    if (!task.done) (e.currentTarget as HTMLDivElement).style.borderColor = '#E2DFD6';
                }}
            >
                {task.done && (
                    <span style={{ color: 'white', fontSize: 12, fontWeight: 700 }}>✓</span>
                )}
            </div>

            {/* Body */}
            <div style={{ flex: 1, minWidth: 0 }}>
                <div
                    style={{
                        fontSize: 14,
                        fontWeight: 600,
                        marginBottom: 3,
                        textDecoration: task.done ? 'line-through' : 'none',
                        color: task.done ? '#AAA89F' : '#18170F',
                    }}
                >
                    {task.title}
                </div>
                <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.5, marginBottom: 6 }}>
                    {task.desc}
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span
                        style={{
                            fontSize: 10,
                            fontWeight: 700,
                            padding: '2px 7px',
                            borderRadius: 20,
                            textTransform: 'uppercase',
                            letterSpacing: '0.04em',
                            background: task.tag.bg,
                            color: task.tag.color,
                        }}
                    >
                        {task.tag.label}
                    </span>
                    {!task.done && task.link && (
                        <a
                            href={task.link}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={(e) => e.stopPropagation()}
                            className="hover:underline"
                            style={{
                                fontSize: 12,
                                color: '#1A4CD4',
                                fontWeight: 500,
                                textDecoration: 'none',
                            }}
                        >
                            {task.linkLabel}
                        </a>
                    )}
                </div>

                {/* Expanded section */}
                {expanded && (
                    <div
                        style={{
                            background: '#EFEDE7',
                            borderRadius: 9,
                            padding: 14,
                            marginTop: 10,
                            borderLeft: '3px solid #1A4CD4',
                        }}
                    >
                        {/* Steps */}
                        <ol style={{ listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10, padding: 0 }}>
                            {task.steps.map((step, i) => (
                                <li key={i} style={{ fontSize: 13, color: '#6B6860', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
                                    <span
                                        style={{
                                            width: 18,
                                            height: 18,
                                            borderRadius: '50%',
                                            background: '#EBF0FD',
                                            color: '#1A4CD4',
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
                                    <span>{step}</span>
                                </li>
                            ))}
                        </ol>

                        {/* Documents needed */}
                        {task.docs.length > 0 && (
                            <>
                                <div style={{ fontSize: 12, fontWeight: 600, color: '#6B6860', marginBottom: 6 }}>
                                    📄 Documents needed:
                                </div>
                                <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}>
                                    {task.docs.map((doc, i) => (
                                        <span
                                            key={i}
                                            style={{
                                                fontSize: 11,
                                                padding: '3px 8px',
                                                borderRadius: 20,
                                                background: 'white',
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

                        {/* Time estimate */}
                        <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 8, display: 'flex', alignItems: 'center', gap: 5 }}>
                            ⏱ {task.time}
                        </div>

                        {/* Link */}
                        {task.link && (
                            <a
                                href={task.link}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={(e) => e.stopPropagation()}
                                className="hover:underline"
                                style={{
                                    display: 'inline-block',
                                    marginTop: 8,
                                    fontSize: 12,
                                    color: '#1A4CD4',
                                    fontWeight: 500,
                                    textDecoration: 'none',
                                }}
                            >
                                {task.linkLabel}
                            </a>
                        )}
                    </div>
                )}
            </div>

            {/* Arrow */}
            <span
                style={{
                    fontSize: 14,
                    color: '#AAA89F',
                    flexShrink: 0,
                    marginTop: 2,
                    transition: 'transform .2s',
                }}
            >
                {expanded ? '↑' : '›'}
            </span>
        </div>
    );
}
