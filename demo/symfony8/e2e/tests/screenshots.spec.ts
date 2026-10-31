import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { sectionForm, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshot (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('SMS kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1100 } });

  test('sms-settings — sms section on the log provider after "Send a test SMS"', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    await page.getByRole('button', { name: 'Send a test SMS' }).click();
    await expect(page.getByText('Test SMS sent to +15*******23 via log (status: logged).')).toBeVisible();
    await expect(sectionForm(page)).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'sms-settings.png'), fullPage: true });
  });
});
