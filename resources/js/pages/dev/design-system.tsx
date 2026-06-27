import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import {
    Button,
    CountBadge,
    Field,
    IconButton,
    Pill,
    Segmented,
    Surface,
    Tag,
    Tile,
    categoryClass,
} from '@/components/ds';
import { cn } from '@/lib/utils';

/* ── tiny inline icons (currentColor) so the page stays self-contained ── */
const Svg = (props: React.ComponentProps<'svg'>) => (
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.9}
        strokeLinecap="round"
        strokeLinejoin="round"
        {...props}
    />
);
const Arrow = () => (
    <Svg>
        <path d="M5 12h14M13 6l6 6-6 6" />
    </Svg>
);
const Plus = () => (
    <Svg>
        <path d="M12 5v14M5 12h14" />
    </Svg>
);
const Pin = ({ dot = false }: { dot?: boolean }) => (
    <Svg>
        <path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z" />
        {dot && <circle cx="12" cy="10" r="2.2" />}
    </Svg>
);
const Star = () => (
    <Svg>
        <path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z" />
    </Svg>
);
const Triangle = () => (
    <Svg>
        <path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
    </Svg>
);

/* ── section scaffold ── */
function Section({
    n,
    eyebrow,
    title,
    lead,
    children,
}: {
    n: string;
    eyebrow: string;
    title: string;
    lead?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="border-t border-border py-[50px]">
            <div className="mb-7 max-w-[680px]">
                <p className="font-mono text-[11px] tracking-[0.16em] text-text-3 uppercase">
                    {n} — {eyebrow}
                </p>
                <h2 className="mt-2.5 font-display text-[29px] font-medium tracking-[-0.015em]">
                    {title}
                </h2>
                {lead && (
                    <p className="mt-2 max-w-[640px] text-[15px] leading-[1.55] text-text-2">
                        {lead}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function Demo({
    label,
    tag,
    children,
    className,
}: {
    label: string;
    tag: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Surface className={cn('p-[22px]', className)}>
            <div className="mb-4 flex items-center justify-between">
                <span className="text-[13.5px] font-semibold">{label}</span>
                <span className="font-mono text-[10px] text-text-3">{tag}</span>
            </div>
            {children}
        </Surface>
    );
}

const NEUTRALS = [
    { name: 'Canvas', token: '--bg', hex: '#faf6ee', role: 'App background' },
    {
        name: 'Surface',
        token: '--surface',
        hex: '#fffdf8',
        role: 'Cards, sheets',
    },
    {
        name: 'Surface 2',
        token: '--surface-2',
        hex: '#f1ebdf',
        role: 'Insets, tracks',
    },
    { name: 'Border', token: '--border', hex: '#e6ddcb', role: 'Hairlines' },
    { name: 'Ink', token: '--ink', hex: '#211d15', role: 'Primary text' },
    {
        name: 'Text 2',
        token: '--text-2',
        hex: '#6e6657',
        role: 'Body, secondary',
    },
    {
        name: 'Text 3',
        token: '--text-3',
        hex: '#a89f8c',
        role: 'Captions, mono',
    },
    {
        name: 'Primary',
        token: '--primary',
        hex: '#ff3902',
        role: 'Brand — actions',
    },
];

const ACCENTS: {
    name: string;
    token: string;
    use: string;
    tone: 'success' | 'warn' | 'danger' | 'navy';
}[] = [
    {
        name: 'Success',
        token: '--success',
        use: 'Confirmations, “open now”, free events, on-time transit.',
        tone: 'success',
    },
    {
        name: 'Amber / Warn',
        token: '--amber · --warn',
        use: 'Delays, “leaving soon”, a heads-up that isn’t an error.',
        tone: 'warn',
    },
    {
        name: 'Danger',
        token: '--danger',
        use: 'Cancellations, disruptions, destructive actions, unresolved counts.',
        tone: 'danger',
    },
    {
        name: 'Navy',
        token: '--navy',
        use: 'A calm category tint (culture, language) where orange would shout.',
        tone: 'navy',
    },
];

const PRINCIPLES = [
    {
        n: '01',
        t: 'Orange acts, cyan locates',
        d: 'If it isn’t the primary action or the active selection, it isn’t orange. If it isn’t origin or distance, it isn’t cyan.',
    },
    {
        n: '02',
        t: 'Neutrals do the heavy lifting',
        d: 'Structure, labels, resting chips and most text live in warm neutrals. Accent is the exception, not the field.',
    },
    {
        n: '03',
        t: 'One CTA per view',
        d: 'A screen has a single most-important action. Two orange buttons competing means one steps down to secondary.',
    },
    {
        n: '04',
        t: 'Status colors stay literal',
        d: 'Green = good/free/open, amber = heads-up, red = stop/cancel. Never decorative, so they always mean something.',
    },
    {
        n: '05',
        t: 'Soft tints over solid fills',
        d: 'Prefer a tinted icon or soft chip to a fully saturated card. Saturation is a budget; spend it on what matters.',
    },
    {
        n: '06',
        t: 'Both themes, same roles',
        d: 'Dark mode brightens accents for contrast but never reassigns meaning. The mapping holds in light and dark.',
    },
];

const CATEGORY_TINTS = [
    { label: 'Parks', cat: 'park' },
    { label: 'Culture', cat: 'culture' },
    { label: 'Pitches', cat: 'pitch' },
    { label: 'Swimming', cat: 'swimming' },
    { label: 'Cafés', cat: 'cafe' },
    { label: 'Events', cat: 'event' },
];

export default function DesignSystem() {
    const [mode, setMode] = useState('walk');
    const [cat, setCat] = useState('parks');
    // Adopt the app's current theme on first render (read, don't set-in-effect).
    const [dark, setDark] = useState(
        () =>
            typeof document !== 'undefined' &&
            document.documentElement.classList.contains('dark'),
    );

    // Restore the app's theme when leaving this page (we drive <html> below).
    useEffect(() => {
        const original = document.documentElement.classList.contains('dark');

        return () => {
            document.documentElement.classList.toggle('dark', original);
        };
    }, []);

    // The --color-* token indirection resolves at :root, so dark mode must
    // toggle on <html> — a nested wrapper class won't flip the tokens.
    useEffect(() => {
        document.documentElement.classList.toggle('dark', dark);
    }, [dark]);

    return (
        <div className="min-h-screen bg-background pb-20 text-foreground">
            <Head title="Design system" />

            {/* topbar */}
            <div className="sticky top-0 z-20 border-b border-border bg-background/85 backdrop-blur-md">
                <div className="mx-auto flex max-w-[1060px] items-center justify-between px-11 py-[15px]">
                    <div className="flex items-center gap-2.5">
                        <div className="flex size-[34px] items-center justify-center rounded-[10px] bg-primary font-display text-[18px] font-semibold text-white">
                            E
                        </div>
                        <div>
                            <div className="font-display text-[17px] font-semibold">
                                Expadu
                            </div>
                            <div className="font-mono text-[10px] tracking-[0.08em] text-text-3">
                                DESIGN SYSTEM · v4
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => setDark((d) => !d)}
                        className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-border bg-card px-3.5 py-[7px] text-[12.5px] font-semibold"
                    >
                        {dark ? 'Light' : 'Dark'} mode
                    </button>
                </div>
            </div>

            <div className="mx-auto max-w-[1060px] px-11">
                {/* hero */}
                <div className="pt-[54px] pb-1.5">
                    <p className="font-mono text-[11px] tracking-[0.16em] text-text-3 uppercase">
                        Foundations · Cologne newcomer companion
                    </p>
                    <h1 className="mt-4 font-display text-[50px] leading-[1.03] font-medium tracking-[-0.025em]">
                        One warm system,
                        <br />
                        two colors with jobs.
                    </h1>
                    <p className="mt-[18px] max-w-[640px] text-[17px] leading-[1.6] text-text-2">
                        Expadu runs on a warm paper canvas with a single
                        hot-orange brand color and a cyan companion. The rule
                        that holds it together:{' '}
                        <strong className="font-semibold text-foreground">
                            orange acts, cyan locates
                        </strong>{' '}
                        — and neither bleeds into the other’s role.
                    </p>
                    <div className="mt-[22px] flex flex-wrap gap-2">
                        <span className="inline-flex items-center gap-2 rounded-full bg-primary-soft px-3.5 py-[7px] text-[12.5px] font-semibold text-primary">
                            ● Primary — actions
                        </span>
                        <span className="inline-flex items-center gap-2 rounded-full bg-cyan-soft px-3.5 py-[7px] text-[12.5px] font-semibold text-cyan-h">
                            ● Cyan — origin &amp; distance
                        </span>
                        <span className="inline-flex items-center gap-2 rounded-full bg-surface-2 px-3.5 py-[7px] text-[12.5px] font-semibold text-text-2">
                            ● Neutrals — everything else
                        </span>
                    </div>
                </div>

                {/* 01 neutrals */}
                <Section
                    n="01"
                    eyebrow="Canvas"
                    title="Warm neutrals"
                    lead="The paper-and-ink base. Every screen is built from these before a single accent is added — which is what keeps the accents loud."
                >
                    <div className="grid grid-cols-2 gap-[15px] sm:grid-cols-4">
                        {NEUTRALS.map((s) => (
                            <Surface key={s.token} className="overflow-hidden">
                                <div
                                    className="flex h-[78px] items-end p-[9px]"
                                    style={{ background: s.hex }}
                                >
                                    <span
                                        className="rounded-[6px] px-[7px] py-[3px] font-mono text-[11px] font-medium"
                                        style={{
                                            background: 'rgba(20,16,8,.06)',
                                            color: '#211d15',
                                        }}
                                    >
                                        {s.hex}
                                    </span>
                                </div>
                                <div className="px-[13px] pt-[11px] pb-[13px]">
                                    <div className="text-[13.5px] font-semibold">
                                        {s.name}
                                    </div>
                                    <div className="mt-0.5 font-mono text-[10.5px] text-text-3">
                                        {s.token}
                                    </div>
                                    <div className="mt-[7px] text-[11.5px] leading-[1.45] text-text-2">
                                        {s.role}
                                    </div>
                                </div>
                            </Surface>
                        ))}
                    </div>
                </Section>

                {/* 02 the two voices */}
                <Section
                    n="02"
                    eyebrow="The two voices"
                    title="Primary & companion"
                    lead="Two colors carry meaning in Expadu. Keep them apart and the interface reads instantly; mix them and everything turns to noise."
                >
                    <div className="grid gap-[18px] md:grid-cols-2">
                        <Surface className="p-[22px]">
                            <div className="flex items-center gap-2.5">
                                <span className="size-[30px] rounded-[9px] bg-primary" />
                                <div>
                                    <div className="text-[13.5px] font-semibold">
                                        Primary — Expadu Orange
                                    </div>
                                    <div className="font-mono text-[10.5px] text-text-3">
                                        --primary · #ff3902
                                    </div>
                                </div>
                            </div>
                            <p className="mt-3 text-[13px] leading-[1.55] text-text-2">
                                The one thing the eye should jump to. Reserved
                                for{' '}
                                <strong className="text-foreground">
                                    the active choice and the forward action
                                </strong>
                                : selected filter, primary button, FAB, current
                                nav item.
                            </p>
                            <div className="mt-3 flex gap-1.5">
                                <span className="h-[30px] flex-1 rounded-[7px] bg-primary" />
                                <span className="h-[30px] flex-1 rounded-[7px] bg-primary-hover" />
                                <span className="h-[30px] flex-1 rounded-[7px] bg-primary-soft" />
                            </div>
                            <div className="mt-3 font-mono text-[10.5px] text-text-3">
                                solid · hover · soft
                            </div>
                        </Surface>
                        <Surface className="p-[22px]">
                            <div className="flex items-center gap-2.5">
                                <span className="size-[30px] rounded-[9px] bg-cyan" />
                                <div>
                                    <div className="text-[13.5px] font-semibold">
                                        Companion — Cyan
                                    </div>
                                    <div className="font-mono text-[10.5px] text-text-3">
                                        --cyan · #05badd
                                    </div>
                                </div>
                            </div>
                            <p className="mt-3 text-[13px] leading-[1.55] text-text-2">
                                The “where am I measuring from” voice.{' '}
                                <strong className="text-foreground">
                                    Only ever origin &amp; distance
                                </strong>
                                : the “from” pill, the distance picker, “X min
                                away”. Never a generic button.
                            </p>
                            <div className="mt-3 flex gap-1.5">
                                <span className="h-[30px] flex-1 rounded-[7px] bg-cyan" />
                                <span className="h-[30px] flex-1 rounded-[7px] bg-cyan-h" />
                                <span className="h-[30px] flex-1 rounded-[7px] bg-cyan-soft" />
                            </div>
                            <div className="mt-3 font-mono text-[10.5px] text-text-3">
                                solid · text · soft
                            </div>
                        </Surface>
                    </div>
                </Section>

                {/* 03 semantic accents */}
                <Section
                    n="03"
                    eyebrow="Semantic accents"
                    title="Status & support colors"
                    lead="A small supporting cast, each with one job. These free orange from having to mean “important” everywhere."
                >
                    <div className="flex flex-col">
                        {ACCENTS.map((a) => (
                            <div
                                key={a.name}
                                className="grid grid-cols-[170px_1fr] items-center gap-4 border-b border-border py-3.5 sm:grid-cols-[170px_1fr_80px_80px]"
                            >
                                <div className="flex items-center gap-2.5">
                                    <span
                                        className={cn(
                                            'size-[30px] rounded-[9px]',
                                            a.tone === 'success' &&
                                                'bg-success',
                                            a.tone === 'warn' && 'bg-amber',
                                            a.tone === 'danger' && 'bg-danger',
                                            a.tone === 'navy' && 'bg-navy',
                                        )}
                                    />
                                    <div>
                                        <div className="text-[13.5px] font-semibold">
                                            {a.name}
                                        </div>
                                        <div className="mt-px font-mono text-[10.5px] text-text-3">
                                            {a.token}
                                        </div>
                                    </div>
                                </div>
                                <div className="text-[12.5px] leading-[1.5] text-text-2">
                                    {a.use}
                                </div>
                                <Tag
                                    tone={a.tone}
                                    className="hidden justify-center sm:flex"
                                >
                                    soft
                                </Tag>
                                <div className="hidden items-center justify-center sm:flex">
                                    {a.name === 'Danger' ? (
                                        <CountBadge>5</CountBadge>
                                    ) : (
                                        <span className="font-mono text-[10.5px] text-text-3">
                                            —
                                        </span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </Section>

                {/* 04 typography */}
                <Section
                    n="04"
                    eyebrow="Typography"
                    title="Fraunces & Geist"
                    lead="A warm serif for moments of voice, a clean grotesque for the working interface, and a mono for data."
                >
                    <div className="flex flex-col">
                        {[
                            {
                                sample: (
                                    <span className="font-display text-[40px] leading-none font-medium tracking-[-0.025em]">
                                        Your Tuesday, sorted
                                    </span>
                                ),
                                role: 'Display',
                                meta: 'Fraunces · 500 · -2.5%',
                            },
                            {
                                sample: (
                                    <span className="font-display text-[27px] font-medium tracking-[-0.015em]">
                                        Section heading
                                    </span>
                                ),
                                role: 'Heading',
                                meta: 'Fraunces · 500',
                            },
                            {
                                sample: (
                                    <span className="text-[17px] font-semibold">
                                        Card title &amp; UI label
                                    </span>
                                ),
                                role: 'UI Strong',
                                meta: 'Geist · 600',
                            },
                            {
                                sample: (
                                    <span className="text-[15px] leading-[1.6] text-text-2">
                                        Body copy carries explanations in Geist
                                        Regular at a comfortable measure.
                                    </span>
                                ),
                                role: 'Body',
                                meta: 'Geist · 400',
                            },
                            {
                                sample: (
                                    <span className="font-mono text-[13px] tracking-[0.04em] text-text-2">
                                        14:32 · LIVE · +3 MIN · 850 M
                                    </span>
                                ),
                                role: 'Data / Mono',
                                meta: 'Geist Mono · 500',
                            },
                        ].map((row) => (
                            <div
                                key={row.role}
                                className="flex items-baseline justify-between gap-6 border-b border-border py-[17px]"
                            >
                                <div className="min-w-0">{row.sample}</div>
                                <div className="w-[170px] flex-none text-right">
                                    <div className="text-[15px] font-semibold">
                                        {row.role}
                                    </div>
                                    <div className="mt-[3px] font-mono text-[11px] text-text-3">
                                        {row.meta}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </Section>

                {/* 05 buttons */}
                <Section
                    n="05"
                    eyebrow="Components"
                    title="Buttons"
                    lead="One orange button per view. Everything else is neutral or ghost so the real action stays obvious."
                >
                    <div className="grid gap-[18px] md:grid-cols-2">
                        <Demo label="Hierarchy" tag="variant">
                            <div className="flex flex-col items-start gap-2.5">
                                <Button>
                                    <Arrow />
                                    Take me there
                                </Button>
                                <Button variant="secondary">
                                    Save for later
                                </Button>
                                <Button variant="ghost">Not now</Button>
                            </div>
                        </Demo>
                        <Demo label="Specialized" tag="cyan · danger · fab">
                            <div className="flex flex-col items-start gap-2.5">
                                <Button variant="cyan">
                                    <Pin dot />
                                    from Neumarkt
                                </Button>
                                <Button variant="danger">End journey</Button>
                                <IconButton aria-label="Add">
                                    <Plus />
                                </IconButton>
                            </div>
                        </Demo>
                    </div>
                </Section>

                {/* 06 pills, segmented, tags */}
                <Section
                    n="06"
                    eyebrow="Components"
                    title="Filter pills, segmented & tags"
                    lead="The shared filter language. Selected = orange; scope = solid orange; origin = cyan; resting = neutral."
                >
                    <div className="grid gap-[18px] md:grid-cols-2">
                        <Demo label="Category — single select" tag="Pill">
                            <div className="flex flex-wrap items-center gap-2.5">
                                {['Parks', 'Culture', 'Pitches', 'Cafés'].map(
                                    (c) => (
                                        <Pill
                                            key={c}
                                            variant={
                                                cat === c.toLowerCase()
                                                    ? 'on'
                                                    : 'default'
                                            }
                                            onClick={() =>
                                                setCat(c.toLowerCase())
                                            }
                                        >
                                            {c}
                                        </Pill>
                                    ),
                                )}
                            </div>
                        </Demo>
                        <Demo label="Scope & origin" tag="scope · cyan">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <Pill variant="scope">
                                    <Pin />
                                    Near me
                                </Pill>
                                <Pill variant="default">All Cologne</Pill>
                                <Pill variant="cyan">
                                    <Pin dot />
                                    from You
                                </Pill>
                            </div>
                        </Demo>
                        <Demo label="Segmented" tag="Segmented">
                            <Segmented
                                value={mode}
                                onValueChange={setMode}
                                options={[
                                    { value: 'walk', label: 'Walk' },
                                    { value: 'transit', label: 'Transit' },
                                    { value: 'bike', label: 'Bike' },
                                ]}
                            />
                        </Demo>
                        <Demo
                            label="Status tags & count"
                            tag="Tag · CountBadge"
                        >
                            <div className="flex flex-wrap items-center gap-2.5">
                                <Tag tone="success">Open now</Tag>
                                <Tag tone="warn">+3 min</Tag>
                                <Tag tone="danger">Cancelled</Tag>
                            </div>
                            <div className="mt-3 flex items-center gap-2.5">
                                <span className="inline-flex items-center gap-2 text-[13px] font-semibold">
                                    Alerts <CountBadge>5</CountBadge>
                                </span>
                                <span className="text-[11.5px] text-text-3">
                                    solid red — attention, not action
                                </span>
                            </div>
                        </Demo>
                    </div>
                </Section>

                {/* 07 cards & tiles */}
                <Section
                    n="07"
                    eyebrow="Components"
                    title="Cards, tiles & fields"
                    lead="Surfaces sit on the border + soft-shadow recipe. Accent enters through a small tinted icon or a single status mark."
                >
                    <div className="grid gap-[18px] md:grid-cols-2">
                        <Demo label="Place card" tag="Tile + IconButton">
                            <Tile
                                iconTone="success"
                                icon={<Star />}
                                title={
                                    <span className="flex items-center gap-2">
                                        Rheinpark <Tag tone="success">Open</Tag>
                                    </span>
                                }
                                subtitle={
                                    <>
                                        Riverside park · Deutz
                                        <span className="mt-2 block font-mono text-[12px] text-cyan-h">
                                            8 min away · on foot
                                        </span>
                                    </>
                                }
                                trailing={
                                    <IconButton
                                        size="sm"
                                        aria-label="Take me there"
                                    >
                                        <Arrow />
                                    </IconButton>
                                }
                                className="border-none p-0"
                            />
                        </Demo>
                        <Demo label="Alert tile" tag="tinted icon">
                            <Tile
                                iconTone="danger"
                                icon={<Triangle />}
                                title="Line 1 suspended"
                                subtitle="Replacement buses between Deutz and Kalk until 18:00."
                                className="border-none p-0"
                            />
                        </Demo>
                        <Demo label="Input field" tag="Field">
                            <Field placeholder="Search places, events, lines…" />
                        </Demo>
                        <Demo label="Surface elevation" tag="shadow-card">
                            <div className="flex gap-[18px]">
                                <div className="flex-1">
                                    <div className="h-[74px] rounded-[14px] border border-border bg-card" />
                                    <div className="mt-2 text-center font-mono text-[10.5px] text-text-3">
                                        flat · border
                                    </div>
                                </div>
                                <div className="flex-1">
                                    <div className="h-[74px] rounded-[14px] border border-border bg-card shadow-card" />
                                    <div className="mt-2 text-center font-mono text-[10.5px] text-text-3">
                                        raised · cards
                                    </div>
                                </div>
                            </div>
                        </Demo>
                    </div>
                </Section>

                {/* 08 category tints */}
                <Section
                    n="08"
                    eyebrow="Categories"
                    title="Category tints"
                    lead="Each leisure category carries a soft tint and a marker ink, so a glance reads the kind of place before the label does."
                >
                    <div className="flex flex-wrap gap-2.5">
                        {CATEGORY_TINTS.map((c) => (
                            <span
                                key={c.cat}
                                className={cn(
                                    'inline-flex items-center gap-2 rounded-full px-[13px] py-[7px] text-[12.5px] font-semibold',
                                    categoryClass(c.cat),
                                )}
                                style={{
                                    background: 'var(--cat-tint)',
                                    color: 'var(--cat-mark)',
                                }}
                            >
                                <span
                                    className="size-2 rounded-full"
                                    style={{ background: 'var(--cat-mark)' }}
                                />
                                {c.label}
                            </span>
                        ))}
                    </div>
                </Section>

                {/* 09 shape */}
                <Section
                    n="09"
                    eyebrow="Shape & depth"
                    title="Radius & elevation"
                    lead="Generous rounding and one soft shadow keep the app friendly. Pills go fully round; surfaces stay at 14–16px."
                >
                    <div className="flex flex-wrap gap-[18px]">
                        {[
                            { r: '8px', cls: 'rounded-[8px]' },
                            { r: '11px', cls: 'rounded-[11px]' },
                            { r: '16px', cls: 'rounded-[16px]' },
                            { r: 'pill', cls: 'rounded-full' },
                        ].map((b) => (
                            <div
                                key={b.r}
                                className={cn(
                                    'flex size-[74px] items-end justify-center border-[1.5px] border-primary bg-primary-soft pb-1.5 font-mono text-[10.5px] font-semibold text-primary-hover',
                                    b.cls,
                                )}
                            >
                                {b.r}
                            </div>
                        ))}
                    </div>
                </Section>

                {/* 10 principles */}
                <Section
                    n="10"
                    eyebrow="Principles"
                    title="How to keep it coherent"
                >
                    <div className="grid gap-4 md:grid-cols-3">
                        {PRINCIPLES.map((p) => (
                            <Surface key={p.n} className="p-5">
                                <div className="font-mono text-[11px] font-semibold text-primary">
                                    {p.n}
                                </div>
                                <div className="mt-2 text-[15px] font-semibold">
                                    {p.t}
                                </div>
                                <div className="mt-1.5 text-[13px] leading-[1.55] text-text-2">
                                    {p.d}
                                </div>
                            </Surface>
                        ))}
                    </div>
                </Section>
            </div>
        </div>
    );
}
