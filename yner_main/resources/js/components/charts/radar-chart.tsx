interface RadarChartProps {
    data: { label: string; value: number }[];
    rings?: number;
}

const SIZE = 300;
const CENTER = SIZE / 2;
const RADIUS = 105;

function point(angleDeg: number, radius: number) {
    const a = (angleDeg * Math.PI) / 180;
    return { x: CENTER + radius * Math.cos(a), y: CENTER + radius * Math.sin(a) };
}

export function RadarChart({ data, rings = 4 }: RadarChartProps) {
    const n = data.length;
    const max = Math.max(...data.map((d) => d.value), 1);
    const niceMax = Math.ceil(max / rings) * rings || rings;

    const angleAt = (i: number) => -90 + (360 / n) * i;

    const ringPolys = Array.from({ length: rings }, (_, r) => {
        const radius = (RADIUS * (r + 1)) / rings;
        return data.map((_, i) => point(angleAt(i), radius)).map((p) => `${p.x},${p.y}`).join(' ');
    });

    const dataPoly = data
        .map((d, i) => point(angleAt(i), (d.value / niceMax) * RADIUS))
        .map((p) => `${p.x},${p.y}`)
        .join(' ');

    return (
        <svg viewBox={`0 0 ${SIZE} ${SIZE}`} className="mx-auto w-full max-w-[300px]" role="img">
            {ringPolys.map((poly, i) => (
                <polygon key={i} points={poly} fill="none" stroke="currentColor" className="text-border" strokeWidth={1} />
            ))}

            {data.map((_, i) => {
                const end = point(angleAt(i), RADIUS);
                return <line key={i} x1={CENTER} y1={CENTER} x2={end.x} y2={end.y} stroke="currentColor" className="text-border" strokeWidth={1} />;
            })}

            {Array.from({ length: rings }, (_, r) => {
                const v = (niceMax * (r + 1)) / rings;
                const radius = (RADIUS * (r + 1)) / rings;
                return (
                    <text key={r} x={CENTER + 4} y={CENTER - radius + 4} className="fill-muted-foreground text-[8px]">
                        {String(Math.round(v)).padStart(2, '0')}
                    </text>
                );
            })}

            <polygon points={dataPoly} fill="rgb(16 185 129)" fillOpacity={0.25} stroke="rgb(16 185 129)" strokeWidth={2} />
            {data.map((d, i) => {
                const p = point(angleAt(i), (d.value / niceMax) * RADIUS);
                return <circle key={i} cx={p.x} cy={p.y} r={2.5} fill="rgb(16 185 129)" />;
            })}

            {data.map((d, i) => {
                const p = point(angleAt(i), RADIUS + 16);
                return (
                    <text key={i} x={p.x} y={p.y} textAnchor="middle" dominantBaseline="middle" className="fill-muted-foreground text-[9px] font-medium">
                        {d.label}
                    </text>
                );
            })}
        </svg>
    );
}
