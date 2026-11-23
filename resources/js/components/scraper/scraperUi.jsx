import React, { useEffect } from 'react';

export const HEALTH = {
    ready: { label: 'Ready', dot: 'bg-emerald-500', chip: 'border-emerald-200 bg-emerald-50 text-emerald-800', icon: '✓' },
    needs_config: { label: 'Needs setup', dot: 'bg-amber-500', chip: 'border-amber-200 bg-amber-50 text-amber-800', icon: '!' },
    blocked: { label: 'Blocked', dot: 'bg-rose-500', chip: 'border-rose-200 bg-rose-50 text-rose-800', icon: '✕' },
    error: { label: 'Error', dot: 'bg-rose-500', chip: 'border-rose-200 bg-rose-50 text-rose-800', icon: '✕' },
    unknown: { label: 'Not checked', dot: 'bg-slate-300', chip: 'border-slate-200 bg-slate-50 text-slate-600', icon: '?' },
};

export const RUN_STATUS = {
    queued: { label: 'Queued', chip: 'border-slate-200 bg-slate-50 text-slate-700' },
    running: { label: 'Running', chip: 'border-sky-200 bg-sky-50 text-sky-800' },
    success: { label: 'Finished', chip: 'border-emerald-200 bg-emerald-50 text-emerald-800' },
    partial: { label: 'Finished with errors', chip: 'border-amber-200 bg-amber-50 text-amber-800' },
    empty: { label: 'Nothing found', chip: 'border-amber-200 bg-amber-50 text-amber-800' },
    blocked: { label: 'Blocked', chip: 'border-rose-200 bg-rose-50 text-rose-800' },
    error: { label: 'Failed', chip: 'border-rose-200 bg-rose-50 text-rose-800' },
    cancelled: { label: 'Cancelled', chip: 'border-slate-200 bg-slate-50 text-slate-600' },
};

export const TECH_CATEGORY = {
    cdn_waf: { label: 'CDN / firewall', tone: 'border-violet-200 bg-violet-50 text-violet-800' },
    bot_protection: { label: 'Bot protection', tone: 'border-rose-200 bg-rose-50 text-rose-800' },
    cms: { label: 'CMS', tone: 'border-sky-200 bg-sky-50 text-sky-800' },
    theme: { label: 'Theme', tone: 'border-sky-200 bg-white text-sky-800' },
    framework: { label: 'Framework', tone: 'border-teal-200 bg-teal-50 text-teal-800' },
    plugin: { label: 'Plugins', tone: 'border-slate-200 bg-white text-slate-600' },
    cache: { label: 'Caching', tone: 'border-slate-200 bg-slate-50 text-slate-700' },
    server: { label: 'Server', tone: 'border-slate-200 bg-slate-50 text-slate-700' },
    language: { label: 'Language', tone: 'border-slate-200 bg-slate-50 text-slate-700' },
    js_library: { label: 'JavaScript', tone: 'border-amber-200 bg-amber-50 text-amber-800' },
    ui: { label: 'UI kit', tone: 'border-slate-200 bg-slate-50 text-slate-700' },
    analytics: { label: 'Analytics', tone: 'border-slate-200 bg-white text-slate-500' },
};

export const TECH_ORDER = ['cdn_waf', 'bot_protection', 'cms', 'theme', 'framework', 'server', 'language', 'cache', 'js_library', 'ui', 'plugin', 'analytics'];

export function HealthChip({ status, size = 'sm' }) {
    const h = HEALTH[status] || HEALTH.unknown;
    const pad = size === 'lg' ? 'px-3 py-1 text-sm' : 'px-2 py-0.5 text-xs';

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full border font-semibold ${pad} ${h.chip}`}>
            <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${h.dot}`} />
            {h.label}
        </span>
    );
}

export function RunChip({ status }) {
    const s = RUN_STATUS[status] || { label: status || '—', chip: 'border-slate-200 bg-slate-50 text-slate-600' };

    return <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold ${s.chip}`}>{s.label}</span>;
}

export function TechChip({ tech }) {
    const cat = TECH_CATEGORY[tech.category] || TECH_CATEGORY.plugin;
    const name = String(tech.name || '').replace(/^(Theme|Plugin): /, '');

    return (
        <span title={tech.evidence ? `Detected from ${tech.evidence}` : undefined} className={`inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium ${cat.tone}`}>
            {name}
            {tech.version ? <span className="crm-mono text-[10px] opacity-70">{tech.version}</span> : null}
        </span>
    );
}

export function Fact({ label, children, mono = false }) {
    return (
        <div className="min-w-0">
            <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</dt>
            <dd className={`mt-0.5 break-words text-sm text-slate-800 ${mono ? 'crm-mono text-[13px]' : ''}`}>{children}</dd>
        </div>
    );
}

export function Spinner({ className = 'h-4 w-4' }) {
    return (
        <svg className={`animate-spin motion-reduce:animate-none ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}

export function timeAgo(iso) {
    if (!iso) return 'never';
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)} h ago`;
    return `${Math.round(seconds / 86400)} d ago`;
}

export function errorMessage(error, fallback) {
    const data = error?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
    }
    return data?.message || fallback;
}

export function groupTech(tech = []) {
    const groups = {};
    tech.forEach((t) => {
        (groups[t.category] ||= []).push(t);
    });
    return TECH_ORDER.filter((c) => groups[c]).map((c) => ({ category: c, items: groups[c] }));
}

/**
 * Freeze the page behind an open dialog: the CRM scrolls inside <main>, so lock that
 * (and the document, just in case) and restore the previous values on close.
 */
export function useScrollLock(active = true) {
    useEffect(() => {
        if (!active) return undefined;
        const targets = [document.querySelector('main'), document.documentElement].filter(Boolean);
        const previous = targets.map((el) => el.style.overflow);
        targets.forEach((el) => { el.style.overflow = 'hidden'; });
        return () => targets.forEach((el, i) => { el.style.overflow = previous[i]; });
    }, [active]);
}
