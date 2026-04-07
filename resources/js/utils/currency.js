/**
 * Shared currency formatting utilities.
 * Replaces the three inline copies previously in Payments.jsx, Dashboard.jsx,
 * and Reports.jsx.
 */

export function formatCurrency(amount, currency = 'KES') {
    return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

export function asNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}
