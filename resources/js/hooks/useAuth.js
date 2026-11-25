import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import api from '../services/api';
import {
    clearAuthSnapshot,
    clearImpersonationSnapshot,
    ensureSessionToken,
    readAuthSnapshot,
    readImpersonationSnapshot,
    readSessionToken,
    rotateSessionToken,
    storeAuthSnapshot,
    subscribeToAuthChanges,
    updateStoredUser,
} from '../utils/authStorage';

let validationInFlight = null;
function validateCurrentAccess(token) {
    if (validationInFlight?.token === token) return validationInFlight.promise;
    const promise = api.get('/crm/me').finally(() => {
        if (validationInFlight?.promise === promise) validationInFlight = null;
    });
    validationInFlight = { token, promise };
    return promise;
}

export function useAuth() {
    const auth = useSyncExternalStore(
        subscribeToAuthChanges,
        readAuthSnapshot,
        () => ({ token: null, user: null }),
    );
    const user = auth.user;
    const token = auth.token;
    const impersonation = auth.impersonation || null;
    const [isLoading, setIsLoading] = useState(true);
    const [accessError, setAccessError] = useState(false);

    useEffect(() => {
        let cancelled = false;

        // Token-first: with no credential there is nothing to validate. Skipping
        // the guaranteed-401 round-trip avoids the logout churn it would trigger.
        if (!token && !readImpersonationSnapshot()) {
            setIsLoading(false);
            return () => {
                cancelled = true;
            };
        }

        // Only the first check and an explicit "Try again" may block the screen.
        // Background re-checks (tab focus, timer, a 403 from any request) must
        // not swap the workspace for a spinner or error: that unmounts every
        // page and discards unsaved edits. A 401 still ends the session, and a
        // changed access policy still resets the workspace in AccessSession.
        const refresh = (blocking) => {
            if (blocking) {
                setIsLoading(true);
                setAccessError(false);
            }
            validateCurrentAccess(token)
                .then(({ data }) => {
                    if (cancelled) return;
                    setAccessError(false);
                    updateStoredUser(data.user);
                    ensureSessionToken();
                })
                .catch((error) => {
                    if (cancelled) return;
                    if (error.response?.status === 401) {
                        clearAuthSnapshot({ clearSessionToken: true });
                    } else if (blocking) {
                        setAccessError(true);
                    }
                })
                .finally(() => { if (blocking && !cancelled) setIsLoading(false); });
        };
        const backgroundRefresh = () => refresh(false);
        const requestedRefresh = (event) => refresh(event?.detail?.blocking === true);
        refresh(true);
        const interval = window.setInterval(backgroundRefresh, 60_000);
        window.addEventListener('focus', backgroundRefresh);
        window.addEventListener('crm-access-refresh', requestedRefresh);
        return () => {
            cancelled = true;
            window.clearInterval(interval);
            window.removeEventListener('focus', backgroundRefresh);
            window.removeEventListener('crm-access-refresh', requestedRefresh);
        };
    }, [token]);

    useEffect(() => {
        if (token && user) {
            ensureSessionToken();
        }
    }, [token, user]);

    const login = useCallback(async (email, password) => {
        const { data } = await api.post('/crm/login', { email, password });
        rotateSessionToken();
        storeAuthSnapshot(data.token, data.user);
        setIsLoading(false);
        return data;
    }, []);

    const logout = useCallback(async () => {
        if (impersonation) {
            clearImpersonationSnapshot({ clearSessionToken: true });
            setIsLoading(false);
            return;
        }

        const sessionToken = readSessionToken();

        try {
            await api.post('/crm/logout', sessionToken ? { session_token: sessionToken } : {});
        } finally {
            clearAuthSnapshot({ clearSessionToken: true });
            setIsLoading(false);
        }
    }, [impersonation]);

    return { user, isLoading, accessError, login, logout, impersonation };
}
