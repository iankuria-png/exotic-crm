import axios from 'axios';

// Interceptor-free client used ONLY to verify whether a 401 represents a real
// credential loss. It must never re-enter the global 401 handler in api.js,
// otherwise the first failed probe would itself trigger the logout flow it is
// meant to gate.
const authProbe = axios.create({
    baseURL: '/api',
    // Fail fast: this only gates the logout decision, so it must not hang.
    timeout: 15_000,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true,
});

// Mirror the bearer-token attachment from the main client, but nothing else.
authProbe.interceptors.request.use((config) => {
    const token = localStorage.getItem('crm_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

export default authProbe;
