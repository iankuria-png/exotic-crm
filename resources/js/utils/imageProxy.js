const PROXY_BASE = '/api/crm/image-proxy';

/**
 * Returns true for URLs that can/should be proxied.
 */
export function isProxiable(url) {
    if (!url || typeof url !== 'string') return false;
    const trimmed = url.trim();
    if (trimmed === '') return false;
    if (trimmed.startsWith('data:')) return false;
    if (trimmed.startsWith('blob:')) return false;
    if (trimmed.startsWith('/')) return false; // Already local
    return true;
}

/**
 * Wraps a remote image URL through the CRM image proxy.
 * Prevents Cloudflare hotlink protection from blocking cross-origin images.
 */
export function proxyImageUrl(url) {
    if (!isProxiable(url)) return url || '';
    return `${PROXY_BASE}?url=${encodeURIComponent(url.trim())}`;
}
