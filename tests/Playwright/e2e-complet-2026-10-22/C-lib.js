// C-lib — outillage commun des specs de la vague C (application des stores) — e2e-complet-2026-10-22.
// Ce fichier n'est PAS une spec (pas de *.spec.js) : il ne s'exécute pas seul.
//
// INSTRUMENT — ce qui est corrigé PAR RAPPORT à tests/Playwright/verif-globale-2026-10-21/M10-app-capacitor.spec.js
// ------------------------------------------------------------------------------------------------------------
// Le M10 d'origine répondait IMMÉDIATEMENT à `addListener` (success:true, data:{}). Or le vrai pont
// (native-bridge.js) enregistre le rappel, et le greffon natif (Plugin.addListener : setKeepAlive(true) +
// saveCall) NE RÉPOND PAS à l'enregistrement : l'écouteur ne se déclenche que sur un VRAI événement.
// Résultat dans l'ancien banc : le rappel `backButton` s'exécutait une fois au démarrage avec `{}`, sans
// modale ni historique, donc appelait App.exitApp — un appel natif FABRIQUÉ par le banc (constaté en
// rejouant M10 : « App.exitApp » en 9e position des appels de démarrage sur un arbre sain).
// Ici : `addListener` reste muet ; `window.__pont.emettre(plugin, evenement, donnees)` déclenche l'événement
// à la demande (bouton retour, perte de réseau) comme le ferait la coque.
//
// Le vrai `native-bridge.js` est injecté TEL QUEL (pas de registerPlugin : il vient de @capacitor/core,
// absent du site). La clé API de production du miroir est réécrite en MIX_API_KEY via page.route.

const fs = require('fs');
const path = require('path');

const API = process.env.C_API_URL || 'http://127.0.0.1:8766';
const SITE_REPO = process.env.C_SITE_REPO
  || '/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne';
const BRIDGE = path.join(SITE_REPO, 'app/node_modules/@capacitor/android/capacitor/src/main/assets/native-bridge.js');
const BRIDGE_IOS = path.join(SITE_REPO, 'app/node_modules/@capacitor/ios/Capacitor/Capacitor/assets/native-bridge.js');
const SCRATCH = process.env.C_SCRATCH
  || '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/15fca3a9-ef91-4f69-8ff0-680ff8c89dfd/scratchpad';
const SHOTS_ROOT = path.resolve(__dirname, '../../e2e/__screenshots__/e2e-complet-C');

const UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.6723.86 Mobile Safari/537.36';
const UA_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';

function cleApiLocale() {
  if (process.env.C_API_KEY) return process.env.C_API_KEY;
  try {
    const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
    const m = env.match(/^MIX_API_KEY=(.*)$/m);
    return m ? m[1].trim().replace(/^"|"$/g, '') : '';
  } catch (_e) { return ''; }
}

async function injecterCleApi(page) {
  const cle = cleApiLocale();
  if (!cle) throw new Error('MIX_API_KEY illisible — l\'instrument ne peut pas parler au backend local');
  await page.route((u) => u.origin === API && u.pathname.startsWith('/api/'), async (route) => {
    const req = route.request();
    if (req.method() === 'OPTIONS') return route.continue();
    const h = { ...req.headers() };
    for (const k of Object.keys(h)) if (k.toLowerCase() === 'x-api-key') delete h[k];
    h['x-api-key'] = cle;
    return route.continue({ headers: h });
  });
}

function scriptCoque(plateforme, avecRegisterPlugin) {
  const bridge = fs.readFileSync(BRIDGE, 'utf8'); // identique iOS/Android (vérifié par sha dans les constats)
  const transport = plateforme === 'android'
    ? `window.androidBridge = { postMessage: function (json) { window.__pont.recevoir(JSON.parse(json), function (rep) { window.androidBridge.onmessage({ data: JSON.stringify(rep) }); }); } };`
    : `window.webkit = { messageHandlers: { bridge: { postMessage: function (data) { window.__pont.recevoir(data, function (rep) { window.Capacitor.fromNative(rep); }); } } } };`;
  return `
    window.__pont = { appels: [], ecouteurs: [], rappels: {}, plateforme: '${plateforme}' };
    window.__pont.fauxResultat = function (d) {
      var p = d.pluginId, m = d.methodName;
      if (p === 'SocialLogin' && m === 'login') return { provider: 'apple', result: { idToken: 'eyJhbGciOiJSUzI1NiJ9.eyJmYWtlIjoidHJ1ZSIsInN1YiI6InZnLW0xMCJ9.c2lnbmF0dXJlLWZhY3RpY2UtdmctbTEw', profile: { email: 'e2e-app@example.invalid', givenName: 'E2E-APP', familyName: 'Fictif' } } };
      if (p === 'Network' && m === 'getStatus') return { connected: window.__pont.reseau !== false, connectionType: window.__pont.reseau === false ? 'none' : 'wifi' };
      if (p === 'LocalNotifications' && (m === 'requestPermissions' || m === 'checkPermissions')) return { display: 'granted' };
      if (p === 'LocalNotifications' && m === 'getPending') return { notifications: [] };
      if (p === 'App' && m === 'getLaunchUrl') return { url: null };
      if (p === 'App' && m === 'getInfo') return { name: 'Le Cayenne', id: 'fr.lecayenne.app', build: '1', version: '1.0.0' };
      return {};
    };
    window.__pont.recevoir = function (d, repondre) {
      window.__pont.appels.push({ plugin: d.pluginId, methode: d.methodName, options: d.options || {}, t: Date.now() });
      if (d.methodName === 'addListener') {
        var ev = (d.options || {}).eventName || '?';
        window.__pont.ecouteurs.push(d.pluginId + ':' + ev);
        // addListener RESTE MUET (Plugin.addListener : setKeepAlive + saveCall). Le rappel est retenu pour emettre().
        window.__pont.rappels[d.pluginId + ':' + ev] = { callbackId: d.callbackId, repondre: repondre };
        return;
      }
      var rep = { callbackId: d.callbackId, pluginId: d.pluginId, methodName: d.methodName, success: true, data: window.__pont.fauxResultat(d) };
      setTimeout(function () { try { repondre(rep); } catch (e) { console.error('[pont] réponse impossible', e); } }, 0);
    };
    // Déclenche un événement natif (bouton retour, réseau…) comme le ferait la coque : réponse « conservée ».
    window.__pont.emettre = function (plugin, evenement, donnees) {
      var r = window.__pont.rappels[plugin + ':' + evenement];
      if (!r) return false;
      r.repondre({ callbackId: r.callbackId, pluginId: plugin, methodName: 'addListener', success: true, data: donnees || {}, save: true });
      return true;
    };
    window.WEBVIEW_SERVER_URL = 'https://localhost';
    ${transport}
    window.Capacitor = { DEBUG: false, isLoggingEnabled: false, Plugins: {} };
    ${bridge}
    window.Capacitor.PluginHeaders = [
      { name: 'App', methods: [{ name: 'exitApp', rtype: 'promise' }, { name: 'getLaunchUrl', rtype: 'promise' }, { name: 'getInfo', rtype: 'promise' }, { name: 'addListener' }, { name: 'removeListener' }] },
      { name: 'Browser', methods: [{ name: 'open', rtype: 'promise' }] },
      { name: 'Haptics', methods: [{ name: 'impact', rtype: 'promise' }, { name: 'notification', rtype: 'promise' }] },
      { name: 'Keyboard', methods: [{ name: 'setAccessoryBarVisible', rtype: 'promise' }] },
      { name: 'LocalNotifications', methods: [{ name: 'requestPermissions', rtype: 'promise' }, { name: 'checkPermissions', rtype: 'promise' }, { name: 'schedule', rtype: 'promise' }, { name: 'cancel', rtype: 'promise' }, { name: 'getPending', rtype: 'promise' }] },
      { name: 'Network', methods: [{ name: 'getStatus', rtype: 'promise' }, { name: 'addListener' }] },
      { name: 'Share', methods: [{ name: 'share', rtype: 'promise' }] },
      { name: 'SplashScreen', methods: [{ name: 'hide', rtype: 'promise' }] },
      { name: 'StatusBar', methods: [{ name: 'setStyle', rtype: 'promise' }, { name: 'setBackgroundColor', rtype: 'promise' }, { name: 'setOverlaysWebView', rtype: 'promise' }] },
      { name: 'SocialLogin', methods: [{ name: 'initialize', rtype: 'promise' }, { name: 'login', rtype: 'promise' }, { name: 'logout', rtype: 'promise' }] }
    ];
    ${avecRegisterPlugin ? `
    window.Capacitor.registerPlugin = function (nom) {
      return new Proxy({}, { get: function (_, methode) {
        if (methode === 'then') return undefined;
        if (methode === 'addListener') return function (ev, cb) { return Promise.resolve(window.Capacitor.addListener(nom, ev, cb)); };
        return function (opts) { return window.Capacitor.nativePromise(nom, methode, opts || {}); };
      } });
    };` : ''}
  `;
}

/** Contexte « dans la coque ». taille : {width,height}. */
async function contexte(browser, plateforme, taille, opts = {}) {
  const ctx = await browser.newContext({
    viewport: taille || { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
    locale: 'fr-FR', timezoneId: 'Europe/Paris',
    userAgent: plateforme === 'android' ? UA_ANDROID : UA_IOS,
  });
  await ctx.addInitScript(scriptCoque(plateforme, !!opts.avecRegisterPlugin));
  const page = await ctx.newPage();
  await injecterCleApi(page);
  return { ctx, page };
}

function sha(fichier) {
  return require('crypto').createHash('sha256').update(fs.readFileSync(fichier)).digest('hex');
}

function jeton() {
  const f = process.env.C_TOKEN_FILE || path.join(SCRATCH, 'c-token.txt');
  return fs.existsSync(f) ? fs.readFileSync(f, 'utf8').trim() : null;
}

/** Journal de constats JSON, écrit à chaque note (un worker peut mourir sur un échec). */
function journal(dossier, nom) {
  const fichier = path.join(dossier, nom || 'constats.json');
  fs.mkdirSync(dossier, { recursive: true });
  const lire = () => { try { return JSON.parse(fs.readFileSync(fichier, 'utf8')); } catch (_e) { return {}; } };
  return { fichier, note(cle, valeur) { const c = lire(); c[cle] = valeur; fs.writeFileSync(fichier, JSON.stringify(c, null, 2)); }, lire };
}

/**
 * Enregistreur à budget de PNG : `snap(nom)` écrit TOUJOURS dom/console/network (preuves) et le PNG
 * seulement si `garder` (booléen ou regex) l'accepte. Réutilise le quatuor du dépôt.
 */
function enregistreur(page, dossier, garder = true) {
  const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');
  const rec = attachMegaAuditRecorder(page, dossier);
  const veut = (nom) => (garder instanceof RegExp ? garder.test(nom) : !!garder);
  return {
    async snap(nom) {
      await rec.snap(nom);
      if (!veut(nom)) { try { fs.unlinkSync(path.join(dossier, nom + '.png')); } catch (_e) { /* absent */ } }
    },
    dispose: rec.dispose,
  };
}

module.exports = {
  API, SITE_REPO, BRIDGE, BRIDGE_IOS, SCRATCH, SHOTS_ROOT, UA_ANDROID, UA_IOS,
  cleApiLocale, injecterCleApi, scriptCoque, contexte, sha, jeton, journal, enregistreur,
};
