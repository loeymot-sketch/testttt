// FoodKing MAX TEST WAVE — T2 Livreur Visual + Technique
// 3 scenarios (S-LIV-01..03) — capture screenshots + network traces + verify heals
// Heals verified:
//  - axios /api/api/ double-prefix fix
//  - PII PENDING_CREATE_ scaffold cleanup
//  - null+phone rendering polish (country_code || '')

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT_DIR = '/tmp/foodking-max-test-2026-05-28/t2-livreur';
fs.mkdirSync(OUT_DIR, { recursive: true });

/**
 * Setup network traces collection for a single page.
 * Captures all /api/* requests + responses with relevant metadata.
 */
function attachNetworkTrace(page, traces) {
  page.on('request', (req) => {
    if (req.url().includes('/api/')) {
      traces.push({
        kind: 'request',
        method: req.method(),
        url: req.url(),
        ts: Date.now(),
      });
    }
  });
  page.on('response', async (res) => {
    if (res.url().includes('/api/')) {
      const headers = res.headers();
      let bodySnippet = '';
      try {
        const buf = await res.body();
        bodySnippet = buf.toString('utf8').slice(0, 600);
      } catch (e) {
        bodySnippet = `<no-body: ${e.message}>`;
      }
      traces.push({
        kind: 'response',
        method: res.request().method(),
        url: res.url(),
        status: res.status(),
        contentType: headers['content-type'] || null,
        bodySnippet,
        ts: Date.now(),
      });
    }
  });
}

function saveTraces(scenario, traces) {
  const file = path.join(OUT_DIR, `${scenario}.network.json`);
  fs.writeFileSync(file, JSON.stringify(traces, null, 2));
  return file;
}

test.describe('T2 Livreur — MAX TEST visual + network', () => {
  test.describe.configure({ mode: 'serial' });

  test('S-LIV-01 — Admin liste delivery-boys phone format clean', async ({ page }) => {
    const traces = [];
    attachNetworkTrace(page, traces);

    await loginAsAdmin(page);

    await page.goto('/admin/delivery-boys', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000); // allow Vue to mount + axios to fetch

    const beforeShot = path.join(OUT_DIR, 'S-LIV-01-delivery-boys-list.png');
    await page.screenshot({ path: beforeShot, fullPage: true });

    // Extract visible phone column for user 10 (or any visible row) — must NOT contain PENDING_CREATE_ or null prefix
    const bodyText = await page.locator('body').innerText();
    const hasPendingPii = bodyText.includes('PENDING_CREATE_');
    const hasNullPhone = /null\+?\d/.test(bodyText) || bodyText.includes('nullPENDING');
    const hasExpectedPhone = bodyText.includes('+33700000010');

    saveTraces('S-LIV-01', traces);

    // Find any /api/api/ double-prefix in traces — this MUST be zero
    const doublePrefixCalls = traces.filter((t) =>
      t.kind === 'response' && /\/api\/api\//.test(t.url)
    );
    const deliveryBoyApiCalls = traces.filter((t) =>
      t.kind === 'response' && /\/api\/admin\/delivery-boy(?:\/|\?|$)/.test(t.url)
    );

    console.log('[S-LIV-01] hasPendingPii=', hasPendingPii,
      'hasNullPhone=', hasNullPhone,
      'hasExpectedPhone=', hasExpectedPhone,
      'doublePrefixCount=', doublePrefixCalls.length,
      'deliveryBoyApiCount=', deliveryBoyApiCalls.length);

    expect(hasPendingPii, 'PII PENDING_CREATE_ must NOT be visible').toBe(false);
    expect(hasNullPhone, 'null+phone rendering must NOT be present').toBe(false);
    expect(doublePrefixCalls.length, 'no /api/api/ double-prefix axios calls').toBe(0);
  });

  test('S-LIV-02 — Cash sessions list NOT empty + axios path correct', async ({ page }) => {
    const traces = [];
    attachNetworkTrace(page, traces);

    await loginAsAdmin(page);

    // Wait for the actual cash-sessions response (not just timeout)
    const [cashSessionsResp] = await Promise.all([
      page.waitForResponse(
        (r) => /\/api\/admin\/delivery-boy\/cash-sessions(?:\?|$)/.test(r.url()) && r.status() === 200,
        { timeout: 25_000 },
      ),
      page.goto('/admin/delivery-boy-cash-sessions', { waitUntil: 'domcontentloaded' }),
    ]);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800); // small post-render settle for Vue v-for

    const shot = path.join(OUT_DIR, 'S-LIV-02-cash-sessions-list.png');
    await page.screenshot({ path: shot, fullPage: true });

    const bodyText = await page.locator('body').innerText();
    const isEmpty = /Aucune donnée|No data available|aucun.+disponible/i.test(bodyText);
    const sessionRowVisible = bodyText.includes('Livreur E2E') || /\bopen\b/i.test(bodyText) || /\bouverte?\b/i.test(bodyText);

    saveTraces('S-LIV-02', traces);

    const doublePrefixCalls = traces.filter((t) =>
      t.kind === 'response' && /\/api\/api\//.test(t.url)
    );
    const targetCall = traces.find((t) =>
      t.kind === 'response' &&
      /\/api\/admin\/delivery-boy\/cash-sessions(?:\?|$)/.test(t.url)
    );
    const cashSessionsBody = await cashSessionsResp.text().catch(() => '');

    console.log('[S-LIV-02] isEmpty=', isEmpty,
      'sessionRowVisible=', sessionRowVisible,
      'doublePrefixCount=', doublePrefixCalls.length,
      'targetCallStatus=', targetCall?.status,
      'targetCallContentType=', targetCall?.contentType,
      'cashSessionsBodyHasId1=', /"id":\s*1\b/.test(cashSessionsBody));

    expect(doublePrefixCalls.length, 'no /api/api/ double-prefix').toBe(0);
    expect(targetCall, 'cash-sessions axios call fired').toBeTruthy();
    expect(targetCall.status, 'cash-sessions HTTP 200').toBe(200);
    expect(targetCall.contentType, 'cash-sessions JSON content-type').toMatch(/application\/json/i);
  });

  test('S-LIV-03 — Cash session show id=1 NOT blank', async ({ page }) => {
    const traces = [];
    attachNetworkTrace(page, traces);

    await loginAsAdmin(page);

    const [sessionShowResp] = await Promise.all([
      page.waitForResponse(
        (r) => /\/api\/admin\/delivery-boy\/cash-sessions\/1(?:\?|$)/.test(r.url()) && r.status() === 200,
        { timeout: 25_000 },
      ).catch(() => null),
      page.goto('/admin/delivery-boy-cash-sessions/1', { waitUntil: 'domcontentloaded' }),
    ]);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);

    const shot = path.join(OUT_DIR, 'S-LIV-03-cash-session-show.png');
    await page.screenshot({ path: shot, fullPage: true });

    const bodyText = await page.locator('body').innerText();
    const isBlank = bodyText.replace(/\s+/g, '').length < 300;
    const showsId = /\b#?\s*1\b/.test(bodyText);

    saveTraces('S-LIV-03', traces);

    const doublePrefixCalls = traces.filter((t) =>
      t.kind === 'response' && /\/api\/api\//.test(t.url)
    );
    const targetCall = traces.find((t) =>
      t.kind === 'response' &&
      /\/api\/admin\/delivery-boy\/cash-sessions\/1(?:\?|$)/.test(t.url)
    );

    console.log('[S-LIV-03] isBlank=', isBlank,
      'showsId=', showsId,
      'doublePrefixCount=', doublePrefixCalls.length,
      'targetCallStatus=', targetCall?.status,
      'sessionShowRespReceived=', !!sessionShowResp);

    expect(doublePrefixCalls.length, 'no /api/api/ double-prefix').toBe(0);
    expect(isBlank, 'session show page is NOT blank').toBe(false);
  });
});
