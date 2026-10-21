/* The Events street: the mock's Stories board (events-next street-art.js)
   as pictures and a few CSS layers. Every fixed drawing is an image made
   by tools/events-street/build.mjs; the hour changes only custom
   properties (colours, opacities, brightness), so nothing is redrawn.
   Posters are the page's own (PosterArt), placed in the wall's hour bays
   and round the column. What moves (street-motion.css) moves by transform
   and opacity alone: the drawings' moving parts are pictures of their own,
   so the compositor moves them and nothing is repainted. */

import type {
    AnimationEvent,
    CSSProperties,
    PointerEvent,
    ReactNode,
} from 'react';
import { useId, useRef, useState } from 'react';
import type { PosterFit, TitleStepName } from '@/components/posters/poster-art';
import manifest from './street-layers.json';
import type { Bay, Slotted, StreetLayoutName } from './street-layout';
import { drum, hourLabel, phonePages, STREET, wallBays } from './street-layout';
import type { SkyKeys } from './street-sky';
import './street.css';
import './street-motion.css';

type Box = [number, number, number, number];

/** A part of a layer that moves on its own, by the mock's classes. */
type Part = {
    id: string;
    cls: string;
    box: Box;
    files: Record<string, string>;
    vars?: Record<string, string>;
};

type Layer = {
    id: string;
    kind: 'image' | 'mask' | 'tint' | 'multiply';
    /** Null when the layer is all moving parts. */
    box: Box | null;
    files: Record<string, string>;
    anchor?: 'right';
    parts?: Part[];
};

export type StreetMotion = 'still' | 'calm' | 'full';

// The mock's animation classes, as the page's (street-motion.css).
const moves = (cls: string) =>
    cls
        .split(' ')
        .map((c) => `m-${c}`)
        .join(' ');

// Layers that move as a whole: the clouds drift, the flock and the plane
// fly across, the tree sways.
const WHOLE: Record<string, string> = {
    'cloud-a': 'm-drift-a',
    'cloud-b': 'm-drift-b',
    'cloud-c': 'm-drift-c',
    birds: 'm-flock',
    plane: 'm-plane',
    tree: 'm-sway',
};

// Posters pasted up in this tab already don't paste again, after Back or
// a refresh too (the mock's wall.js keeps them in sessionStorage).
const PASTED = 'expadu-wall-pasted';
const pasted = new Set<string>(
    (() => {
        try {
            return JSON.parse(
                window.sessionStorage.getItem(PASTED) ?? '[]',
            ) as string[];
        } catch {
            return [];
        }
    })(),
);

function remember(key: string) {
    pasted.add(key);

    try {
        window.sessionStorage.setItem(PASTED, JSON.stringify([...pasted]));
    } catch {
        // Remembering is a nicety; without it a poster pastes again.
    }
}

/** Lets every poster paste up again (the motion lab's Replay). */
export function forgetPasted() {
    pasted.clear();

    try {
        window.sessionStorage.removeItem(PASTED);
    } catch {
        // As above.
    }
}

type Layout = {
    size: [number, number];
    scales: number[];
    inline: { lampFrame: string; moonGlow: string };
    grain: { y: number; height: number; opacity: number; tile: number };
    layers: Layer[];
};

const layouts = manifest as unknown as Record<StreetLayoutName, Layout>;

const tintId = (base: string, id: string) => `${base}-${id}`;

/** A two-colour map for a grey tint layer: white → `light`, black → `dark`. */
function Tint({
    id,
    light,
    dark,
}: {
    id: string;
    light: string;
    dark: string;
}) {
    const ch = (hex: string, i: number) =>
        (parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16) / 255).toFixed(4);
    const table = (i: number) => `${ch(dark, i)} ${ch(light, i)}`;

    return (
        <filter id={id} colorInterpolationFilters="sRGB">
            <feComponentTransfer>
                <feFuncR type="table" tableValues={table(0)} />
                <feFuncG type="table" tableValues={table(1)} />
                <feFuncB type="table" tableValues={table(2)} />
            </feComponentTransfer>
        </filter>
    );
}

export type StreetSceneProps<T> = {
    layout: StreetLayoutName;
    /** Drawing width: 1100–2240 on a desktop (streetFit), 351 on a phone. */
    width: number;
    /** On-screen pixels per drawing unit (zoom × devicePixelRatio). */
    density: number;
    sky: SkyKeys;
    /** The hour of "Now" when the day shown is today, else null. */
    nowHour: number | null;
    /** "13:10", shown on the Now pill. */
    nowLabel?: string;
    /** The day's posters on the wall, by hour. */
    wall: Slotted<T>[];
    /** The column's posters; the first faces you. */
    column: T[];
    columnPos?: number;
    onColumnPos?: (pos: number) => void;
    phonePage?: number;
    onPhonePage?: (page: number) => void;
    poster: (
        item: T,
        where: 'wall' | 'column',
        style?: CSSProperties,
        fit?: PosterFit,
    ) => ReactNode;
    /** The weather chip (EV2b); nothing when there's no weather to show. */
    chip?: ReactNode;
    /** A bay's "+N more" (EV2c opens the hour's stories). */
    onMore?: (hour: number) => void;
    /** How much moves (the owner's motion lab); reduced motion stills it all. */
    motion?: StreetMotion;
    /** A poster's key, so one pasted up already isn't pasted again. */
    itemKey?: (item: T) => string;
    /** Pointing at the column holds its turning. */
    onColumnHold?: (held: boolean) => void;
};

export default function StreetScene<T>({
    layout,
    width,
    density,
    sky,
    nowHour,
    nowLabel,
    wall,
    column,
    columnPos = 0,
    onColumnPos,
    phonePage,
    onPhonePage,
    poster,
    chip,
    onMore,
    motion = 'full',
    itemKey,
    onColumnHold,
}: StreetSceneProps<T>) {
    const uid = useId().replace(/:/g, '');
    const swipe = useRef<{ y: number; at: number } | null>(null);
    const wheelAt = useRef(0);
    const swiped = useRef(false);
    // A drag on the column turns it (the mock's down/move): the press, how
    // far it has turned, and the click it swallows once it moved.
    const colPress = useRef<{
        x: number;
        id: number;
        moved: boolean;
        perPoster: number;
    } | null>(null);
    const [colDrag, setColDrag] = useState(0);
    const colDragNow = useRef(0);
    const swallowClick = useRef(false);
    // Each face's middle slice measures its poster's title; the others
    // take that step, so a poster's lines meet at the slices' seams.
    const [titleSteps, setTitleSteps] = useState<Record<string, TitleStepName>>(
        {},
    );
    const tilt = useRef<{ card: HTMLElement | null; frame: number }>({
        card: null,
        frame: 0,
    });
    const art = layouts[layout];
    const c = STREET[layout];
    const desktop = layout === 'desktop';
    const [, height] = art.size;
    const scale = desktop ? (density > 1.1 ? 1.5 : 1) : density > 2.2 ? 3 : 2;
    const byId = Object.fromEntries(art.layers.map((l) => [l.id, l]));

    // Only what the hour shows is fetched: no stars by day, no rain when dry.
    const shown = (id: string, opacity = 1) =>
        opacity > 0.001 && byId[id] ? byId[id] : null;
    const at = (layer: { box: Box; anchor?: 'right' }): CSSProperties =>
        layer.anchor === 'right'
            ? {
                  // Drawn against the board's 1361 width: the drainpipe stays
                  // at the street's right end however wide it is.
                  right: STREET.desktop.size[0] - (layer.box[0] + layer.box[2]),
                  top: layer.box[1],
                  width: layer.box[2],
                  height: layer.box[3],
              }
            : {
                  left: layer.box[0],
                  top: layer.box[1],
                  width: layer.box[2],
                  height: layer.box[3],
              };

    function img(id: string, opacity = 1, style: CSSProperties = {}) {
        const layer = shown(id, opacity);

        if (!layer) {
            return null;
        }

        const src = layer.files[String(scale)];

        if (layer.kind === 'mask' && layer.box) {
            return (
                <div
                    key={id}
                    className="st-l st-mask"
                    data-layer={id}
                    style={{
                        ...at({ box: layer.box, anchor: layer.anchor }),
                        maskImage: `url(${src})`,
                        WebkitMaskImage: `url(${src})`,
                        opacity: opacity === 1 ? undefined : opacity,
                        ...style,
                    }}
                />
            );
        }

        const filter =
            layer.kind === 'tint'
                ? `url(#${tintId(uid, id.startsWith('cloud') ? 'cloud' : 'puddle')})`
                : undefined;
        const picture = (
            key: string,
            from: { box: Box; anchor?: 'right' },
            file: string | undefined,
            cls = '',
            extra: CSSProperties = {},
        ) =>
            file ? (
                <img
                    key={key}
                    className={`st-l ${cls}`.trim()}
                    src={file}
                    alt=""
                    decoding="async"
                    draggable={false}
                    style={{ ...at(from), filter, ...extra }}
                />
            ) : null;
        const still = layer.box
            ? picture(
                  id,
                  { box: layer.box, anchor: layer.anchor },
                  src,
                  id === 'lamp-glow' ? 'm-glow' : '',
                  style,
              )
            : null;
        const parts = layer.parts ?? [];
        const whole = WHOLE[id];

        if (!parts.length && !whole) {
            return still ? (
                <img
                    {...still.props}
                    key={id}
                    data-layer={id}
                    style={{
                        ...still.props.style,
                        opacity: opacity === 1 ? undefined : opacity,
                    }}
                />
            ) : null;
        }

        // A pigeon's head pecks while its body walks: the head goes inside.
        const heads = parts.filter((p) => p.cls.startsWith('pig-head'));
        const part = (p: Part) =>
            picture(
                p.id,
                p,
                p.files[String(scale)],
                moves(p.cls),
                p.vars as CSSProperties,
            );

        return (
            <div
                key={id}
                className={`st-l st-move ${whole ?? ''}`.trim()}
                data-layer={id}
                style={{ opacity: opacity === 1 ? undefined : opacity }}
            >
                {still}
                {parts
                    .filter((p) => !p.cls.startsWith('pig-head'))
                    .map((p) =>
                        p.cls.startsWith('pig-walk') ? (
                            <div
                                key={p.id}
                                className={`st-l st-move ${moves(p.cls)}`}
                            >
                                {picture(p.id, p, p.files[String(scale)])}
                                {heads
                                    .filter(
                                        (h) =>
                                            h.id === p.id.replace('pw', 'ph'),
                                    )
                                    .map(part)}
                            </div>
                        ) : (
                            part(p)
                        ),
                    )}
            </div>
        );
    }

    const vars = {
        '--k-s1': sky.s1,
        '--k-s2': sky.s2,
        '--k-s3': sky.s3,
        '--k-s4': sky.s4,
        '--k-s5': sky.s5,
        '--k-far': sky.far,
        '--k-near': sky.near,
        '--k-mist-c': sky.mistC,
        '--k-obj-b': sky.objB,
        '--k-poster-b': sky.posterB,
        '--st-w': `${width}px`,
        '--st-h': `${height}px`,
    } as CSSProperties;

    const skyH = desktop ? 240 : 154;
    const lampAt = desktop
        ? 'translate(86 936)'
        : 'translate(9 566) scale(0.58)';
    const lampHead = desktop
        ? { x: 86, y: 936, s: 1 }
        : { x: 9, y: 566, s: 0.58 };
    const glow = desktop ? [99.1, 20.1] : [63, 12.8];
    const light = desktop
        ? { top: 250, bottom: 36, lamp: '86px 82px' }
        : { top: 162, bottom: 32, lamp: '14px 36px' };

    // ---------- Posters ----------
    const bays: Bay<T>[] = desktop ? wallBays(wall, { width, nowHour }) : [];
    const pages = desktop ? null : phonePages(wall, nowHour);
    const page = pages
        ? Math.min(
              phonePage ?? pages.page,
              Math.max(0, pages.posters.length - 1),
          )
        : 0;
    const turnPage = (dir: number) => {
        if (pages) {
            onPhonePage?.(
                Math.max(0, Math.min(pages.posters.length - 1, page + dir)),
            );
        }
    };
    const N = column.length;
    const pos = N ? ((columnPos % N) + N) % N : 0;
    // The drum turns on from where it is (not back round to the first
    // poster), so its turn eases from face to face; a drag turns it with
    // the finger, in posters.
    const strip = drum(column, columnPos + colDrag, layout);
    const columnFit = (item: T, slice: number): PosterFit => {
        const key = itemKey?.(item) ?? String(column.indexOf(item));

        return slice === Math.floor(c.col.slices / 2)
            ? {
                  onTitleStep: (step) =>
                      setTitleSteps((all) =>
                          all[key] === step ? all : { ...all, [key]: step },
                      ),
              }
            : { titleStep: titleSteps[key] ?? 'normal' };
    };
    const toPoster = (i: number) => {
        let step = (((i - pos) % N) + N) % N;

        if (step > N / 2) {
            step -= N;
        }

        onColumnPos?.(columnPos + step);
    };

    // With a mouse, a wall poster leans towards the pointer and catches the
    // light (the mock's mxTilt), one frame at a time.
    const untilt = () => {
        const t = tilt.current;
        cancelAnimationFrame(t.frame);

        if (t.card) {
            for (const name of [
                '--tilt-x',
                '--tilt-y',
                '--glare-x',
                '--glare-y',
            ]) {
                t.card.style.removeProperty(name);
            }

            t.card.classList.remove('is-tilting');
            t.card = null;
        }
    };
    const onTilt = (e: PointerEvent<HTMLDivElement>) => {
        if (!desktop || motion === 'still' || e.pointerType !== 'mouse') {
            return;
        }

        const card = (e.target as Element)
            .closest('.st-slot')
            ?.querySelector<HTMLElement>(':scope > .pp');

        if (card !== tilt.current.card) {
            untilt();
        }

        if (!card) {
            return;
        }

        const { clientX, clientY } = e;
        cancelAnimationFrame(tilt.current.frame);
        tilt.current.frame = requestAnimationFrame(() => {
            const r = card.getBoundingClientRect();
            const x = (clientX - r.left) / r.width;
            const y = (clientY - r.top) / r.height;
            card.style.setProperty(
                '--tilt-x',
                `${((x - 0.5) * 8).toFixed(2)}deg`,
            );
            card.style.setProperty(
                '--tilt-y',
                `${((0.5 - y) * 8).toFixed(2)}deg`,
            );
            card.style.setProperty('--glare-x', `${(x * 100).toFixed(1)}%`);
            card.style.setProperty('--glare-y', `${(y * 100).toFixed(1)}%`);
            card.classList.add('is-tilting');
            tilt.current.card = card;
        });
    };

    // A poster is pasted up once in a tab; after that it's simply there.
    const slot = (item: T, i: number, style: CSSProperties) => {
        const key = itemKey?.(item);

        return {
            key: key ?? i,
            'data-up': key && pasted.has(key) ? true : undefined,
            style: { ...style, '--i': i } as CSSProperties,
            onAnimationEnd: (e: AnimationEvent<HTMLDivElement>) => {
                if (key && e.animationName === 'pp-paste') {
                    remember(key);
                }
            },
        };
    };

    return (
        <div
            className={`st-scene st-scene--${layout}`}
            data-motion={motion}
            onPointerMove={onTilt}
            onPointerLeave={untilt}
            style={vars}
            data-scale={scale}
        >
            <svg className="st-defs" width="0" height="0" aria-hidden="true">
                <defs>
                    <Tint
                        id={tintId(uid, 'cloud')}
                        light={sky.cloud}
                        dark={sky.cloudShade}
                    />
                    <Tint
                        id={tintId(uid, 'puddle')}
                        light={sky.s4}
                        dark={sky.s2}
                    />
                </defs>
            </svg>

            {/* The sky, the city and the wall */}
            <div className="st-sky" style={{ height: skyH }} />
            {img('stars', sky.stars)}
            {/* The sun's glow is its own element, so its pulse is the
                compositor's (on the sky's SVG it repainted the sky). */}
            {sky.sunO > 0.001 ? (
                <div
                    className="st-l st-sunglow m-pulse"
                    style={{
                        left: sky.sunX - glow[0],
                        top: sky.sunY - glow[0],
                        width: glow[0] * 2,
                        height: glow[0] * 2,
                        opacity: sky.sunO,
                        background: `radial-gradient(closest-side, color-mix(in srgb, ${sky.sunC} 75%, transparent), color-mix(in srgb, ${sky.sunC} 28%, transparent) 35%, transparent)`,
                    }}
                />
            ) : null}
            <svg
                className="st-l st-orbs"
                width={width}
                height={skyH}
                viewBox={`0 0 ${width} ${skyH}`}
                aria-hidden="true"
            >
                <defs
                    dangerouslySetInnerHTML={{ __html: art.inline.moonGlow }}
                />
                {sky.moonO > 0.001 ? (
                    <g
                        transform={`translate(${sky.moonX} ${sky.moonY})`}
                        opacity={sky.moonO}
                    >
                        <circle
                            r="46"
                            fill={`url(#${desktop ? 'std' : 'stp'}moonglow)`}
                        />
                        <circle r="15" fill="#3a3b55" opacity="0.55" />
                        <path d={sky.moonPath} fill="#f4f1dc" />
                    </g>
                ) : null}
                {sky.sunO > 0.001 ? (
                    <g
                        transform={`translate(${sky.sunX} ${sky.sunY})`}
                        opacity={sky.sunO}
                    >
                        <circle r={glow[1]} fill={sky.sunC} />
                    </g>
                ) : null}
            </svg>
            {img('cloud-a', sky.c1)}
            {img('cloud-b', sky.c2)}
            {img('cloud-c', sky.c3)}
            {img('birds', sky.birds)}
            {img('plane', sky.plane)}
            {img('far', 1, { background: 'var(--k-far)' })}
            {img('far-light', 0.45, { background: 'var(--k-s4)' })}
            <div
                className="st-haze"
                style={
                    desktop
                        ? { top: 178, height: 60 }
                        : { top: 114.8, height: 37.2 }
                }
            />
            {sky.mist > 0.001 ? (
                <div
                    className="st-mist"
                    style={{
                        ...(desktop
                            ? { top: 192, height: 46 }
                            : { top: 123.5, height: 28.5 }),
                        opacity: sky.mist,
                    }}
                />
            ) : null}
            {img('smoke')}
            {img('near', 1, { background: 'var(--k-near)' })}
            {img('windows', sky.windows)}
            {img('wall')}
            <div
                className="st-grain"
                style={{
                    top: art.grain.y,
                    height: art.grain.height,
                    backgroundImage: `url(${byId.grain.files[String(scale)]})`,
                    backgroundSize: `${art.grain.tile}px`,
                }}
            />
            {sky.wet > 0.001 ? (
                <div
                    className="st-wet"
                    style={{
                        top: desktop ? 250 : 162,
                        height: desktop ? 630 : 372,
                        opacity: sky.wet,
                    }}
                />
            ) : null}
            {img('shadow', sky.shadow)}
            {img('tree', 1, { filter: 'brightness(var(--k-obj-b))' })}
            {desktop ? img('edge') : null}

            {/* The day's posters on the wall */}
            {desktop ? (
                <div
                    className="st-zone"
                    style={{ left: 548, right: 24, top: 250, bottom: 80 }}
                >
                    <div
                        className="st-zone-strip"
                        style={{ top: -250, height: 960 }}
                    >
                        {bays.map((b) => (
                            <div
                                key={b.hour}
                                className="st-bay"
                                style={{
                                    left: b.x,
                                    width: STREET.desktop.bayW,
                                    opacity: b.dim ? 0.72 : undefined,
                                }}
                            >
                                <span
                                    className="st-hour"
                                    style={{ left: b.labelX }}
                                >
                                    {hourLabel(b.hour)}
                                </span>
                                {b.isNow ? (
                                    <span className="st-now">
                                        Now {nowLabel}
                                    </span>
                                ) : null}
                                {b.more > 0 ? (
                                    <button
                                        type="button"
                                        className="st-more"
                                        onClick={() => onMore?.(b.hour)}
                                    >
                                        +{b.more} more
                                    </button>
                                ) : null}
                                {b.posters.map((p, i) => (
                                    <div
                                        className="st-slot"
                                        {...slot(p.item, i, {
                                            left: p.x,
                                            top: p.y,
                                        })}
                                    >
                                        {poster(p.item, 'wall', {
                                            zoom: p.zoom,
                                            rotate: `${p.rotate}deg`,
                                        })}
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            ) : pages ? (
                <>
                    <div
                        className="st-zone st-zone--pages"
                        style={{ left: 192, width: 154, top: 162, height: 372 }}
                        // Swipe up for later, down for earlier; a wheel
                        // or trackpad pages too.
                        onPointerDown={(e) => {
                            swiped.current = false;
                            swipe.current = { y: e.clientY, at: e.timeStamp };
                        }}
                        onPointerUp={(e) => {
                            const start = swipe.current;
                            swipe.current = null;

                            if (start && Math.abs(e.clientY - start.y) > 40) {
                                swiped.current = true;
                                turnPage(e.clientY < start.y ? 1 : -1);
                            }
                        }}
                        // A swipe that began on a poster doesn't also tap it.
                        onClickCapture={(e) => {
                            if (swiped.current) {
                                swiped.current = false;
                                e.preventDefault();
                                e.stopPropagation();
                            }
                        }}
                        onPointerCancel={() => {
                            swipe.current = null;
                        }}
                        onWheel={(e) => {
                            if (
                                Math.abs(e.deltaY) < 20 ||
                                e.timeStamp - wheelAt.current < 450
                            ) {
                                return;
                            }

                            wheelAt.current = e.timeStamp;
                            turnPage(e.deltaY > 0 ? 1 : -1);
                        }}
                    >
                        <div
                            className="st-zone-strip"
                            style={{
                                top: -162,
                                height: 590,
                                left: 0,
                                right: 0,
                                translate: `0 ${-page * STREET.phone.pageH}px`,
                            }}
                        >
                            {pages.posters.map((p, i) =>
                                Math.abs(i - page) <= 1 ? (
                                    <div
                                        className="st-slot"
                                        {...slot(p.item, i, {
                                            left: p.x,
                                            top: p.y,
                                        })}
                                        inert={i !== page}
                                    >
                                        {poster(p.item, 'wall', {
                                            width: STREET.phone.pw,
                                            height: STREET.phone.ph,
                                            rotate: `${p.rotate}deg`,
                                        })}
                                    </div>
                                ) : null,
                            )}
                        </div>
                    </div>
                    {pages.posters.length > 1 ? (
                        <div
                            className="st-pages"
                            role="group"
                            aria-label="Pages"
                        >
                            {pages.posters
                                .map((_, i) => i)
                                .slice(
                                    Math.max(
                                        0,
                                        Math.min(
                                            page - 3,
                                            pages.posters.length - 7,
                                        ),
                                    ),
                                    Math.max(
                                        0,
                                        Math.min(
                                            page - 3,
                                            pages.posters.length - 7,
                                        ),
                                    ) + 7,
                                )
                                .map((i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        aria-label={`Page ${i + 1}`}
                                        aria-current={
                                            i === page ? 'true' : undefined
                                        }
                                        onClick={() => onPhonePage?.(i)}
                                    >
                                        <i />
                                    </button>
                                ))}
                        </div>
                    ) : null}
                </>
            ) : null}

            {/* The hour's light over the wall */}
            <div
                className="st-light"
                aria-hidden="true"
                style={{ top: light.top, bottom: light.bottom }}
            >
                <div
                    className="st-light-night"
                    style={{ opacity: sky.night }}
                />
                <div
                    className="st-light-golden"
                    style={{ opacity: sky.golden }}
                />
                <div
                    className="st-light-sun"
                    style={{ opacity: sky.sunWash }}
                />
                <div
                    className="st-light-lamp"
                    style={{
                        opacity: sky.lamp,
                        background: `radial-gradient(circle at ${light.lamp},rgba(255,206,130,.75),rgba(255,178,90,.25) ${desktop ? 154 : 70}px,rgba(255,170,80,0) ${desktop ? 440 : 200}px)`,
                    }}
                />
            </div>

            {/* The poster column: a drag anywhere on it turns it */}
            <div
                className="st-column"
                style={{
                    left: c.col.left,
                    top: c.col.top,
                    width: 300 * c.col.scale,
                    height: 740 * c.col.scale,
                }}
                onPointerDown={(e) => {
                    if (
                        N < 2 ||
                        (e.pointerType === 'mouse' && e.button !== 0)
                    ) {
                        return;
                    }

                    const face = e.currentTarget
                        .querySelector('.st-drum')
                        ?.getBoundingClientRect().width;
                    colPress.current = {
                        x: e.clientX,
                        id: e.pointerId,
                        moved: false,
                        perPoster: face ? (face * c.col.pitch) / c.col.win : 1,
                    };
                }}
                onPointerMove={(e) => {
                    const press = colPress.current;

                    if (!press || press.id !== e.pointerId) {
                        return;
                    }

                    const dx = e.clientX - press.x;

                    if (!press.moved && Math.abs(dx) < 6) {
                        return;
                    }

                    if (!press.moved) {
                        press.moved = true;
                        e.currentTarget.setPointerCapture(e.pointerId);
                        onColumnHold?.(true);
                    }

                    // It follows the finger; two posters either way it
                    // starts to resist.
                    let turn = -dx / press.perPoster;

                    if (Math.abs(turn) > 2) {
                        turn = Math.sign(turn) * (2 + (Math.abs(turn) - 2) / 3);
                    }

                    colDragNow.current = turn;
                    setColDrag(turn);
                }}
                onPointerUp={(e) => {
                    const press = colPress.current;

                    if (!press || press.id !== e.pointerId) {
                        return;
                    }

                    colPress.current = null;

                    if (!press.moved) {
                        return;
                    }

                    // It settles on the nearest poster; a short flick still
                    // turns one.
                    const turn = colDragNow.current;
                    const steps =
                        Math.abs(turn) > 0.15 && Math.abs(turn) < 0.5
                            ? Math.sign(turn)
                            : Math.round(turn);
                    colDragNow.current = 0;
                    setColDrag(0);
                    onColumnPos?.(columnPos + steps);
                    onColumnHold?.(false);
                    // The click that ends a drag opens nothing.
                    swallowClick.current = true;
                }}
                onPointerCancel={() => {
                    colPress.current = null;
                    colDragNow.current = 0;
                    setColDrag(0);
                    onColumnHold?.(false);
                }}
                onClickCapture={(e) => {
                    if (swallowClick.current) {
                        swallowClick.current = false;
                        e.stopPropagation();
                        e.preventDefault();
                    }
                }}
            >
                <div
                    className="st-column-body"
                    style={{
                        transform:
                            c.col.scale === 1
                                ? undefined
                                : `scale(${c.col.scale})`,
                    }}
                >
                    {img('column', 1, { filter: 'brightness(var(--k-obj-b))' })}
                    <div
                        className="st-drum"
                        onPointerEnter={() => onColumnHold?.(true)}
                        onPointerLeave={() => onColumnHold?.(false)}
                    >
                        <div
                            className="st-drum-turn"
                            style={{
                                width: c.col.win,
                                transform: strip.style,
                                transition: colDrag ? 'none' : undefined,
                            }}
                        >
                            {strip.slices.map((s) => (
                                <div
                                    key={s.key}
                                    className="st-face"
                                    data-face={s.face}
                                    aria-hidden={
                                        s.face !== 0 || s.slice !== 1
                                            ? true
                                            : undefined
                                    }
                                    style={{
                                        left: s.left,
                                        width: s.width,
                                        top: c.col.top4,
                                        height: c.col.ph,
                                        transform: s.transform,
                                    }}
                                >
                                    <div
                                        style={{
                                            position: 'absolute',
                                            top: 0,
                                            left: s.offset,
                                            width: c.col.pw,
                                        }}
                                    >
                                        {poster(
                                            s.item,
                                            'column',
                                            {
                                                width: c.col.pw,
                                                height: c.col.ph,
                                            },
                                            columnFit(s.item, s.slice),
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="st-drum-shade" />
                        <div
                            className="st-drum-glow"
                            style={{ opacity: sky.colGlow }}
                        />
                    </div>
                    {N > 1 ? (
                        <>
                            <div className="st-dots">
                                {column.map((_, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        aria-label={`Show poster ${i + 1} of ${N}`}
                                        aria-current={
                                            i === pos ? 'true' : undefined
                                        }
                                        onClick={() => toPoster(i)}
                                    >
                                        <i />
                                    </button>
                                ))}
                            </div>
                            <button
                                type="button"
                                className="st-col-prev"
                                aria-label="Previous poster"
                                onClick={() => onColumnPos?.(columnPos - 1)}
                            />
                            <button
                                type="button"
                                className="st-col-next"
                                aria-label="Next poster"
                                onClick={() => onColumnPos?.(columnPos + 1)}
                            />
                        </>
                    ) : null}
                </div>
            </div>

            {/* The pavement, the lamp and what stands on it */}
            {img('puddles', sky.wet)}
            {img('lamp-pool', sky.lamp)}
            <div className="st-objects">
                {img('lamp')}
                {/* The lit glass and flame flicker on their own, so the
                    flicker is the compositor's. */}
                {sky.lamp > 0.001 ? (
                    <svg
                        className="st-l m-flame"
                        width={44 * lampHead.s}
                        height={68 * lampHead.s}
                        viewBox="-22 -640 44 68"
                        aria-hidden="true"
                        style={{
                            left: lampHead.x - 22 * lampHead.s,
                            top: lampHead.y - 640 * lampHead.s,
                        }}
                    >
                        <path
                            d="M-14 -572 L-22 -640 h44 L14 -572 Z"
                            fill="#ffd27a"
                            opacity={sky.lamp}
                        />
                        <circle
                            cx="0"
                            cy="-596"
                            r="6"
                            fill="#fff4cf"
                            opacity={sky.lamp}
                        />
                    </svg>
                ) : null}
                <svg
                    className="st-l"
                    width={art.size[0]}
                    height={height}
                    viewBox={`0 0 ${art.size[0]} ${height}`}
                    aria-hidden="true"
                    style={{ left: 0, top: 0 }}
                >
                    <g transform={lampAt}>
                        <path
                            d="M-14 -572 L-22 -640 h44 L14 -572 Z"
                            fill="#c8dcd9"
                            opacity={sky.glassDay}
                        />
                        <g
                            dangerouslySetInnerHTML={{
                                __html: art.inline.lampFrame,
                            }}
                        />
                    </g>
                </svg>
                {img('street')}
            </div>
            {img('lamp-glow', sky.lamp)}
            {img('leaves', sky.leaves)}
            {img('rain', sky.rain)}
            {chip}
        </div>
    );
}
