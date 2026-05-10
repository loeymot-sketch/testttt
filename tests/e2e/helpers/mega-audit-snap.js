// iter15 mega-audit shared helper — emit the 4-file artifact quartet.
// Each capture writes:
//   <state>.png         ← screenshot
//   <state>.dom.html    ← full DOM
//   <state>.console.json ← collected console + pageerror
//   <state>.network.json ← responses status>=400 OR duration>2000ms
//
// Reviewer agents (Wave E) consume all 4 files per state.

const fs = require('fs');
const path = require('path');

/**
 * Attach console + network listeners to a page. Returns the buffers + a snap()
 * function. Call snap(name) once per visual state. Call dispose() in afterEach
 * if you want a clean buffer per test.
 */
function attachMegaAuditRecorder(page, screenshotDir) {
  fs.mkdirSync(screenshotDir, { recursive: true });

  let consoleBuffer = [];
  let networkBuffer = [];

  const onConsole = (msg) => {
    try {
      consoleBuffer.push({
        level: msg.type(),
        text: msg.text().substring(0, 2000),
        location: msg.location(),
        ts: Date.now(),
      });
    } catch (_e) { /* ignore serialize errors */ }
  };
  const onPageError = (err) => {
    consoleBuffer.push({
      level: 'pageerror',
      text: String(err.message || err).substring(0, 2000),
      stack: String(err.stack || '').substring(0, 4000),
      ts: Date.now(),
    });
  };
  const onResponse = async (resp) => {
    try {
      const req = resp.request();
      const timing = req.timing();
      const duration = timing && timing.responseEnd >= 0 ? Math.round(timing.responseEnd) : null;
      const status = resp.status();
      if (status >= 400 || (duration !== null && duration > 2000)) {
        networkBuffer.push({
          url: resp.url().substring(0, 400),
          method: req.method(),
          status,
          duration_ms: duration,
          ts: Date.now(),
        });
      }
    } catch (_e) { /* ignore */ }
  };

  page.on('console', onConsole);
  page.on('pageerror', onPageError);
  page.on('response', onResponse);

  async function snap(name) {
    const base = path.join(screenshotDir, name);
    await page.screenshot({ path: `${base}.png`, fullPage: false });
    try {
      const html = await page.content();
      // [test-e2e fix B-004 round-1 2026-05-10] Raise DOM truncation cap from
      // 500KB to 2MB; large POS shells exceeded the lower limit causing late-DOM
      // elements (e.g. data-testid="pos-grand-total", cart lines) to fall past
      // the cut, defeating adversarial reviewer audit of the captured artifact.
      fs.writeFileSync(`${base}.dom.html`, html.substring(0, 2_000_000));
    } catch (_e) { /* ignore */ }
    fs.writeFileSync(`${base}.console.json`, JSON.stringify(consoleBuffer, null, 2));
    fs.writeFileSync(`${base}.network.json`, JSON.stringify(networkBuffer, null, 2));
    // Reset network/console to a "since last snap" model — keeps snaps decoupled.
    networkBuffer = [];
    consoleBuffer = [];
  }

  function dispose() {
    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
  }

  return { snap, dispose };
}

module.exports = { attachMegaAuditRecorder };
