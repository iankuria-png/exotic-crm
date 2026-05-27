import { formatCurrency } from '../../utils/currency';
import { getCountryFlag } from '../../utils/flags';

export function moneyFromBreakdown(breakdown = {}, normalizedTotal = null, normalizedCurrency = 'USD', mode = 'flat') {
    if (mode === 'flat' && normalizedTotal !== null && normalizedTotal !== undefined) {
        return formatCurrency(normalizedTotal, normalizedCurrency);
    }

    const entries = Object.entries(breakdown || {}).filter(([, value]) => Number(value || 0) !== 0);
    if (entries.length === 0) return formatCurrency(0, normalizedCurrency);
    if (entries.length === 1) return formatCurrency(entries[0][1], entries[0][0]);

    return entries
        .slice(0, 2)
        .map(([currency, amount]) => formatCurrency(amount, currency))
        .join(' + ');
}

export function formatDelta(value) {
    if (value === null || value === undefined) return 'No baseline';
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'No baseline';
    return `${numeric >= 0 ? '+' : ''}${numeric.toFixed(1)}%`;
}

export function deltaTone(value) {
    if (value === null || value === undefined) return 'default';
    return Number(value) >= 0 ? 'success' : 'warning';
}

export function marketLabel(market) {
    if (!market) return 'All markets';
    return `${getCountryFlag(market.country)} ${market.name}`;
}

export function relativeTime(value) {
    if (!value) return '--';
    const timestamp = new Date(value).getTime();
    if (Number.isNaN(timestamp)) return '--';
    const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 14) return `${days}d ago`;
    return new Date(value).toLocaleDateString();
}
