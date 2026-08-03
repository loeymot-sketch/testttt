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

  // [Wave M1 round-3 A-014 console noise] Filter out the browser-native
  // "WebSocket connection to ws://...:6001 failed" warning emitted by Pusher's
  // transport when no Reverb/Soketi broadcast server is running in the test
  // env. The product DOES degrade gracefully (Echo retries, app polls as
  // fallback) — the warning is informational and was polluting every console
  // capture, drowning out real signals. Same filter for "Pusher : ..." retry
  // chatter from pusher-js' own logger.
  const NOISE_TEXT_PATTERNS = [
    /WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i,
    /^Pusher\s*:\s*/i,
  ];
  function _isKnownNoise(text) {
    const t = String(text || '');
    return NOISE_TEXT_PATTERNS.some((rx) => rx.test(t));
  }

  const onConsole = (msg) => {
    try {
      const text = msg.text();
      if (_isKnownNoise(text)) return; // drop known benign chatter
      consoleBuffer.push({
        level: msg.type(),
        text: text.substring(0, 2000),
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
  // [Wave M1 round-3 A-018 network capture]
  // Before: recorder kept responses ONLY when status>=400 OR duration>2000ms.
  // Successful 2xx mutations (POST/PUT/PATCH/DELETE for our admin toggles)
  // were dropped, leaving 16/17 network.json files empty in round-2 — the
  // adversarial reviewer had no way to verify "absence of 429" because there
  // was no positive evidence of the toggle POSTs landing at all.
  // Widen to also keep all non-GET requests regardless of status, so the
  // audit trail records mutations end-to-end. Stays sub-MB even on a heavy
  // 17-state spec because we only capture metadata, not bodies.
  const MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
  const onResponse = async (resp) => {
    try {
      const req = resp.request();
      const timing = req.timing();
      const duration = timing && timing.responseEnd >= 0 ? Math.round(timing.responseEnd) : null;
      const status = resp.status();
      const method = String(req.method() || 'GET').toUpperCase();
      const isMutation = MUTATION_METHODS.has(method);
      if (status >= 400 || (duration !== null && duration > 2000) || isMutation) {
        networkBuffer.push({
          url: resp.url().substring(0, 400),
          method,
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
