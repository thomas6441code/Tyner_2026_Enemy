interface BarChartProps {
    values: number[];
    labels: string[];
    highlightIndex?: number;
}

const W = 460;
const H = 260;
const PAD = { top: 30, right: 12, bottom: 28, left: 28 };

export function BarChart({ values, labels, highlightIndex }: BarChartProps) {
    const max = Math.max(...values, 1);
    const top = Math.ceil(max * 1.15);

    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;
    const slot = innerW / values.length;
    const barW = Math.min(slot * 0.5, 26);

    const baseY = PAD.top + innerH;
    const barX = (i: number) => PAD.left + slot * i + (slot - barW) / 2;
    const barH = (v: number) => (v / top) * innerH;

    const gridLines = 4;
    const yTicks = Array.from({ length: gridLines + 1 }, (_, i) => (top * i) / gridLines);

    return (
        <svg viewBox={`0 0 ${W} ${H}`} className="w-full" preserveAspectRatio="xMidYMid meet" role="img">
            <defs>
                <pattern id="barStripes" width="6" height="6" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
                    <rect width="6" height="6" fill="rgb(16 185 129)" />
                    <line x1="0" y1="0" x2="0" y2="6" stroke="white" strokeWidth="2.5" opacity="0.55" />
                </pattern>
            </defs>

            {yTicks.map((t, i) => (
                <g key={i}>
                    <line
                        x1={PAD.left}
                        x2={W - PAD.right}
                        y1={baseY - (t / top) * innerH}
                        y2={baseY - (t / top) * innerH}
                        stroke="currentColor"
                        className="text-border"
                        strokeWidth={1}
                    />
                    <text
                        x={PAD.left - 6}
                        y={baseY - (t / top) * innerH + 3}
                        textAnchor="end"
                        className="fill-muted-foreground text-[9px]"
                    >
                        {Math.round(t)}
                    </text>
                </g>
            ))}

            {values.map((v, i) => {
                const isHi = i === highlightIndex;
                const h = barH(v);
                return (
                    <g key={i}>
                        <rect
                            x={barX(i)}
                            y={baseY - h}
                            width={barW}
                            height={h}
                            rx={5}
                            fill={isHi ? 'url(#barStripes)' : 'currentColor'}
                            className={isHi ? '' : 'text-muted'}
                        />
                        <text x={barX(i) + barW / 2} y={H - 10} textAnchor="middle" className="fill-muted-foreground text-[9px]">
                            {labels[i]}
                        </text>

                        {isHi && (
                            <g>
                                <rect x={barX(i) + barW / 2 - 16} y={baseY - h - 26} width={32} height={19} rx={6} className="fill-foreground" />
                                <text
                                    x={barX(i) + barW / 2}
                                    y={baseY - h - 13}
                                    textAnchor="middle"
                                    className="fill-background text-[10px] font-semibold"
                                >
                                    {v}
                                </text>
                            </g>
                        )}
                    </g>
                );
            })}
        </svg>
    );
}
