import { IconWalk, IconTrain, IconBus } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';

type Segment = {
    type: string;
    line?: string;
    direction?: string;
    mode?: string;
    boarding_stop?: string;
    alighting_stop?: string;
    departure_time?: string;
    arrival_time?: string;
    platform?: string;
    delay_min?: number;
    duration_min?: number;
    distance_km?: number;
};

export function JourneyTimeline({ segments, departureTime }: { segments: Segment[]; departureTime?: string }) {
    // Build timeline nodes from segments
    const nodes: Array<{
        type: 'stop' | 'leg';
        time?: string;
        name?: string;
        legType?: 'walk' | 'transit' | 'transfer';
        line?: string;
        direction?: string;
        mode?: string;
        duration?: number;
        platform?: string;
        delay?: number;
        color?: string;
    }> = [];

    let currentTime = departureTime ?? '';

    for (let i = 0; i < segments.length; i++) {
        const seg = segments[i];

        if (seg.type === 'walk') {
            // If first segment, add origin stop
            if (i === 0) {
                nodes.push({ type: 'stop', time: currentTime, name: 'Your location' });
            }
            nodes.push({ type: 'leg', legType: 'walk', duration: seg.duration_min, color: '#1A4CD4' });
            // After walk, if next is transit, the transit boarding stop will be added
            // If last segment, add destination
            if (i === segments.length - 1) {
                const walkEnd = seg.duration_min && currentTime ? addMinutes(currentTime, seg.duration_min) : '';
                nodes.push({ type: 'stop', time: walkEnd, name: 'Destination' });
            }
        } else if (seg.type === 'transit') {
            // Boarding stop
            nodes.push({
                type: 'stop',
                time: seg.departure_time,
                name: seg.boarding_stop ?? '',
            });
            // Transit leg
            const lineColor = getLineColor(seg.line ?? '', seg.mode ?? 'tram');
            nodes.push({
                type: 'leg',
                legType: 'transit',
                line: seg.line,
                direction: seg.direction,
                mode: seg.mode,
                duration: seg.duration_min,
                platform: seg.platform,
                delay: seg.delay_min,
                color: lineColor,
            });
            // Alighting stop
            nodes.push({
                type: 'stop',
                time: seg.arrival_time,
                name: seg.alighting_stop ?? '',
            });
            currentTime = seg.arrival_time ?? currentTime;
        }
    }

    return (
        <div className="py-2">
            {nodes.map((node, i) => {
                if (node.type === 'stop') {
                    return <StopNode key={i} time={node.time ?? ''} name={node.name ?? ''} isFirst={i === 0} isLast={i === nodes.length - 1} />;
                }
                if (node.legType === 'walk') {
                    return <WalkLeg key={i} duration={node.duration ?? 0} />;
                }
                if (node.legType === 'transit') {
                    return (
                        <TransitLeg
                            key={i}
                            line={node.line ?? ''}
                            direction={node.direction ?? ''}
                            mode={node.mode ?? 'tram'}
                            duration={node.duration ?? 0}
                            platform={node.platform}
                            delay={node.delay}
                            color={node.color ?? '#1A4CD4'}
                        />
                    );
                }
                return null;
            })}
        </div>
    );
}

function StopNode({ time, name, isFirst, isLast }: { time: string; name: string; isFirst: boolean; isLast: boolean }) {
    return (
        <div className="flex items-center gap-3" style={{ minHeight: 40 }}>
            <div className="w-[52px] text-right font-mono text-[13px] font-semibold text-[#18170F] dark:text-[#F5F4F0]">
                {time}
            </div>
            <div className="flex w-5 flex-col items-center">
                <div
                    className="size-[14px] rounded-full bg-white dark:bg-[#1E1D15]"
                    style={{ border: `3px solid ${isFirst ? '#0A7C52' : isLast ? '#C4271A' : 'currentColor'}` }}
                />
            </div>
            <div className="text-[14px] font-semibold text-[#18170F] dark:text-[#F5F4F0]">
                {name}
            </div>
        </div>
    );
}

function WalkLeg({ duration }: { duration: number }) {
    return (
        <div className="flex gap-3" style={{ minHeight: 48 }}>
            <div className="w-[52px]" />
            <div className="flex w-5 flex-col items-center">
                <div className="flex-1" style={{ width: 3, background: 'repeating-linear-gradient(to bottom, #1A4CD4 0px, #1A4CD4 4px, transparent 4px, transparent 10px)' }} />
            </div>
            <div className="flex items-center gap-2 py-2">
                <IconWalk size={16} stroke={ICON_STROKE} className="shrink-0 text-[#6B6860] dark:text-[#AAA89F]" />
                <div>
                    <div className="text-[13px] text-[#6B6860] dark:text-[#AAA89F]">Walk</div>
                    <div className="text-xs text-[#AAA89F] dark:text-[#6B6860]">About {duration} min</div>
                </div>
            </div>
        </div>
    );
}

function TransitLeg({ line, direction, mode, duration, platform, delay, color }: {
    line: string; direction: string; mode: string; duration: number; platform?: string; delay?: number; color: string;
}) {
    const TransitIcon = mode === 'rail' ? IconTrain : mode === 'bus' ? IconBus : IconTrain;

    return (
        <div className="flex gap-3" style={{ minHeight: 56 }}>
            <div className="w-[52px]" />
            <div className="flex w-5 flex-col items-center">
                <div className="flex-1 rounded-[3px]" style={{ width: 6, background: color }} />
            </div>
            <div className="flex items-center gap-2 py-2">
                <TransitIcon size={16} stroke={ICON_STROKE} className="shrink-0" style={{ color }} />
                <div>
                    <div className="flex items-center gap-1.5">
                        <span className="inline-flex items-center rounded px-[6px] py-[1px] font-mono text-xs font-bold text-white" style={{ background: color }}>
                            {line}
                        </span>
                        <span className="text-[13px] text-[#18170F] dark:text-[#F5F4F0]">{direction}</span>
                    </div>
                    <div className="mt-[2px] text-xs text-[#AAA89F] dark:text-[#6B6860]">
                        {duration} min
                        {platform ? ` · Platform ${platform}` : ''}
                        {(delay ?? 0) > 0 && (
                            <span className="font-semibold text-[#C47D0E]"> · +{delay}min late</span>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function getLineColor(line: string, mode: string): string {
    // Cologne-specific line colors
    const colors: Record<string, string> = {
        '1': '#E8914A', '3': '#7C3AED', '4': '#C4271A', '5': '#B8562A',
        '7': '#0A7C52', '9': '#1A4CD4', '12': '#0A7C52', '13': '#C47D0E',
        '15': '#1A4CD4', '16': '#0A7C52', '17': '#C4271A', '18': '#1A4CD4',
    };
    if (colors[line]) return colors[line];
    if (mode === 'rail') return '#C4271A';
    if (mode === 'bus') return '#E8914A';
    return '#1A4CD4';
}

function addMinutes(time: string, mins: number): string {
    const [h, m] = time.split(':').map(Number);
    if (isNaN(h) || isNaN(m)) return '';
    const total = h * 60 + m + mins;
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
