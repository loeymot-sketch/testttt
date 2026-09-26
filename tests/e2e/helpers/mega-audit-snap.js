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
const crypto = require('crypto');

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

  /*
   * [AUDIT-SUPERVISEUR 2026-08-26 · AB-011] REGISTRE DES ETATS DEJA VUS.
   *
   * Ce que le superviseur a mesure sur la vague A : CINQ des dix « etats » captures etaient
   * des doublons au bit pres. `05-voir-tout-ligne-simple`, `06-carte-telephone` et
   * `07-carte-plateforme` partageaient une seule et meme empreinte ; `10-couloir-vide`
   * repetait `01-tableau`.
   *
   * La campagne annoncait donc dix etats de couverture et en avait sept. Pire : l'etat cense
   * demontrer la non-regression du correctif du panneau « Voir tout » n'avait JAMAIS ouvert
   * ce panneau — la page n'avait pas bouge entre trois appels successifs.
   *
   * Un audit qui se croit large parce qu'il a beaucoup de fichiers est un audit qui ment sur
   * sa propre couverture. On refuse desormais silencieusement de mentir : deux etats dont le
   * DOM est identique sont signales, nommement, dans la sortie de la campagne.
   *
   * On SIGNALE, on ne fait pas echouer : la capture doit produire ses artefacts meme quand
   * la scene n'a pas bouge — c'est au superviseur de juger, pas a l'enregistreur. Mais le
   * doublon ne peut plus passer inapercu.
   */
  const empreintesVues = new Map();

  async function snap(name) {
    const base = path.join(screenshotDir, name);
    await page.screenshot({ path: `${base}.png`, fullPage: false });
    try {
      const html = await page.content();
      // [test-e2e fix B-004 round-1 2026-05-10] Raise DOM truncation cap from
      // 500KB to 2MB; large POS shells exceeded the lower limit causing late-DOM
      // elements (e.g. data-testid="pos-grand-total", cart lines) to fall past
      // the cut, defeating adversarial reviewer audit of the captured artifact.
      const tronque = html.substring(0, 2_000_000);
      fs.writeFileSync(`${base}.dom.html`, tronque);

      // [AB-011] Le DOM de cet etat est-il celui d'un etat deja capture ?
      const empreinte = crypto.createHash('md5').update(tronque).digest('hex');
      if (empreintesVues.has(empreinte)) {
        const jumeau = empreintesVues.get(empreinte);
        // eslint-disable-next-line no-console
        console.log(
          `[COUVERTURE] « ${name} » a EXACTEMENT le meme DOM que « ${jumeau} ». `
          + 'Ces deux etats n\'en font qu\'un : la scene n\'a pas bouge entre les deux appels. '
          + 'Tout constat tire de l\'un vaut pour l\'autre, et un correctif cense etre demontre '
          + 'ici ne l\'est PAS.'
        );
      } else {
        empreintesVues.set(empreinte, name);
      }
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
