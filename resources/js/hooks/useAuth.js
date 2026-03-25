import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import api from '../services/api';
import {
    clearAuthSnapshot,
    ensureSessionToken,
    readAuthSnapshot,
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
    const [isLoading, setIsLoading] = useState(() => Boolean(token && !user));

    useEffect(() => {
        let cancelled = false;

        if (token && !user) {
            api.get('/crm/me')
                .then(({ data }) => {
                    if (cancelled) {
                        return;
                    }

                    updateStoredUser(data.user);
                    ensureSessionToken();
                })
                .catch(() => {
                    if (cancelled) {
                        return;
                    }

                    clearAuthSnapshot({ clearSessionToken: true });
                })
                .finally(() => {
                    if (!cancelled) {
                        setIsLoading(false);
                    }
                });
        } else {
            setIsLoading(false);
        }

        return () => {
            cancelled = true;
        };
    }, [token, user]);

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
        const sessionToken = readSessionToken();

        try {
            await api.post('/crm/logout', sessionToken ? { session_token: sessionToken } : {});
        } finally {
            clearAuthSnapshot({ clearSessionToken: true });
            setIsLoading(false);
        }
    }, []);

    return { user, isLoading, login, logout };
}
