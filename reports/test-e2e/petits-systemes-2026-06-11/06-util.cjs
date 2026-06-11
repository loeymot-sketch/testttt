/* Shared helpers for abuse wave #14 — append-to-disk discipline. */
const fs = require('fs');
const path = require('path');
const OUT = __dirname;
const RESULTS = path.join(OUT, 'ABUSE_RESULTS.md');

function log(step, verdict, detail) {
  const line = `| ${step} | ${verdict} | ${String(detail).replace(/\n/g, ' ').slice(0, 400)} |\n`;
  fs.appendFileSync(RESULTS, line);
  console.log('RESULT', step, verdict, detail);
}

function sinkSummary(sink) {
  const http = [...new Set(sink.http)].join('; ') || 'aucun HTTP>=400';
  const cons = [...new Set(sink.console)].slice(0, 3).join('; ') || 'console propre';
  return `${http} / ${cons}`;
}

async function snap(page, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: false });
}

/* Click last action button in a row, then confirm the FR delete dialog. */
async function deleteRow(page, rowText) {
  const row = page.locator('tbody tr', { hasText: rowText }).first();
  if (!(await row.count())) return 'row-not-found';
  await row.locator('button, a').last().click();
  await page.waitForTimeout(1200);
  const confirmBtn = page.getByRole('button', { name: /^(Oui|Confirmer|Supprimer|Yes|OK|Delete)/i }).last();
  if (await confirmBtn.count()) await confirmBtn.click().catch(() => {});
  await page.waitForTimeout(2500);
  const body = await page.evaluate(() => document.body.innerText);
  return body.includes(rowText) ? 'still-visible' : 'deleted';
}

const ready = (page) => page.waitForFunction(
  () => document.querySelectorAll('.db-card, table, .db-table').length > 0, null, { timeout: 20000 }
).catch(() => {});

module.exports = { OUT, log, snap, deleteRow, ready, sinkSummary };
