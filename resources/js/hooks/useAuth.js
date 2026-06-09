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

        api.get('/crm/me')
            .then(({ data }) => {
                if (cancelled) {
                    return;
                }

                updateStoredUser(data.user);
                ensureSessionToken();
            })
            .catch((error) => {
                if (cancelled) {
                    return;
                }

                // Only a confirmed credential rejection ends the session. Network
                // failures or 5xx must never wipe an otherwise-valid token.
                if (error.response?.status === 401) {
                    clearAuthSnapshot({ clearSessionToken: true });
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setIsLoading(false);
                }
            });

        return () => {
            cancelled = true;
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

    return { user, isLoading, login, logout, impersonation };
}
