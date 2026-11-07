// Small UI helpers shared by the specs.
import { sleep } from './harness.mjs';

/** The Floor/search card (article) whose heading is `title`. */
export const beat = (page, title) => page.locator('article', { has: page.getByRole('heading', { name: title, exact: true }) }).first();

/** Opens the knock drawer from a card/button, fills the enquiry card and sends it. */
export async function knock(j, page, triggerLocator, card) {
  await triggerLocator.click();
  const dialog = page.getByRole('dialog').first();
  await dialog.waitFor();
  const fill = async (label, value) => { if (value) await dialog.getByLabel(label).fill(value); };
  if (card.what) await dialog.locator('#what').fill(card.what);
  await fill('When, or how many', card.when);
  await fill('Where', card.where);
  await fill('Budget', card.budget);
  await j.snap(page, 'knock-drawer-filled', { full: false });
  await dialog.locator('button[type=submit]').click();
  await j.settle(page, 500);
  return dialog;
}

export async function send(j, page, text) {
  await page.locator('#ln-body').fill(text);
  await page.getByRole('button', { name: 'Send', exact: true }).click();
  await j.settle(page, 400);
}

export { sleep };
