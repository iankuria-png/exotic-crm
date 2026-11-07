import { test, expect } from '@playwright/test';
import { setup, addSecond } from './support/collection-matching-fixture';
const group = page => page.getByRole('group', { name: 'PROFILE 1', exact: true });
const activate = page => page.getByRole('button', { name: 'Activate subscription', exact: true });

test('short receipt progressively offers exact payable discount and direct activation', async ({ page }) => {
    const state = await setup(page, { principal: 3000000 });
    const row = group(page);
    await expect(row.getByText(/TZS 5,000 above/)).toBeVisible();
    await expect(row.getByLabel('Discount PIN', { exact: true })).toHaveCount(0);
    await row.getByLabel('Apply Discount').check();
    await expect(row.getByLabel('Final payable amount')).toHaveValue('30000');
    await expect(row.getByLabel('Discount %', { exact: true })).toHaveValue('14.29');
    await expect(row.getByText(/Market limit 40%/)).toBeVisible();
    await expect(activate(page)).toBeDisabled();
    await row.getByLabel('Discount PIN', { exact: true }).fill('123456');
    await activate(page).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    const request = state.mutations.find(m => m.path.endsWith('/activate')).body;
    expect(request.items[0].discount.payable_amount).toBe('30000');
    expect(request.items[0].discount.percentage).toBe('14.29');
    expect(state.mutations.filter(m => m.path.endsWith('/save'))).toHaveLength(0);
});

test('server PIN error preserves negotiated price and lets staff correct it', async ({ page }) => {
    await setup(page, { principal: 3000000 });
    const row = group(page);
    await row.getByLabel('Apply Discount').check();
    await row.getByLabel('Discount PIN', { exact: true }).fill('000000');
    await activate(page).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Discount PIN is invalid.' })).toBeVisible();
    await expect(row.getByLabel('Final payable amount')).toHaveValue('30000');
    await row.getByLabel('Discount PIN', { exact: true }).fill('123456');
    await activate(page).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
});

test('market cap is visible and blocks approval without changing entered amount', async ({ page }) => {
    await setup(page, { principal: 3000000, discountCap: 10 });
    await group(page).getByLabel('Apply Discount').check();
    await group(page).getByLabel('Discount PIN', { exact: true }).fill('123456');
    await expect(group(page).getByRole('alert')).toContainText('market maximum of 10%');
    await expect(activate(page)).toBeDisabled();
    await expect(group(page).getByLabel('Final payable amount')).toHaveValue('30000');
});

test('catalogue reselection clears PIN and explicitly replaces saved price', async ({ page }) => {
    const state = await setup(page, { principal: 3000000 });
    const row = group(page);
    await row.getByLabel('Apply Discount').check();
    await row.getByLabel('Discount PIN', { exact: true }).fill('123456');
    await row.getByRole('combobox', { name: 'Purchased service' }).selectOption('catalogue:8');
    await expect(row.getByLabel('Discount PIN', { exact: true })).toHaveCount(0);
    await expect(activate(page)).toBeDisabled();
    await row.getByLabel('Apply Discount').check();
    await expect(row.getByLabel('Discount PIN', { exact: true })).toHaveValue('');
    await expect(row.getByText(/replaces the previously saved price/)).toBeVisible();
    await row.getByLabel('Discount PIN', { exact: true }).fill('123456');
    await activate(page).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    expect(state.mutations.find(m => m.path.endsWith('/activate')).body.items[0].replace_saved_price).toBe(true);
});

test('shared payment with zero available budget suggests no zero or negative payable', async ({ page }) => {
    await setup(page, { shared: true, principal: 3500000 });
    await page.getByRole('button', { name: 'Share payment with another profile' }).click();
    await addSecond(page);
    const second = page.getByRole('group', { name: 'PROFILE 2', exact: true });
    await second.getByLabel('Apply Discount').check();
    await expect(second.getByLabel('Final payable amount')).toHaveValue('');
    await expect(second.getByText(/Other profiles already use/)).toBeVisible();
    await second.getByLabel('Final payable amount').fill('30000');
    await group(page).getByLabel('Apply Discount').check();
    await group(page).getByLabel('Final payable amount').fill('5000');
    await expect(second.getByLabel('Final payable amount')).toHaveValue('30000');
});

test('draft retains negotiated amount and clears approval PIN', async ({ page }) => {
    await setup(page, { principal: 3000000 });
    const row = group(page);
    await row.getByLabel('Apply Discount').check();
    await row.getByLabel('Discount PIN', { exact: true }).fill('123456');
    await page.getByRole('button', { name: 'Save draft for later' }).click();
    await expect(row.getByLabel('Discount PIN', { exact: true })).toHaveValue('');
    await expect(row.getByLabel('Final payable amount')).toHaveValue('30000');
    await expect(activate(page)).toBeDisabled();
});

test('excess received payment remains a Finance decision without a discount prompt', async ({ page }) => {
    await setup(page, { principal: 4000000 });
    await expect(group(page).getByLabel('Apply Discount')).toHaveCount(0);
    await expect(activate(page)).toBeDisabled();
});


test('mobile discount controls remain readable and keyboard accessible', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page, { principal: 3000000 });
    const row = group(page);
    await row.getByLabel('Apply Discount').focus();
    await page.keyboard.press('Space');
    await row.getByLabel('Discount PIN', { exact: true }).fill('123456');
    await expect(activate(page)).toBeEnabled();
    const box = await row.boundingBox();
    expect(box.x + box.width).toBeLessThanOrEqual(391);
    const inputs = row.locator('input[type=number], input[type=password]');
    for (const input of await inputs.all()) { const b = await input.boundingBox(); expect(b.x).toBeGreaterThanOrEqual(0); expect(b.x + b.width).toBeLessThanOrEqual(391); }
    await page.screenshot({ path: '../discount-mobile.png', fullPage: true });
});
