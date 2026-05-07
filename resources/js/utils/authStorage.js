const AUTH_TOKEN_KEY = 'crm_token';
const AUTH_USER_KEY = 'crm_user';
const SESSION_AUTH_KEY = 'crm_session_auth';
const SESSION_TOKEN_KEY = 'crm_session_token';
const AUTH_CHANGE_EVENT = 'crm-auth-changed';
const IMPERSONATION_KEY = 'crm_impersonation';

let lastToken = null;
let lastUserValue = null;
let lastImpersonationValue = null;
let lastSessionAuth = null;
let lastSnapshot = {
    token: null,
    user: null,
    impersonation: null,
};

function canUseBrowserStorage() {
    return typeof window !== 'undefined';
}

function parseStoredUser(value) {
    if (!value) {
        return null;
    }

    try {
        return JSON.parse(value);
    } catch {
        return null;
    }
}

function parseStoredImpersonation(value) {
    if (!value) {
        return null;
    }

    try {
        const parsed = JSON.parse(value);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }

        const user = parsed.user && typeof parsed.user === 'object' ? parsed.user : null;
        const impersonator = parsed.impersonator && typeof parsed.impersonator === 'object' ? parsed.impersonator : null;
        if (!user?.id || !impersonator?.id) {
            return null;
        }

        return {
            user,
            impersonator,
            started_at: parsed.started_at || null,
            redirect_to: parsed.redirect_to || '/',
        };
    } catch {
        return null;
    }
}

function emitAuthChange() {
    if (!canUseBrowserStorage()) {
        return;
    }

    window.dispatchEvent(new CustomEvent(AUTH_CHANGE_EVENT));
}

function fallbackSessionToken() {
    return `session-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
}

export function readAuthSnapshot() {
    if (!canUseBrowserStorage()) {
        return lastSnapshot;
    }

    const token = localStorage.getItem(AUTH_TOKEN_KEY);
    const userValue = localStorage.getItem(AUTH_USER_KEY);
    const impersonationValue = sessionStorage.getItem(IMPERSONATION_KEY);
    const sessionAuth = sessionStorage.getItem(SESSION_AUTH_KEY) === '1';

    if (
        token === lastToken
        && userValue === lastUserValue
        && impersonationValue === lastImpersonationValue
        && sessionAuth === lastSessionAuth
    ) {
        return lastSnapshot;
    }

    lastToken = token;
    lastUserValue = userValue;
    lastImpersonationValue = impersonationValue;
    lastSessionAuth = sessionAuth;
    const impersonation = parseStoredImpersonation(impersonationValue);
    const storedUser = parseStoredUser(userValue);
    const hasAuth = Boolean(token) || sessionAuth;
    lastSnapshot = {
        token,
        user: hasAuth ? (impersonation?.user || storedUser) : null,
        impersonation: hasAuth ? impersonation : null,
    };

    return lastSnapshot;
}

export function subscribeToAuthChanges(callback) {
    if (!canUseBrowserStorage()) {
        return () => {};
    }

    const handleChange = () => callback();

    window.addEventListener(AUTH_CHANGE_EVENT, handleChange);
    window.addEventListener('storage', handleChange);

    return () => {
        window.removeEventListener(AUTH_CHANGE_EVENT, handleChange);
        window.removeEventListener('storage', handleChange);
    };
}

export function storeAuthSnapshot(token, user) {
    if (!canUseBrowserStorage()) {
        return;
    }

    sessionStorage.removeItem(IMPERSONATION_KEY);
    if (token) {
        localStorage.setItem(AUTH_TOKEN_KEY, token);
        sessionStorage.removeItem(SESSION_AUTH_KEY);
    } else {
        localStorage.removeItem(AUTH_TOKEN_KEY);
        sessionStorage.setItem(SESSION_AUTH_KEY, '1');
    }
    localStorage.setItem(AUTH_USER_KEY, JSON.stringify(user));
    emitAuthChange();
}

export function updateStoredUser(user) {
    if (!canUseBrowserStorage()) {
        return;
    }

    const impersonation = readImpersonationSnapshot();
    if (impersonation) {
        sessionStorage.setItem(IMPERSONATION_KEY, JSON.stringify({
            ...impersonation,
            user,
        }));
        emitAuthChange();
        return;
    }

    localStorage.setItem(AUTH_USER_KEY, JSON.stringify(user));
    emitAuthChange();
}

export function clearAuthSnapshot({ clearSessionToken = false } = {}) {
    if (!canUseBrowserStorage()) {
        return;
    }

    localStorage.removeItem(AUTH_TOKEN_KEY);
    localStorage.removeItem(AUTH_USER_KEY);
    sessionStorage.removeItem(SESSION_AUTH_KEY);

    if (clearSessionToken) {
        sessionStorage.removeItem(SESSION_TOKEN_KEY);
    }

    emitAuthChange();
}

export function readImpersonationSnapshot() {
    if (!canUseBrowserStorage()) {
        return null;
    }

    return parseStoredImpersonation(sessionStorage.getItem(IMPERSONATION_KEY));
}

export function clearImpersonationSnapshot({ clearSessionToken = false } = {}) {
    if (!canUseBrowserStorage()) {
        return;
    }

    sessionStorage.removeItem(IMPERSONATION_KEY);

    if (clearSessionToken) {
        sessionStorage.removeItem(SESSION_TOKEN_KEY);
    }

    emitAuthChange();
}

export function readSessionToken() {
    if (!canUseBrowserStorage()) {
        return '';
    }

    return sessionStorage.getItem(SESSION_TOKEN_KEY) || '';
}

export function rotateSessionToken() {
    if (!canUseBrowserStorage()) {
        return '';
    }

    const nextToken = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : fallbackSessionToken();

    sessionStorage.setItem(SESSION_TOKEN_KEY, nextToken);

    return nextToken;
}

export function ensureSessionToken() {
    const existingToken = readSessionToken();
    if (existingToken) {
        return existingToken;
    }

    return rotateSessionToken();
}

export function clearSessionToken() {
    if (!canUseBrowserStorage()) {
        return;
    }

    sessionStorage.removeItem(SESSION_TOKEN_KEY);
}
