const LEGACY_DURATION_DAYS = {
    daily: 1,
    weekly: 7,
    biweekly: 14,
    monthly: 30,
};

const DAY_MS = 24 * 60 * 60 * 1000;

// Default "Additional days" for renewing a deal: the length of the plan being renewed.
// The server reuses the old deal's price and duration, so this must not drift from it.
export function renewDaysForDeal(deal) {
    const explicit = Number(deal?.duration_days);
    if (Number.isInteger(explicit) && explicit > 0) {
        return String(explicit);
    }

    const legacy = LEGACY_DURATION_DAYS[String(deal?.duration || '').trim().toLowerCase()];
    if (legacy) {
        return String(legacy);
    }

    const activatedAt = Date.parse(deal?.activated_at || '');
    const expiresAt = Date.parse(deal?.expires_at || '');
    const previousPeriodDays = Math.round((expiresAt - activatedAt) / DAY_MS);
    if (Number.isFinite(previousPeriodDays) && previousPeriodDays > 0) {
        return String(previousPeriodDays);
    }

    return '30';
}
