import axios from 'axios';
import { clearAuthSnapshot } from '../utils/authStorage';
import { readImpersonationSnapshot } from '../utils/authStorage';

const api = axios.create({
    baseURL: '/api',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true,
});

// Attach token from localStorage
api.interceptors.request.use((config) => {
    const token = localStorage.getItem('crm_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    const impersonation = readImpersonationSnapshot();
    if (impersonation?.user?.id) {
        config.headers['X-CRM-Impersonate-User'] = String(impersonation.user.id);
    }

    return config;
});

// Handle 401 responses
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            clearAuthSnapshot({ clearSessionToken: true });
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);

export default api;
