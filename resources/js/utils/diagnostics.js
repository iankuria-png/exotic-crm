// Builds an operator-friendly diagnostics blob shared by the ErrorBoundary,
// NotFound, error toasts, and the Network Check page — so "Copy diagnostics"
// produces the same shape everywhere and support gets what it needs without a
// terminal.

import { classifyError, getRequestId } from '../services/errors';

const CORE_KEYS = ['url', 'userAgent', 'online', 'timestamp', 'requestId', 'category', 'appBuild'];

export function getAppBuild() {
    if (typeof document !== 'undefined') {
        const meta = document.querySelector('meta[name="app-build"]');
        if (meta?.content) {
            return meta.content;
        }
    }
    return (typeof window !== 'undefined' && window.__APP_BUILD__) || 'dev';
}

export function buildDiagnostics({ error, extra } = {}) {
    return {
        url: typeof window !== 'undefined' ? window.location.href : '',
        userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '',
        online: typeof navigator !== 'undefined' ? navigator.onLine : true,
        timestamp: new Date().toISOString(),
        requestId: error ? getRequestId(error) : null,
        category: error ? classifyError(error) : null,
        appBuild: getAppBuild(),
        ...(extra || {}),
    };
}

export function formatDiagnostics(diag) {
    const lines = [
        'ExoticCRM diagnostics',
        `Time: ${diag.timestamp}`,
        `Page: ${diag.url}`,
        `Build: ${diag.appBuild}`,
        `Online: ${diag.online ? 'yes' : 'no'}`,
        diag.category ? `Category: ${diag.category}` : null,
        diag.requestId ? `Request ID: ${diag.requestId}` : null,
        `Browser: ${diag.userAgent}`,
    ];

    // Append any caller-supplied extras (e.g. Network Check timings, boundary name).
    Object.entries(diag).forEach(([key, value]) => {
        if (CORE_KEYS.includes(key) || value === null || value === undefined) {
            return;
        }
        lines.push(`${key}: ${typeof value === 'object' ? JSON.stringify(value) : value}`);
    });

    return lines.filter(Boolean).join('\n');
}

export async function copyDiagnostics(diag) {
    const text = formatDiagnostics(diag);

    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {
        // fall through to the legacy path
    }

    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        return ok;
    } catch {
        return false;
    }
}
