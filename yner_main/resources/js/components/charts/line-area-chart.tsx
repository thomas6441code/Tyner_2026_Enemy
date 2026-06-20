interface LineAreaChartProps {
    values: number[];
    labels: string[];
    highlightIndex?: number;
}

const W = 640;
const H = 260;
const PAD = { top: 28, right: 18, bottom: 30, left: 34 };

function smoothPath(pts: { x: number; y: number }[]): string {
    if (pts.length < 2) {
        return pts.length === 1 ? `M ${pts[0].x},${pts[0].y}` : '';
    }
    let d = `M ${pts[0].x},${pts[0].y}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] ?? pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] ?? p2;
        const cp1x = p1.x + (p2.x - p0.x) / 6;
        const cp1y = p1.y + (p2.y - p0.y) / 6;
        const cp2x = p2.x - (p3.x - p1.x) / 6;
        const cp2y = p2.y - (p3.y - p1.y) / 6;
        d += ` C ${cp1x},${cp1y} ${cp2x},${cp2y} ${p2.x},${p2.y}`;
    }
    return d;
}

export function LineAreaChart({ values, labels, highlightIndex }: LineAreaChartProps) {
    const max = Math.max(...values);
    const min = Math.min(...values);
    const range = max - min || 1;
    const top = max + range * 0.25;
    const bottom = min - range * 0.25;
    const span = top - bottom || 1;

    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;

    const x = (i: number) => PAD.left + (values.length === 1 ? innerW / 2 : (i / (values.length - 1)) * innerW);
    const y = (v: number) => PAD.top + innerH - ((v - bottom) / span) * innerH;

    const pts = values.map((v, i) => ({ x: x(i), y: y(v) }));
    const line = smoothPath(pts);
    const area = `${line} L ${pts[pts.length - 1].x},${PAD.top + innerH} L ${pts[0].x},${PAD.top + innerH} Z`;

    const gridLines = 4;
    const yTicks = Array.from({ length: gridLines + 1 }, (_, i) => bottom + (span * i) / gridLines);

    return (
        <svg viewBox={`0 0 ${W} ${H}`} className="w-full" preserveAspectRatio="xMidYMid meet" role="img">
            <defs>
                <linearGradient id="lineAreaFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="rgb(16 185 129)" stopOpacity="0.28" />
                    <stop offset="100%" stopColor="rgb(16 185 129)" stopOpacity="0" />
                </linearGradient>
            </defs>

            {yTicks.map((t, i) => (
                <g key={i}>
                    <line
                        x1={PAD.left}
                        x2={W - PAD.right}
                        y1={y(t)}
                        y2={y(t)}
                        stroke="currentColor"
                        className="text-border"
                        strokeDasharray="3 4"
                        strokeWidth={1}
                    />
                    <text x={PAD.left - 8} y={y(t) + 3} textAnchor="end" className="fill-muted-foreground text-[9px]">
                        {Math.round(t)}
                    </text>
                </g>
            ))}

            {highlightIndex !== undefined && pts[highlightIndex] && (
                <rect
                    x={pts[highlightIndex].x - 10}
                    y={PAD.top}
                    width={20}
                    height={innerH}
                    rx={6}
                    fill="rgb(16 185 129)"
                    opacity={0.12}
                />
            )}

            <path d={area} fill="url(#lineAreaFill)" />
            <path d={line} fill="none" stroke="rgb(16 185 129)" strokeWidth={2.5} strokeLinecap="round" />

            {pts.map((p, i) => (
                <circle
                    key={i}
                    cx={p.x}
                    cy={p.y}
                    r={i === highlightIndex ? 5 : 3.5}
                    fill="white"
                    stroke="rgb(16 185 129)"
                    strokeWidth={2}
                />
            ))}

            {highlightIndex !== undefined && pts[highlightIndex] && (
                <g>
                    <rect
                        x={pts[highlightIndex].x - 18}
                        y={pts[highlightIndex].y - 30}
                        width={36}
                        height={20}
                        rx={6}
                        className="fill-foreground"
                    />
                    <text
                        x={pts[highlightIndex].x}
                        y={pts[highlightIndex].y - 16}
                        textAnchor="middle"
                        className="fill-background text-[10px] font-semibold"
                    >
                        {values[highlightIndex]}
                    </text>
                </g>
            )}

            {labels.map((label, i) => (
                <text
                    key={i}
                    x={x(i)}
                    y={H - 10}
                    textAnchor="middle"
                    className="fill-muted-foreground text-[9px]"
                >
                    {label}
                </text>
            ))}
        </svg>
    );
}
