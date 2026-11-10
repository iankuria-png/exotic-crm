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

const initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(part => part[0]?.toUpperCase() || '').join('') || '?';
const exactTime = value => value ? new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value)) + ' EAT' : '';

// Who activated the subscription: automation, a named staff member, or an honest "not recorded".
export function ActivatedBy({ activation, compact = false }) {
    if (!activation) return null;
    const when = activation.at ? <time dateTime={activation.at} title={exactTime(activation.at)} className="text-slate-500"> · {relative(activation.at)}</time> : null;
    const verb = activation.pending ? 'Started by' : 'Activated by';
    if (activation.kind === 'automatic') return <p className={`flex min-w-0 items-center gap-2 text-xs ${compact ? '' : 'mt-2'}`} aria-label={`${activation.pending ? 'Automatic activation in progress' : 'Activated automatically'}${activation.at ? `, ${exactTime(activation.at)}` : ''}`}>
        <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-white"><Icon name="bolt" className="h-3.5 w-3.5" /></span>
        <span className="min-w-0"><span className="block text-[11px] uppercase tracking-wide text-slate-500">{verb}</span><span className="font-semibold text-teal-900">Automatic</span>{when}</span>
    </p>;
    if (activation.kind === 'staff') return <p className={`flex min-w-0 items-center gap-2 text-xs ${compact ? '' : 'mt-2'}`} aria-label={`${verb} ${activation.actor.name}${activation.at ? `, ${exactTime(activation.at)}` : ''}`}>
        <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold text-indigo-900 ring-1 ring-inset ring-indigo-200">{initials(activation.actor.name)}</span>
        <span className="min-w-0"><span className="block text-[11px] uppercase tracking-wide text-slate-500">{verb}</span><span className="break-words font-semibold text-slate-900">{activation.actor.name}</span>{when}</span>
    </p>;
    return <p className={`flex items-center gap-2 text-xs text-slate-500 ${compact ? '' : 'mt-2'}`}><span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200">?</span>Activator not recorded{when}</p>;
}

// Payment → activation duration: from the provider's paid time (or phone arrival) to the confirmed activation.
export function activationMinutes(row) {
    const activation = row?.activation;
    const paid = row?.provider_at || row?.received_at;
    if (!activation?.at || activation.pending || !paid) return null;
    const minutes = (new Date(activation.at).getTime() - new Date(paid).getTime()) / 60000;
    return Number.isFinite(minutes) ? minutes : null;
}
export function formatDuration(minutes) {
    if (minutes < 1) return 'under 1 min';
    const total = Math.round(minutes);
    if (total < 60) return `${total} min`;
    const hours = Math.floor(total / 60);
    if (hours < 24) return total % 60 ? `${hours} h ${total % 60} min` : `${hours} h`;
    const days = Math.floor(hours / 24);
    return hours % 24 ? `${days} d ${hours % 24} h` : `${days} d`;
}
export const speedTone = minutes => minutes <= 15 ? 'live' : minutes <= 120 ? 'neutral' : 'action';
export function median(values) {
    if (!values.length) return null;
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

export function TimeToActivate({ row, className = '' }) {
    const minutes = activationMinutes(row);
    if (minutes === null) return null;
    const paid = row.provider_at || row.received_at;
    const span = `${row.provider_at ? 'Paid' : 'Phone arrival'} ${exactTime(paid)} → activated ${exactTime(row.activation.at)}`;
    if (minutes < -1) return <p className={`mt-2 text-xs text-slate-500 ${className}`} title={span}>Activated before this payment's time</p>;
    const tone = tones[speedTone(minutes)];
    return <p className={`mt-2 ${className}`} title={span} aria-label={`Payment to active in ${formatDuration(Math.max(0, minutes))}. ${span}`}>
        <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${tone.pill}`}>
            <Icon name="pending" className="h-3.5 w-3.5" />
            <span className="font-normal opacity-80">Paid → active</span>
            <span className="font-semibold tabular-nums">{formatDuration(Math.max(0, minutes))}</span>
        </span>
    </p>;
}

export function ActivationSpeedSummary({ rows }) {
    const timed = (rows || []).map(row => ({ kind: row.activation?.kind, minutes: activationMinutes(row) })).filter(item => item.minutes !== null && item.minutes >= 0);
    if (!timed.length) return null;
    const groups = [
        ['All activations', timed],
        ['Automatic', timed.filter(item => item.kind === 'automatic')],
        ['Customer service', timed.filter(item => item.kind === 'staff')],
    ];
    return <section aria-label="Payment to activation time" className="mb-4 rounded-xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2"><h3 className="text-sm font-semibold text-slate-900">Payment → active</h3><p className="text-xs text-slate-500">Median time for the activated payments shown below</p></div>
        <dl className="mt-3 grid gap-3 sm:grid-cols-3">{groups.map(([label, items]) => {
            const value = median(items.map(item => item.minutes));
            return <div key={label} className="rounded-lg bg-slate-50 px-3 py-2.5">
                <dt className="flex items-center gap-1.5 text-xs text-slate-500">{label === 'Automatic' && <Icon name="bolt" className="h-3.5 w-3.5 text-teal-700" />}{label}</dt>
                <dd className="mt-1 flex items-baseline gap-2"><span className={`text-lg font-semibold tabular-nums ${value === null ? 'text-slate-400' : value <= 15 ? 'text-teal-800' : value <= 120 ? 'text-slate-900' : 'text-amber-800'}`}>{value === null ? '—' : formatDuration(value)}</span><span className="text-xs text-slate-500">{items.length} {items.length === 1 ? 'payment' : 'payments'}</span></dd>
            </div>;
        })}</dl>
    </section>;
}
