import axios from 'axios';
import { clearAuthSnapshot } from '../utils/authStorage';
import { readImpersonationSnapshot } from '../utils/authStorage';
import authProbe from './authProbe';

const api = axios.create({
    baseURL: '/api',
    // A generous ceiling so a hung backend can't leave the UI spinning forever,
    // while still clearing normal slow calls (AI generation, reports, sync).
    // Multipart uploads are exempted below — they can legitimately run longer.
    timeout: 60_000,
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

    // Never time out large multipart uploads (50 MB media/video, KYC docs,
    // lesson media). This covers every current and future upload call site
    // without threading a per-request override through each one.
    const contentType = config.headers?.['Content-Type'] || config.headers?.['content-type'];
    if (typeof contentType === 'string' && contentType.includes('multipart/form-data')) {
        config.timeout = 0;
    }

    return config;
});

// A single 401 from any one request must NOT tear down the session — that turned
// every transient blip into a forced logout. Instead we confirm the credential is
// actually dead with one bypass probe (authProbe has no interceptor, so it cannot
// recurse into this handler), and only then clear auth and redirect. Concurrent
// 401s from background pollers collapse into a single in-flight confirmation.
let logoutConfirmationInFlight = false;

async function confirmCredentialLossThenLogout() {
    if (logoutConfirmationInFlight) {
        return;
    }
    logoutConfirmationInFlight = true;

    try {
        // If the token still works, the original 401 was endpoint-specific or
        // transient — leave the session untouched.
        await authProbe.get('/crm/me');
        logoutConfirmationInFlight = false;
    } catch (probeError) {
        if (probeError.response?.status === 401) {
            clearAuthSnapshot({ clearSessionToken: true });
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
            // Intentionally leave the flag set: we are navigating away.
            return;
        }
        // Probe failed for a non-auth reason (network/5xx). Do nothing and allow
        // a future request to re-trigger confirmation.
        logoutConfirmationInFlight = false;
    }
}

// Handle 401 responses
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            void confirmCredentialLossThenLogout();
        }
        return Promise.reject(error);
    }
);

export default api;
