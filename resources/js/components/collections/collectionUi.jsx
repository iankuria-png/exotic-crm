import React from 'react';

// Shared visual language for the incoming-payments workspace: semantic tone, compact pills and EAT time.
export const tones = {
    live: { pill: 'bg-teal-50 text-teal-900 ring-teal-600/25', dot: 'bg-teal-600', bar: 'bg-teal-600', edge: 'border-l-teal-500' },
    action: { pill: 'bg-amber-50 text-amber-950 ring-amber-600/30', dot: 'bg-amber-500', bar: 'bg-amber-500', edge: 'border-l-amber-400' },
    busy: { pill: 'bg-sky-50 text-sky-950 ring-sky-600/25', dot: 'bg-sky-500', bar: 'bg-sky-500', edge: 'border-l-sky-400' },
    danger: { pill: 'bg-rose-50 text-rose-900 ring-rose-600/25', dot: 'bg-rose-600', bar: 'bg-rose-600', edge: 'border-l-rose-500' },
    neutral: { pill: 'bg-slate-100 text-slate-700 ring-slate-500/20', dot: 'bg-slate-400', bar: 'bg-slate-400', edge: 'border-l-slate-200' },
};

export function StatusPill({ tone = 'neutral', pulse = false, children, className = '' }) {
    const t = tones[tone] || tones.neutral;
    return <span className={`inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${t.pill} ${className}`}>
        <span aria-hidden="true" className="relative flex h-2 w-2 shrink-0">
            {pulse && <span className={`absolute inline-flex h-full w-full rounded-full opacity-60 motion-safe:animate-ping ${t.dot}`} />}
            <span className={`relative inline-flex h-2 w-2 rounded-full ${t.dot}`} />
        </span>
        <span className="truncate">{children}</span>
    </span>;
}

const paths = {
    check: 'M5 10.5l3.2 3.2L15 7',
    pending: 'M10 17a7 7 0 100-14 7 7 0 000 14zM10 6.8V10l2.2 1.5',
    alert: 'M10 6.5v4.2M10 13.6v.1',
    search: 'M9 15a6 6 0 100-12 6 6 0 000 12zm4.3-1.7L17 17',
    download: 'M10 3v9m0 0l-3.5-3.5M10 12l3.5-3.5M4 15.5h12',
    close: 'M5.5 5.5l9 9m0-9l-9 9',
    copy: 'M7 7V4.5A1.5 1.5 0 018.5 3h7A1.5 1.5 0 0117 4.5v7a1.5 1.5 0 01-1.5 1.5H13M4.5 7h7A1.5 1.5 0 0113 8.5v7a1.5 1.5 0 01-1.5 1.5h-7A1.5 1.5 0 013 15.5v-7A1.5 1.5 0 014.5 7z',
    bolt: 'M11 2.5L4.5 11h5l-1 6.5L15 9h-5l1-6.5z',
    pause: 'M7.5 5v10M12.5 5v10',
    inbox: 'M3 11l2-6.5h10L17 11m-14 0v4.5h14V11m-14 0h4l1 2h4l1-2h4',
    chevron: 'M7.5 5l5 5-5 5',
};
export function Icon({ name, className = 'h-4 w-4' }) {
    return <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" className={`shrink-0 ${className}`}><path d={paths[name]} /></svg>;
}

export function CheckMark({ state }) {
    if (state === 'done') return <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-700 text-white"><Icon name="check" className="h-3.5 w-3.5" /></span>;
    if (state === 'blocked') return <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-300"><Icon name="alert" className="h-3.5 w-3.5" /></span>;
    return <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-slate-400 ring-1 ring-inset ring-slate-300"><Icon name="pending" className="h-3.5 w-3.5" /></span>;
}

const eatFormat = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false });
export const eat = value => value ? `${eatFormat.format(new Date(value))} EAT` : '';
export function relative(value, now = Date.now()) {
    if (!value) return '';
    const minutes = Math.round((now - new Date(value).getTime()) / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} h ago`;
    const days = Math.floor(hours / 24);
    return days === 1 ? 'yesterday' : `${days} days ago`;
}
export function remaining(value, now = Date.now()) {
    const minutes = Math.max(0, Math.round((new Date(value).getTime() - now) / 60000));
    return minutes >= 60 ? `${Math.floor(minutes / 60)} h ${minutes % 60} min left` : `${minutes} min left`;
}

export const buttonBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-45';
export const primaryButton = `${buttonBase} bg-teal-700 text-white shadow-sm hover:bg-teal-800`;
export const secondaryButton = `${buttonBase} border border-slate-300 bg-white text-slate-700 hover:bg-slate-50`;
export const warnButton = `${buttonBase} border border-amber-500 bg-white text-amber-900 hover:bg-amber-50`;
export const quietButton = `${buttonBase} px-2 text-teal-800 hover:bg-teal-50`;
