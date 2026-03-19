export const RETENTION_BANDS = ['Stable', 'Watchlist', 'Needs Attention', 'Critical'];

export const RETENTION_BEHAVIOR_TAGS = [
    'Champion',
    'Stable',
    'Trial Converting',
    'Payment Friction',
    'Renewal Risk',
    'Dormant',
    'Win-back Candidate',
];

export function isRetentionWatchBand(band) {
    return ['Watchlist', 'Needs Attention', 'Critical'].includes(String(band || ''));
}

export function retentionBandClasses(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    case 'Watchlist':
        return 'bg-sky-50 text-sky-700 ring-sky-200';
    case 'Needs Attention':
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    case 'Critical':
        return 'bg-rose-50 text-rose-700 ring-rose-200';
    default:
        return 'bg-slate-100 text-slate-600 ring-slate-200';
    }
}

export function retentionBandAccent(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'bg-emerald-500';
    case 'Watchlist':
        return 'bg-sky-500';
    case 'Needs Attention':
        return 'bg-amber-500';
    case 'Critical':
        return 'bg-rose-500';
    default:
        return 'bg-slate-400';
    }
}

export function retentionBandTone(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'success';
    case 'Watchlist':
        return 'accent';
    case 'Needs Attention':
        return 'warning';
    case 'Critical':
        return 'danger';
    default:
        return 'default';
    }
}
