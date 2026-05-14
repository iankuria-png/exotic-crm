import React from 'react';

export default function ProgressRing({ value = 0, size = 48, label = null }) {
    const pct = Math.max(0, Math.min(100, Number(value || 0)));
    const radius = 18;
    const circumference = 2 * Math.PI * radius;
    const dash = circumference - (pct / 100) * circumference;

    return (
        <span className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
            <svg className="h-full w-full -rotate-90" viewBox="0 0 44 44" aria-hidden="true">
                <circle cx="22" cy="22" r={radius} fill="none" stroke="currentColor" strokeWidth="4" className="text-slate-200" />
                <circle
                    cx="22"
                    cy="22"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="4"
                    strokeDasharray={circumference}
                    strokeDashoffset={dash}
                    strokeLinecap="round"
                    className="text-teal-600 transition-all duration-500"
                />
            </svg>
            <span className="absolute text-[11px] font-bold text-slate-800">{label || `${Math.round(pct)}%`}</span>
        </span>
    );
}
