// Node harness — loads the WEB stub (/Users/1millnonstop/Downloads/web/api.js)
// and the MOBILE stub (mobile/api/client.js) and calls .profile() for the SAME token.
const fs = require('fs');
const vm = require('vm');

const TOKEN = process.argv[2];
if (!TOKEN) { console.error('usage: node harness.js <token>'); process.exit(2); }

const WEB_STUB = '/Users/1millnonstop/Downloads/web/api.js';
const MOB_STUB = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/api/client.js';

// Shared fetch (Node 18 global). document intentionally absent -> try/catch fallbacks fire.
function makeSandbox(withStorage) {
  const store = {};
  const localStorage = {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
  };
  const win = { LC: {} };
  win.window = win; // self-ref
  const sandbox = {
    window: win,
    localStorage,
    fetch: globalThis.fetch,
    crypto: globalThis.crypto,
    console,
    setTimeout, clearTimeout,
  };
  // WEB path uses localStorage token key 'lecayenne.authToken'
  store['lecayenne.authToken'] = TOKEN;
  store['lecayenne.authPhone'] = '0000000000';
  if (withStorage) {
    // MOBILE path reads token via window.LC.storage.getToken()
    win.LC.storage = {
      getToken: () => TOKEN,
      getAuth: () => ({ token: TOKEN }),
      setAuth: () => {},
      clearAuth: () => {},
    };
  }
  sandbox.globalThis = sandbox;
  return sandbox;
}

async function runWeb() {
  const sb = makeSandbox(false);
  vm.createContext(sb);
  vm.runInContext(fs.readFileSync(WEB_STUB, 'utf8'), sb, { filename: WEB_STUB });
  const api = sb.window.LC.api;
  return api.profile();
}
async function runMobile() {
  const sb = makeSandbox(true);
  vm.createContext(sb);
  vm.runInContext(fs.readFileSync(MOB_STUB, 'utf8'), sb, { filename: MOB_STUB });
  const api = sb.window.LC.mobileApi;
  return api.profile();
}

(async () => {
  try {
    const web = await runWeb();
    const mob = await runMobile();
    console.log(JSON.stringify({
      web_loyalty_points: web && web.loyalty_points,
      web_loyalty_code: web && web.loyalty_code,
      web_id: web && web.id,
      mobile_loyalty_points: mob && mob.loyalty_points,
      mobile_loyalty_code: mob && mob.loyalty_code,
      mobile_id: mob && mob.id,
    }, null, 2));
  } catch (e) {
    console.error('HARNESS ERROR', e);
    process.exit(1);
  }
})();
