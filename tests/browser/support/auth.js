import { randomUUID } from 'node:crypto';
import { expect } from '@playwright/test';
import { getRoleCredentials } from './env.js';

export async function loginViaApi(request, role) {
    const credentials = getRoleCredentials(role);
    if (!credentials) {
        throw new Error(`Missing credentials for role "${role}".`);
    }

    const response = await request.post('/api/crm/login', {
        data: credentials,
    });

    expect(response.ok(), `Login failed for ${role}.`).toBeTruthy();

    return response.json();
}

export async function seedAuthState(page, authPayload) {
    const sessionToken = randomUUID();

    await page.addInitScript(
        ({ token, user, nextSessionToken }) => {
            window.localStorage.setItem('crm_token', token);
            window.localStorage.setItem('crm_user', JSON.stringify(user));
            window.sessionStorage.setItem('crm_session_token', nextSessionToken);
        },
        {
            token: authPayload.token,
            user: authPayload.user,
            nextSessionToken: sessionToken,
        },
    );

    page.__crmAuthToken = authPayload.token;
    page.__crmSessionToken = sessionToken;

    return sessionToken;
}

export async function loginAndOpen(page, request, role, path) {
    const authPayload = await loginViaApi(request, role);
    await seedAuthState(page, authPayload);
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    return authPayload;
}

export async function cleanupAuthState(page, request) {
    const token = page.__crmAuthToken;

    if (!token) {
        return;
    }

    await request.post('/api/crm/logout', {
        headers: {
            Authorization: `Bearer ${token}`,
        },
        data: {
            session_token: page.__crmSessionToken || null,
        },
        failOnStatusCode: false,
    });

    page.__crmAuthToken = null;
    page.__crmSessionToken = null;
}
