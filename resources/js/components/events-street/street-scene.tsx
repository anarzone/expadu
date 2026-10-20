/* The Events street: the mock's Stories board (events-next street-art.js)
   as pictures and a few CSS layers. Every fixed drawing is an image made
   by tools/events-street/build.mjs; the hour changes only custom
   properties (colours, opacities, brightness), so nothing is redrawn.
   Posters are the page's own (PosterArt), placed in the wall's hour bays
   and round the column. Static: nothing moves here (motion is EV2d). */

import type { CSSProperties, ReactNode } from 'react';
import { useId, useRef } from 'react';
import manifest from './street-layers.json';
import type { Bay, Slotted, StreetLayoutName } from './street-layout';
import { drum, hourLabel, phonePages, STREET, wallBays } from './street-layout';
import type { SkyKeys } from './street-sky';
import './street.css';

type Layer = {
    id: string;
    kind: 'image' | 'mask' | 'tint' | 'multiply';
    box: [number, number, number, number];
    files: Record<string, string>;
    anchor?: 'right';
};

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
    ) => ReactNode;
    /** The weather chip (EV2b); nothing when there's no weather to show. */
    chip?: ReactNode;
    /** A bay's "+N more" (EV2c opens the hour's stories). */
    onMore?: (hour: number) => void;
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
}: StreetSceneProps<T>) {
    const uid = useId().replace(/:/g, '');
    const swipe = useRef<{ y: number; at: number } | null>(null);
    const wheelAt = useRef(0);
    const swiped = useRef(false);
    const art = layouts[layout];
    const c = STREET[layout];
    const desktop = layout === 'desktop';
    const [, height] = art.size;
    const scale = desktop ? (density > 1.1 ? 1.5 : 1) : density > 2.2 ? 3 : 2;
    const byId = Object.fromEntries(art.layers.map((l) => [l.id, l]));

    // Only what the hour shows is fetched: no stars by day, no rain when dry.
    const shown = (id: string, opacity = 1) =>
        opacity > 0.001 && byId[id] ? byId[id] : null;
    const at = (layer: Layer): CSSProperties =>
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

        if (layer.kind === 'mask') {
            return (
                <div
                    key={id}
                    className="st-l st-mask"
                    data-layer={id}
                    style={{
                        ...at(layer),
                        maskImage: `url(${src})`,
                        WebkitMaskImage: `url(${src})`,
                        opacity: opacity === 1 ? undefined : opacity,
                        ...style,
                    }}
                />
            );
        }

        return (
            <img
                key={id}
                className="st-l"
                data-layer={id}
                src={src}
                alt=""
                decoding="async"
                draggable={false}
                style={{
                    ...at(layer),
                    opacity: opacity === 1 ? undefined : opacity,
                    filter:
                        layer.kind === 'tint'
                            ? `url(#${tintId(uid, id.startsWith('cloud') ? 'cloud' : 'puddle')})`
                            : undefined,
                    ...style,
                }}
            />
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
    const strip = drum(column, pos, layout);

    return (
        <div
            className={`st-scene st-scene--${layout}`}
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
                <radialGradient id={`${uid}-sunglow`}>
                    <stop offset="0" stopColor={sky.sunC} stopOpacity="0.75" />
                    <stop
                        offset="0.35"
                        stopColor={sky.sunC}
                        stopOpacity="0.28"
                    />
                    <stop offset="1" stopColor={sky.sunC} stopOpacity="0" />
                </radialGradient>
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
                        <circle r={glow[0]} fill={`url(#${uid}-sunglow)`} />
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
                                        key={i}
                                        className="st-slot"
                                        style={{ left: p.x, top: p.y }}
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
                                        key={i}
                                        className="st-slot"
                                        style={{ left: p.x, top: p.y }}
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

            {/* The poster column */}
            <div
                className="st-column"
                style={{
                    left: c.col.left,
                    top: c.col.top,
                    width: 300 * c.col.scale,
                    height: 740 * c.col.scale,
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
                    <div className="st-drum">
                        <div
                            className="st-drum-turn"
                            style={{ width: c.col.win, transform: strip.style }}
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
                                        {poster(s.item, 'column', {
                                            width: c.col.pw,
                                            height: c.col.ph,
                                        })}
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
                                        onClick={() => onColumnPos?.(i)}
                                    >
                                        <i />
                                    </button>
                                ))}
                            </div>
                            <button
                                type="button"
                                className="st-col-prev"
                                aria-label="Previous poster"
                                onClick={() => onColumnPos?.(pos - 1)}
                            />
                            <button
                                type="button"
                                className="st-col-next"
                                aria-label="Next poster"
                                onClick={() => onColumnPos?.(pos + 1)}
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
                        {sky.lamp > 0.001 ? (
                            <>
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
                            </>
                        ) : null}
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
