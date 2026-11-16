export const COUNT_OPTIONS = [
    { key: 'available_count', label: 'Available' },
    { key: 'active_count', label: 'Active' },
    { key: 'unlockable_count', label: 'Unlockable' },
    { key: 'client_count', label: 'All clients' },
];

export const DEFAULT_FILTERS = {
    search: '', basis: 'available_count', min: '', max: '', inventory: 'all', mapped: 'all', band: 'all',
};

export function validateBounds(filters) {
    for (const key of ['min', 'max']) {
        if (filters[key] !== '' && (!/^\d+$/.test(filters[key]) || !Number.isSafeInteger(Number(filters[key])))) {
            return 'Enter whole numbers of zero or more for the count range.';
        }
    }
    if (filters.min !== '' && filters.max !== '' && Number(filters.min) > Number(filters.max)) {
        return 'Minimum count must be less than or equal to maximum count.';
    }
    return null;
}

export function metricValue(row, key) {
    if (['views', 'contact_rate', 'profile_unique_visits'].includes(key)) return row.engagement?.[key] ?? null;
    if (key === 'performance') return row.performance?.index ?? null;
    return row[key] ?? null;
}

export function filterAndSortLocations(locations, filters, sort) {
    if (validateBounds(filters)) return [];
    const search = filters.search.trim().toLocaleLowerCase();
    const rows = locations.filter((row) => {
        if (search && !String(row.display_city).toLocaleLowerCase().includes(search)) return false;
        const count = metricValue(row, filters.basis);
        if (filters.min !== '' && (count == null || count < Number(filters.min))) return false;
        if (filters.max !== '' && (count == null || count > Number(filters.max))) return false;
        if (filters.inventory === 'unlockable_only' && !(row.active_count === 0 && row.unlockable_count > 0)) return false;
        if (filters.mapped !== 'all' && Boolean(row.mapped) !== (filters.mapped === 'mapped')) return false;
        if (filters.band !== 'all' && row.performance?.band !== filters.band) return false;
        return true;
    });
    return rows.sort((left, right) => {
        const a = metricValue(left, sort.key);
        const b = metricValue(right, sort.key);
        // Missing measurements always follow measured values, even in descending order.
        if (a == null && b != null) return 1;
        if (b == null && a != null) return -1;
        const comparison = sort.key === 'display_city'
            ? String(a ?? '').localeCompare(String(b ?? ''), undefined, { sensitivity: 'base', numeric: true })
            : (a ?? 0) - (b ?? 0);
        return (sort.direction === 'asc' ? comparison : -comparison)
            || String(left.display_city).localeCompare(String(right.display_city), undefined, { sensitivity: 'base', numeric: true })
            || String(left.canonical_key).localeCompare(String(right.canonical_key));
    });
}

export function formatMetric(value, percent = false) {
    if (value == null || !Number.isFinite(Number(value))) return 'Unavailable';
    return percent ? `${Number(value).toFixed(1)}%` : Number(value).toLocaleString();
}

function csvCell(value) {
    let text = String(value ?? 'Unavailable');
    if (/^[\s\uFEFF]*[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replace(/"/g, '""')}"`;
}

export function buildLocationCsv(rows, { from, to }, marketName) {
    const header = ['Market', 'Location', 'Available (Active + Unlockable)', 'Active', 'Unlockable (published expired)',
        'All non-closed cached clients', 'Verified', 'Map status', 'Views', 'Profile-unique visits',
        'Contact rate (%)', 'Phone contacts', 'WhatsApp contacts', 'Viber contacts', 'Performance index', 'Performance band',
        'Engagement from', 'Engagement to', 'Inventory basis'];
    const body = rows.map((row) => [marketName, row.display_city, row.available_count, row.active_count,
        row.unlockable_count, row.client_count, row.verified_count, row.mapped ? 'Mapped' : 'Not mapped',
        row.engagement?.views, row.engagement?.profile_unique_visits, row.engagement?.contact_rate,
        row.channels?.phone, row.channels?.whatsapp, row.channels?.viber, row.performance?.index,
        row.performance?.band, from, to, 'Current CRM cache; cities with cached clients only']);
    return '\uFEFF' + [header, ...body].map((row) => row.map(csvCell).join(',')).join('\r\n');
}
