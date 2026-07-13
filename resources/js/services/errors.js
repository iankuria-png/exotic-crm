// Central classification + friendly copy for API/network failures. Used by the
// global TanStack Query bridge, the ErrorBoundary, toasts, and Network Check so
// every surface labels a failure the same way.

// Categories the global handler treats as "infrastructure" — the classes that
// components almost never handle locally, so a global toast for these can't
// collide with the ~329 hand-wired local toast.error() handlers.
export const INFRA_CATEGORIES = ['network_error', 'timeout', 'server_error'];

export function classifyError(error) {
    if (!error) {
        return 'unknown';
    }

    // Axios aborts (timeout) surface as ECONNABORTED / ETIMEDOUT.
    if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT' || /timeout/i.test(error.message || '')) {
        return 'timeout';
    }

    const status = error.response?.status;

    // No response object at all → the request never reached a server response
    // (offline, DNS, CORS, connection reset).
    if (status === undefined || status === null) {
        return 'network_error';
    }

    if (status === 401) return 'auth_error';
    if (status === 403) return 'permission_error';
    if (status === 404) return 'not_found';
    if (status === 419) return 'session_expired';
    if (status === 422) return 'validation_error';
    if (status === 429) return 'rate_limited';
    if (status >= 500) return 'server_error';
    if (status >= 400) return 'bad_request';

    return 'unknown';
}

// The X-Request-Id the server attached, so support can map a report to a log line.
export function getRequestId(error) {
    return (
        error?.response?.headers?.['x-request-id']
        || error?.response?.data?.request_id
        || null
    );
}

const FRIENDLY = {
    network_error: 'Can’t reach the server. Check your connection and try again.',
    timeout: 'The server took too long to respond. Please try again.',
    server_error: 'Something went wrong on our side. Please try again in a moment.',
    auth_error: 'Your session has expired. Please sign in again.',
    permission_error: 'You don’t have permission to do that.',
    not_found: 'We couldn’t find what you were looking for.',
    session_expired: 'Your session expired. Please refresh and sign in again.',
    rate_limited: 'You’re doing that a bit too fast. Please wait a moment and retry.',
    validation_error: 'Please check the highlighted fields and try again.',
    bad_request: 'That request couldn’t be completed.',
    unknown: 'Something went wrong. Please try again.',
};

const TITLES = {
    network_error: 'Connection problem',
    timeout: 'Connection problem',
    server_error: 'Server problem',
};

export function friendlyMessage(category, error) {
    // Prefer a real server-supplied message when it isn't Laravel's opaque default.
    const serverMessage = error?.response?.data?.message;
    if (serverMessage && serverMessage !== 'Server Error') {
        // For validation/domain errors the server copy is the most specific.
        if (!INFRA_CATEGORIES.includes(category)) {
            return serverMessage;
        }
        if (category === 'server_error') {
            return serverMessage;
        }
    }
    return FRIENDLY[category] || FRIENDLY.unknown;
}

export function errorTitle(category) {
    return TITLES[category] || 'Something went wrong';
}
