import { useState, useEffect, useCallback } from 'react';
import api from '../services/api';

export function useAuth() {
    const [user, setUser] = useState(() => {
        const saved = localStorage.getItem('crm_user');
        return saved ? JSON.parse(saved) : null;
    });
    const [isLoading, setIsLoading] = useState(!user);

    useEffect(() => {
        const token = localStorage.getItem('crm_token');
        if (token && !user) {
            api.get('/crm/me')
                .then(({ data }) => {
                    setUser(data.user);
                    localStorage.setItem('crm_user', JSON.stringify(data.user));
                })
                .catch(() => {
                    localStorage.removeItem('crm_token');
                    localStorage.removeItem('crm_user');
                    setUser(null);
                })
                .finally(() => setIsLoading(false));
        } else {
            setIsLoading(false);
        }
    }, []);

    const login = useCallback(async (email, password) => {
        const { data } = await api.post('/crm/login', { email, password });
        localStorage.setItem('crm_token', data.token);
        localStorage.setItem('crm_user', JSON.stringify(data.user));
        setUser(data.user);
        return data;
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post('/crm/logout');
        } finally {
            localStorage.removeItem('crm_token');
            localStorage.removeItem('crm_user');
            setUser(null);
        }
    }, []);

    return { user, isLoading, login, logout };
}
