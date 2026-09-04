import React, { useMemo } from 'react';

/**
 * A bare inline SVG trend line — no charting library, because this renders nine
 * times on a page that must stay cheap.
 *
 * Gaps matter here. A signal that could not be read records null, and a null is
 * drawn as a break in the line rather than a dip to zero, because "we could not
 * see" and "there was none" are different facts and conflating them is the
 * exact mistake the sampler is built to avoid.
 */
export default function Sparkline({ values = [], tone = 'teal', height = 26 }) {
    const { path, gaps, min, max, hasData } = useMemo(() => {
        const points = Array.isArray(values) ? values : [];
        const numeric = points.filter((v) => typeof v === 'number' && Number.isFinite(v));

        if (numeric.length < 2) {
            return { path: '', gaps: 0, min: 0, max: 0, hasData: false };
        }

        const lo = Math.min(...numeric);
        const hi = Math.max(...numeric);
        const span = hi - lo || 1;
        const width = 100;
        const step = points.length > 1 ? width / (points.length - 1) : width;

        let d = '';
        let breaks = 0;
        let pen = false;

        points.forEach((value, index) => {
            if (typeof value !== 'number' || !Number.isFinite(value)) {
                if (pen) breaks += 1;
                pen = false;
                return;
            }
            const x = index * step;
            const y = height - ((value - lo) / span) * (height - 2) - 1;
            d += `${pen ? 'L' : 'M'}${x.toFixed(2)},${y.toFixed(2)} `;
            pen = true;
        });

        return { path: d.trim(), gaps: breaks, min: lo, max: hi, hasData: true };
    }, [values, height]);

    if (!hasData) {
        return (
            <div className="mt-2 flex h-[26px] items-center justify-center rounded bg-slate-50 text-[10px] text-slate-400">
                Not enough history yet
            </div>
        );
    }

    const stroke = { teal: '#0d9488', amber: '#d97706', rose: '#e11d48' }[tone] || '#0d9488';

    return (
        <div className="mt-2" title={`Range over the window: ${min} – ${max}${gaps ? ` · ${gaps} gap(s) where the signal was unreadable` : ''}`}>
            <svg viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" className="h-[26px] w-full" role="img" aria-label={`Trend, range ${min} to ${max}`}>
                <path d={path} fill="none" stroke={stroke} strokeWidth="1.5" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
            </svg>
            <div className="flex justify-between text-[9px] leading-none text-slate-400">
                <span>{min}</span>
                <span>{max}</span>
            </div>
        </div>
    );
}
