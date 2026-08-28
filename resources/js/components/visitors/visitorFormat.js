import { formatCurrency } from '../../utils/currency';

export const SCOPE_OPTIONS = [
    { value: 'single_profile', label: 'One-time profile unlock' },
    { value: 'market_inactive_profiles', label: 'All inactive contacts' },
];

export const UNLOCK_STATUS_OPTIONS = ['initiated', 'pending_payment', 'active', 'failed', 'expired', 'revoked', 'refunded'];
export const PAYMENT_STATUS_OPTIONS = ['initiated', 'pending', 'completed', 'expired', 'failed', 'canceled'];

export function titleize(value) {
    return String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function compactNumber(value) {
    return Number(value || 0).toLocaleString();
}

export function percentLabel(value) {
    const number = Number(value || 0);
    return `${number.toFixed(number % 1 === 0 ? 0 : 1)}%`;
}

export function moneyRowsLabel(rows = [], fallback = '-') {
    if (!rows.length) return fallback;
    return rows
        .map((entry) => formatCurrency(entry.amount || 0, entry.currency || 'USD'))
        .join(' + ');
}

export function revenueDisplay({ rows = [], normalizedAmount, normalizedDisplay, normalizedCurrency, reporting, emptyLabel = 'No revenue yet' }) {
    if (reporting?.isFlat && normalizedAmount !== null && normalizedAmount !== undefined) {
        return {
            value: normalizedDisplay || formatCurrency(normalizedAmount, normalizedCurrency || reporting.targetCurrency),
            hint: `Normalized to ${normalizedCurrency || reporting.targetCurrency}`,
        };
    }

    return {
        value: moneyRowsLabel(rows, emptyLabel),
        hint: reporting?.isFlat ? 'Native value; FX incomplete' : 'Native currencies',
    };
}

export function providerLabel(provider) {
    const key = String(provider || '').toLowerCase();
    if (key === 'pawapay') return 'pawaPay';
    if (key === 'kopokopo') return 'KopoKopo';
    return titleize(key || 'Provider');
}

export function shortDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function visitorContextParts(context = {}) {
    const platform = context.platform || '';
    const mobile = context.mobile_hint === true || Number(context.device?.max_touch_points || 0) > 0;
    return [
        context.locale || '',
        context.timezone || '',
        platform && mobile ? `${platform} mobile` : platform,
        context.viewport?.width && context.viewport?.height ? `${context.viewport.width}x${context.viewport.height}` : '',
        context.ip_masked ? `IP ${context.ip_masked}` : '',
    ].filter(Boolean);
}

export function copyText(value, toast) {
    const text = String(value || '').trim();
    if (!text) return;
    navigator.clipboard?.writeText(text).then(
        () => toast?.success?.('Copied.'),
        () => toast?.error?.('Could not copy.')
    );
}
