export const FAMILIES = {
    login_campaign: 'Login campaigns', confirmed_payload: 'Confirmed database indicators', stored_script: 'Stored scripts',
    permissions: 'Role and permission risks', failed_attempt: 'Failed login attempts', activity_record: 'Activity records',
    access_review: 'Access requiring review', database_indicator: 'Other database indicators', persistence: 'Persistence',
    content: 'Content integrity', config: 'Configuration', seo: 'SEO and links', hygiene: 'Database hygiene', other: 'Other findings',
};
export function domainLabel(value) {
    try { return new URL(value).hostname; } catch { return value || 'Domain not configured'; }
}
export function activityDate(value, precision = 'time') {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Not recorded';
    if (precision === 'day') return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
    return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' });
}
export const FILTER_KEYS = ['status', 'page', 'group_by', 'family', 'workflow', 'severity', 'category', 'confidence', 'behavior', 'platform_id', 'q', 'rule_key', 'identity', 'definition_hash', 'run_id', 'kind', 'review_group'];
export function readFindingFilters(params) {
    const filters = { status: 'active', page: 1, group_by: 'related' };
    for (const key of FILTER_KEYS) if (params.has(key)) filters[key] = params.get(key);
    filters.page = Math.max(1, Number.parseInt(filters.page, 10) || 1);
    return filters;
}
