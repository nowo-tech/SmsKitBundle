import { type Page } from '@playwright/test';

/** Hides the Symfony Web Debug Toolbar so it never appears in a screenshot (the shell frames every page). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
}

/** The section form of the settings scaffold. */
export function sectionForm(page: Page) {
  return page.locator('form[name="settings_section"]');
}
