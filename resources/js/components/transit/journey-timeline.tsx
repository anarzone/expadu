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
            <div style={{ width: 52, textAlign: 'right', fontSize: 13, fontWeight: 600, color: '#18170F', fontFamily: "'Geist Mono', monospace" }}>
                {time}
            </div>
            <div className="flex flex-col items-center" style={{ width: 20 }}>
                <div style={{
                    width: 14,
                    height: 14,
                    borderRadius: '50%',
                    border: `3px solid ${isFirst ? '#0A7C52' : isLast ? '#C4271A' : '#18170F'}`,
                    background: 'white',
                }} />
            </div>
            <div style={{ fontSize: 14, fontWeight: 600, color: '#18170F' }}>
                {name}
            </div>
        </div>
    );
}

function WalkLeg({ duration }: { duration: number }) {
    return (
        <div className="flex gap-3" style={{ minHeight: 48 }}>
            <div style={{ width: 52 }} />
            <div className="flex flex-col items-center" style={{ width: 20 }}>
                {/* Dotted line */}
                <div style={{ flex: 1, width: 3, background: 'repeating-linear-gradient(to bottom, #1A4CD4 0px, #1A4CD4 4px, transparent 4px, transparent 10px)' }} />
            </div>
            <div className="flex items-center gap-2 py-2">
                <span style={{ fontSize: 14 }}>🚶</span>
                <div>
                    <div style={{ fontSize: 13, color: '#6B6860' }}>Walk</div>
                    <div style={{ fontSize: 12, color: '#AAA89F' }}>About {duration} min</div>
                </div>
            </div>
        </div>
    );
}

function TransitLeg({ line, direction, mode, duration, platform, delay, color }: {
    line: string; direction: string; mode: string; duration: number; platform?: string; delay?: number; color: string;
}) {
    const emoji = mode === 'rail' ? '🚂' : mode === 'bus' ? '🚌' : '🚋';

    return (
        <div className="flex gap-3" style={{ minHeight: 56 }}>
            <div style={{ width: 52 }} />
            <div className="flex flex-col items-center" style={{ width: 20 }}>
                {/* Colored solid line */}
                <div style={{ flex: 1, width: 6, borderRadius: 3, background: color }} />
            </div>
            <div className="flex items-center gap-2 py-2">
                <span style={{ fontSize: 14 }}>{emoji}</span>
                <div>
                    <div className="flex items-center gap-1.5">
                        <span style={{ display: 'inline-flex', alignItems: 'center', background: color, color: 'white', borderRadius: 4, padding: '1px 6px', fontSize: 12, fontWeight: 700, fontFamily: "'Geist Mono', monospace" }}>
                            {line}
                        </span>
                        <span style={{ fontSize: 13, color: '#18170F' }}>{direction}</span>
                    </div>
                    <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 2 }}>
                        {duration} min
                        {platform ? ` · Platform ${platform}` : ''}
                        {(delay ?? 0) > 0 && (
                            <span style={{ color: '#C47D0E', fontWeight: 600 }}> · +{delay}min late</span>
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
