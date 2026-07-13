// Best-effort, self-throttling reporter that POSTs browser-side failures into
// the Error Logs subsystem. It must never throw and must never storm the API:
// identical signatures are suppressed for a window so a crash loop can't flood
// the store (the endpoint is also server-side throttled as a backstop).

import api from './api';
import { getAppBuild } from '../utils/diagnostics';

const WINDOW_MS = 60_000;
const recentlyReported = new Map(); // signature -> last-sent epoch ms

function prune(now) {
    for (const [key, ts] of recentlyReported) {
        if (now - ts > WINDOW_MS) {
            recentlyReported.delete(key);
        }
    }
}

export function reportClientError({ message, category, url, stack, component, requestId } = {}) {
    try {
        const now = Date.now();
        prune(now);

        const signature = `${category || ''}|${(message || '').slice(0, 120)}|${component || ''}`;
        const last = recentlyReported.get(signature);
        if (last && now - last < WINDOW_MS) {
            return;
        }
        recentlyReported.set(signature, now);

        // Fire-and-forget. Swallow every failure — the reporter is the last thing
        // that should surface an error to the user.
        api.post('/crm/client-errors', {
            message: (message || 'Unknown client error').slice(0, 1000),
            category: category || null,
            url: url || (typeof window !== 'undefined' ? window.location.href : null),
            stack: stack ? String(stack).slice(0, 8000) : null,
            component: component || null,
            app_build: getAppBuild(),
            request_id: requestId || null,
        }).catch(() => {});
    } catch {
        // never throw
    }
}
