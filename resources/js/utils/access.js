export function homePath(user) {
    return typeof user?.home_path === 'string' && user.home_path.startsWith('/') && !user.home_path.startsWith('//')
        ? user.home_path : '/faq';
}

function within(path, prefix) {
    return path === prefix || path.startsWith(`${prefix}/`);
}

export function canVisit(user, destination) {
    if (!user?.capabilities || !user.access_policy_version || user.status !== 'active') return false;
    const path = String(destination || '/').split(/[?#]/)[0];
    if (path === '/') return Boolean(user.capabilities.admin_dashboard || user.capabilities.sales_dashboard);
    if (within(path, '/payments')) return Boolean(user.capabilities.payments_workspace);
    if (user.role === 'marketing') {
        return ['/clients', '/kyc', '/push-campaigns', '/banner-ads', '/auto-push', '/team', '/faq', '/network-check', '/university']
            .some((prefix) => within(path, prefix));
    }
    if (user.role === 'field_sales') {
        return ['/field', '/clients', '/deals', '/leads', '/conversations', '/campaigns', '/banner-ads', '/kyc', '/reports', '/faq', '/network-check', '/university']
            .some((prefix) => within(path, prefix));
    }
    if (user.role === 'sales') return !within(path, '/push-campaigns');
    if (['admin', 'sub_admin'].includes(user.role)) return true;
    return ['/faq', '/network-check', '/university'].some((prefix) => within(path, prefix));
}
