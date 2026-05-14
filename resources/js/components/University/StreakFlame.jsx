import React from 'react';

export default function StreakFlame({ current = 0, longest = 0 }) {
    const intensity = current >= 30 ? 'red' : current >= 14 ? 'orange' : current >= 7 ? 'amber' : 'slate';
    const glow = {
        red: 'from-red-500 via-orange-500 to-amber-400',
        orange: 'from-orange-500 via-amber-400 to-yellow-300',
        amber: 'from-amber-500 via-yellow-400 to-yellow-200',
        slate: 'from-slate-400 via-slate-300 to-slate-200',
    }[intensity];

    return (
        <div className="flex items-center gap-3 rounded-2xl border border-white/10 bg-gradient-to-br from-slate-900 to-slate-800 px-4 py-3 text-white shadow-sm">
            <div className={`flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br ${glow} text-white shadow-lg`}>
                <svg className="h-7 w-7 drop-shadow" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2s4 4 4 8a4 4 0 0 1-8 0c0-2 1-4 1-4s-3 3-3 7a6 6 0 0 0 12 0c0-6-6-11-6-11Z" />
                </svg>
            </div>
            <div>
                <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-300">Streak</p>
                <p className="text-2xl font-bold leading-tight">{current} <span className="text-sm font-normal text-slate-400">day{current === 1 ? '' : 's'}</span></p>
                <p className="text-[11px] text-slate-400">Longest: {longest}</p>
            </div>
        </div>
    );
}
