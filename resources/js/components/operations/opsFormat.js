export const LEVEL_TONE = {
    0: { pill: 'bg-emerald-600 text-white', ring: 'ring-emerald-200', text: 'text-emerald-700' },
    1: { pill: 'bg-amber-500 text-white', ring: 'ring-amber-200', text: 'text-amber-700' },
    2: { pill: 'bg-orange-600 text-white', ring: 'ring-orange-200', text: 'text-orange-700' },
    3: { pill: 'bg-rose-600 text-white', ring: 'ring-rose-200', text: 'text-rose-700' },
};

export const SIGNAL_STATE_TONE = {
    ok: 'border-slate-200',
    watch: 'border-amber-300',
    shed: 'border-rose-300',
    unavailable: 'border-dashed border-slate-300',
};

export const CAPABILITY_LABELS = {
    auto_optimize: 'Auto Optimize engine',
    bulk_bio: 'Bulk bio generation',
    pbn_seed: 'PBN seeding',
    geocoding: 'City geocoding',
    push_campaigns: 'Push campaigns',
    ai_briefings: 'AI briefings',
    retention_insights: 'Retention insights',
    support_board_sync: 'Support Board sync',
    optimize_queue_worker: 'Optimize queue worker',
    heavy_queue_worker: 'Heavy queue worker',
};

export function capabilityLabel(key) {
    return CAPABILITY_LABELS[key] || String(key || '').replaceAll('_', ' ');
}

export function signalLabel(key) {
    return String(key || '').replaceAll('_', ' ');
}

/** Seconds as the shortest unit that still reads precisely. */
export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    const value = Number(seconds);
    if (!Number.isFinite(value)) return '—';
    if (value < 60) return `${Math.round(value * 10) / 10}s`;
    if (value < 3600) return `${Math.floor(value / 60)}m ${Math.round(value % 60)}s`;
    if (value < 86400) return `${Math.floor(value / 3600)}h ${Math.floor((value % 3600) / 60)}m`;
    return `${Math.floor(value / 86400)}d ${Math.floor((value % 86400) / 3600)}h`;
}

export function formatAgo(iso) {
    if (!iso) return 'Never';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return 'Never';
    const seconds = Math.max(0, Math.round((Date.now() - then) / 1000));
    return `${formatDuration(seconds)} ago`;
}

export function formatDateTime(iso) {
    if (!iso) return '—';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

export function formatSignalValue(signal) {
    if (!signal?.available) return 'Unavailable';
    if (signal.unit === 'seconds') return formatDuration(signal.value);
    return `${signal.value}`;
}
