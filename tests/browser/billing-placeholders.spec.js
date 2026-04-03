import { test } from '@playwright/test';
import { getOptionalFixture } from './support/env.js';

test.describe('billing browser placeholders', () => {
    test('sub_admin forbidden state when market scope blocks billing access', async () => {
        test.fixme(true, 'Needs a seeded out-of-scope sub_admin fixture once Billing workspace permissions are decomposed.');
    });

    test('degraded payment diagnostics drawer state', async () => {
        test.fixme(
            true,
            `Needs a stable diagnostics entry fixture or seeded payment id. Optional fixture env: PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS=${getOptionalFixture('PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS') || '<unset>'}.`,
        );
    });

    test('billing diagnostics admin health surface', async () => {
        test.fixme(true, 'Awaiting BILL-705/BILL-706 implementation of Settings > Billing > Diagnostics.');
    });

    test('wallet renewal fallback visibility for operators', async () => {
        test.fixme(
            true,
            `Needs renewal fallback runtime data or a seeded client fixture. Optional fixture env: PLAYWRIGHT_CLIENT_ID_FOR_WALLET=${getOptionalFixture('PLAYWRIGHT_CLIENT_ID_FOR_WALLET') || '<unset>'}.`,
        );
    });
});
