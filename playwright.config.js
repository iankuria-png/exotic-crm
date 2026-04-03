import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const ignoreHTTPSErrors = ['1', 'true', 'yes'].includes(String(process.env.PLAYWRIGHT_IGNORE_HTTPS_ERRORS || '').toLowerCase());
const allowRemoteHosts = ['1', 'true', 'yes'].includes(String(process.env.PLAYWRIGHT_ALLOW_REMOTE_HOSTS || '').toLowerCase());
const isCI = Boolean(process.env.CI);

function isLocalLikeBaseUrl(urlString) {
    try {
        const url = new URL(urlString);
        const hostname = (url.hostname || '').toLowerCase();

        return (
            hostname === 'localhost'
            || hostname === '127.0.0.1'
            || hostname === '::1'
            || hostname.endsWith('.localhost')
            || hostname.endsWith('.local')
            || hostname.endsWith('.test')
        );
    } catch (error) {
        throw new Error(`Invalid PLAYWRIGHT_BASE_URL: ${urlString}`);
    }
}

if (!allowRemoteHosts && !isLocalLikeBaseUrl(baseURL)) {
    throw new Error(
        `Refusing to run browser tests against non-local host "${baseURL}". Set PLAYWRIGHT_ALLOW_REMOTE_HOSTS=true only when you intentionally target a shared environment.`,
    );
}

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: true,
    forbidOnly: isCI,
    retries: isCI ? 2 : 0,
    timeout: 60_000,
    expect: {
        timeout: 10_000,
    },
    outputDir: 'tests/browser/artifacts/test-results',
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'tests/browser/artifacts/report' }],
    ],
    use: {
        baseURL,
        ignoreHTTPSErrors,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        viewport: { width: 1440, height: 960 },
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
