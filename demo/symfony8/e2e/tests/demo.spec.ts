import { test, expect } from '@playwright/test';
import { sectionForm } from './helpers';

/** REQ-DEMO-013 — happy path of the SMS kit demo (log provider, nothing is sent). Data: `make seed`. */
test.describe('SMS kit demo', () => {
  test('home page shows the active provider', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('sms-provider')).toHaveText('log (configured)');
    await expect(page.getByRole('link', { name: 'Open the SMS settings' })).toBeVisible();
  });

  test('a message is sent through the log provider with the number masked', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    await expect(page.getByTestId('sms-result')).toContainText('Sent via log to +15*******23');
    await expect(page.getByTestId('sms-result')).toContainText('status logged');
  });

  test('an invalid number is refused', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Destination (E.164)').fill('555-0100');
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    await expect(page.getByTestId('sms-error')).toContainText('E.164');
  });

  test('the sms section offers the providers and a test button', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    const form = sectionForm(page);
    await expect(form.getByLabel('Provider')).toBeVisible();
    for (const option of ['Disabled', 'Log only (development)', 'Twilio', 'SMS Bridge']) {
      await expect(form.getByLabel('Provider').locator('option', { hasText: option })).toHaveCount(1);
    }
    await expect(page.getByRole('button', { name: 'Send a test SMS' })).toBeVisible();
  });

  test('send a test SMS to the saved test recipient', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    await page.getByRole('button', { name: 'Send a test SMS' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/sections\/sms$/);
    await expect(page.getByText('Test SMS sent to +15*******23 via log (status: logged).')).toBeVisible();
  });

  test('a stored token is never rendered back', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    await sectionForm(page).getByRole('group', { name: 'Twilio Auth Token' }).getByLabel('New value').fill('REDACTED-DEMO-TOKEN');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    expect(await page.content()).not.toContain('REDACTED-DEMO-TOKEN');
  });

  test('a forged CSRF token is refused', async ({ request }) => {
    const response = await request.post('/admin/settings/sms/test', { form: { 'csrf_only[_token]': 'forged' } });
    expect(response.status()).toBe(403);
  });
});
