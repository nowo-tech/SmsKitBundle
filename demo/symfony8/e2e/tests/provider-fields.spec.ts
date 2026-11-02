import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * SmsKit 1.3 — provider-dependent rows of the `sms` section (InstanceSettings `visible_when`, UiKit `visible-when`
 * peer): Twilio rows for `twilio`, SMS Bridge rows for `sms_bridge`, none for `log` / `null`. Values survive a switch.
 */
const row = (page: Page, name: string) => sectionForm(page).locator(`[data-nowo-ui-visible-when-field]:has([name^="settings_section[${name}]"])`);

/** axe on the section form, in the state being tested. */
async function expectNoAxeViolations(page: Page): Promise<void> {
  const axe = await new AxeBuilder({ page }).include('form[name="settings_section"]').analyze();
  expect(axe.violations).toEqual([]);
}

test.describe('SMS provider-dependent fields', () => {
  test('only the selected provider rows are shown and hidden values survive a save', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    const form = sectionForm(page);
    await expect(form.getByLabel('Provider')).toHaveValue('log');
    await expect(row(page, 'twilio_account_sid')).toBeHidden();
    await expect(row(page, 'bridge_base_url')).toBeHidden();
    await expect(form.getByLabel('Sender', { exact: true })).toBeVisible();
    await expectNoAxeViolations(page);

    await form.getByLabel('Provider').selectOption('sms_bridge');
    await expect(row(page, 'bridge_base_url')).toBeVisible();
    await expect(row(page, 'twilio_account_sid')).toBeHidden();
    await form.getByLabel('SMS Bridge URL', { exact: true }).fill('https://bridge.example.test');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expectNoAxeViolations(page);

    // Back to log, save, then show SMS Bridge again: the URL is still stored.
    await sectionForm(page).getByLabel('Provider').selectOption('log');
    await expect(row(page, 'bridge_base_url')).toBeHidden();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await sectionForm(page).getByLabel('Provider').selectOption('sms_bridge');
    await expect(sectionForm(page).getByLabel('SMS Bridge URL', { exact: true })).toHaveValue('https://bridge.example.test');
    await sectionForm(page).getByLabel('Provider').selectOption('log');
  });
});
