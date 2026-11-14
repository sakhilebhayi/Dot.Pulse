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

/**
 * The Floor draws a beat as a 56px row and shows the selected one in the pane beside the rows (R-51). These helpers read
 * it the way a person does: pick the row, read the pane. Needs a viewport of 1200px or more (the harness default is 1440).
 */
export const floorRows = (page) => page.locator('[data-row]');

/** Selects the row at `index` (or whose title is `title`) and waits for the pane to show that beat. Returns its signal id. */
export async function selectRow(page, which) {
  const row = typeof which === 'number' ? floorRows(page).nth(which) : floorRows(page).filter({ has: page.getByText(which, { exact: true }) }).first();
  const id = await row.getAttribute('data-beat');
  await row.locator('.fl-row__open').click();
  await page.locator(`[data-pane-beat="${id}"]`).waitFor();
  return id;
}

/** Every beat in the round with the reasons its pane draws: [{ id, title, kind, reasons }]. */
export async function floorBeats(page) {
  const out = [];
  const n = await floorRows(page).count();
  for (let i = 0; i < n; i++) {
    const id = await selectRow(page, i);
    out.push(await page.evaluate((sid) => {
      const pane = document.querySelector(`[data-pane-beat="${sid}"]`);
      return {
        id: sid,
        title: pane.querySelector('.fl-pane__title')?.textContent.trim(),
        kind: pane.querySelector('.pk-kind')?.textContent.trim(),
        reasons: [...pane.querySelectorAll('.pk-meter__reason > span:not(.pk-meter__tick)')].map((s) => s.textContent.trim()),
      };
    }, id));
  }
  return out;
}
