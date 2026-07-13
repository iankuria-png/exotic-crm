import axios from 'axios';

// Session-authenticated client for non-/api web routes (e.g. the Google OAuth
// token exchange). It deliberately has NO auth-redirect interceptor and is NOT
// pinned to the /api prefix, so it can reach web-middleware routes that rely on
// the first-party session cookie rather than a bearer token.
const webClient = axios.create({
    baseURL: '/',
    timeout: 30_000,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true,
});

export default webClient;
