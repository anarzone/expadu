/* The poster's line icons, from the mock's connected/icons.js and
   events-next/poster-art.js. Temporary: replaced by the shared
   `<Icon variant="line">` once F2 (EXP-127 track) is in launch. */

const paths = {
    people: '<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4m2-16a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v2"/>',
    arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    calendar:
        '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3"/>',
    'calendar-add':
        '<path d="M8 2v4m8-4v4M3 10h18M12 21H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v6M18 16v6m-3-3h6"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
    spark: '<path d="m12 2 2.8 7.2L22 12l-7.2 2.8L12 22l-2.8-7.2L2 12l7.2-2.8L12 2Z"/>',
    coffee: '<path d="M3 8h14v7a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm14 1h2a3 3 0 0 1 0 6h-2M6 2v3m5-3v3M2 22h18"/>',
    ball: '<circle cx="12" cy="12" r="9"/><path d="m12 8 4 3-2 5h-4l-2-5 4-3Zm0-5v5M3 10l5 1m2 5-3 4m7-4 3 4m-1-9 5-1"/>',
    chat: '<path d="M3 3h18v14H9l-6 5V3Z"/><path d="M7 8h10M7 12h7"/>',
    museum: '<path d="m2 8 10-6 10 6H2Zm2 12h16M6 11v6m6-6v6m6-6v6M2 22h20"/>',
    turn: '<path d="M20 11a8 8 0 1 0-.6 4"/><path d="M20 20v-5h-5"/>',
} as const;

export type PosterIconName = keyof typeof paths;

export function PosterIcon({ name }: { name: PosterIconName }) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
            focusable="false"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.7}
            strokeLinecap="round"
            strokeLinejoin="round"
            dangerouslySetInnerHTML={{ __html: paths[name] }}
        />
    );
}
