/**
 * Kiosk order helper — Wave E (Kiosk↔Backend↔KDS sync audit).
 *
 * Wraps the kiosk order placement pipeline (machine login → quote → store →
 * payment confirm) behind a small set of programmatic helpers so audit specs
 * never have to drive the wizard UI to produce real, fiscally-valid orders.
 *
 * API contract (also restated in commit body for the Wave E GStack agent):
 *
 *   getKioskApiToken(page, machineId = null)
 *     → Promise<string>  (bearer token with kiosk:order ability)
 *
 *   placeKioskOrder(page, {
 *       items,            // [{ item_id, quantity, item_variations, item_extras, item_addons, instruction? }]
 *       paymentMethod,    // 1=cash_on_delivery, 4=card (TPE), 5=ticket_restaurant (per PaymentGateway interface)
 *       idempotencyKey,   // optional; auto-generated UUID v4 if absent
 *       branchId,         // optional; auto-resolved from seeded kiosk machine
 *       orderType = 25,   // kiosk
 *       source = 5,       // SOURCE_KIOSK
 *     })
 *     → Promise<{
 *         orderId, orderSerialNo, queueNumber,
 *         idempotencyKey, totalAmount, replayed,
 *         quote, order, paymentConfirm,
 *       }>
 *
 *   placeKioskOrderTwice(page, payload)
 *     → Promise<[firstResult, secondResult]>  (second.replayed === true expected)
 *
 *   placeKioskOrderTwiceDifferentPayload(page, payload1, payload2)
 *     → Promise<{ first: result1, second: { status, body } }>
 *       second.status === 409 expected (IdempotencyKeyMiddleware payload conflict)
 *
 *   cleanupKioskAuditOrders(prefix = 'AUDIT-KIOSK-WAVE-E')
 *     → JSON summary of canonical cancellations / preserved terminal rows
 *
 *   resetKioskToken()
 *     → void  (clears the module-level token cache)
 *
 * Constraints honoured:
 *   - kiosk-machine-login endpoint resolved from routes/api.php:158
 *     → POST /api/auth/kiosk-login   (throttle:kiosk-login, no auth, no apiKey
 *       since the apiKey middleware is on the parent 'auth' group via the
 *       installed+apiKey+localization stack — we POST with the same header
 *       the in-browser axios uses, see KIOSK_LOGIN_HEADERS below).
 *   - All order endpoints run inside page.evaluate() so they reuse the
 *     browser's CSRF cookie + axios interceptors. The token-issuance call is
 *     ALSO done via page.evaluate() — POSTs to /api/auth/kiosk-login do NOT
 *     require pre-existing auth (route is in the apiKey-gated group but the
 *     in-browser axios instance already injects X-API-KEY in
 *     KioskLoginComponent — mirrored here via the same window.axios).
 *   - X-Idempotency-Key UUID v4 generated client-side per placement, surfaced
 *     in the return value so replay tests can re-use it.
 *   - Rate-limit awareness: callers should `clearFoodKingRateLimits()` from
 *     './rate-limit.js' between scenarios that would otherwise blow through
 *     throttle:kiosk-orders (30/min) or throttle:kiosk-login.
 *
 * NF525 note: backend remains the SSOT for pricing — this helper only
 * forwards item_id + quantity + variation/extra/addon IDs; quote + order
 * totals come straight from PricingService.
 */

const { execFileSync } = require('child_process');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');

// Source / order_type codes (mirror sync-journey-trace.js for parity).
const SOURCE_KIOSK = 5;
const ORDER_TYPE_KIOSK = 25;
const ASK_NO = 10;

// PaymentGateway interface values (app/Enums/PaymentGateway.php).
const PAYMENT_CASH = 1;       // CASH_ON_DELIVERY
const PAYMENT_CARD = 4;       // CARD (TPE)
const PAYMENT_PREPAID = 5;    // TICKET_RESTAURANT

// Seeded kiosk machine on the dev DB — verified 2026-05-10 via
// `KioskMachine::query()->select('id','username','branch_id','status')->get()`:
//   id=1, username=kiosk-lecayenne, branch_id=1, status=ACTIVE(5).
// TODO: seeded kiosk machine ID — read from DB or env if you need a different
// machine. Override via env KIOSK_E2E_USERNAME / KIOSK_E2E_PASSWORD /
// KIOSK_E2E_MACHINE_ID.
const DEFAULT_KIOSK_USERNAME = process.env.KIOSK_E2E_USERNAME || 'kiosk-lecayenne';
const DEFAULT_KIOSK_PASSWORD = process.env.KIOSK_E2E_PASSWORD || 'kiosk123';
const DEFAULT_KIOSK_MACHINE_ID = Number(process.env.KIOSK_E2E_MACHINE_ID || 1);

const KIOSK_AUDIT_PREFIX = 'AUDIT-KIOSK-WAVE-E';

// Module-level token cache. One Sanctum token per node process is plenty —
// TTL is 480 minutes (config/sanctum.php) and Wave E runs in well under that.
let cachedToken = null;
let cachedTokenForMachineId = null;

/**
 * Reset the module-level token cache. Call between tests if you need a fresh
 * Sanctum token (e.g. to assert revocation behaviour, or to re-issue after a
 * deliberate logout).
 *
 * @returns {void}
 */
function resetKioskToken() {
  cachedToken = null;
  cachedTokenForMachineId = null;
  // On NE réarme PAS `_serveurDejaVerifie` : l'identité du serveur ne change pas parce qu'un
  // jeton est jeté, et la réarmer réintroduirait la latence que ce correctif supprime.
}

/**
 * Spawn `php artisan tinker --execute` synchronously and return its stdout.
 *
 * @param {string} code PHP code passed to --execute
 * @returns {string} trimmed stdout
 */
function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

/**
 * Pick the last JSON line out of an artisan execute output (tinker prefixes
 * include framework warnings + boot noise we want to skip).
 *
 * @param {string} output raw stdout
 * @returns {any} parsed JSON
 */
function parseArtisanJson(output) {
  const lines = String(output)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  const jsonLine = [...lines].reverse().find((line) => line.startsWith('{') || line.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON payload found in artisan output:\n${output}`);
  }
  return JSON.parse(jsonLine);
}

/**
 * Escape a PHP single-quoted string literal body.
 *
 * @param {string} value
 * @returns {string}
 */
function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

/**
 * Mutating E2E helpers require two independent signals: an explicit operator
 * opt-in and a database name that is unambiguously dedicated to tests.
 * APP_ENV is deliberately ignored because a misconfigured test process can
 * still point at a non-test database.
 */
function isDedicatedE2EWriteScope(
  databaseName,
  explicitOptIn = process.env.FOODKING_E2E_DEDICATED_DB,
) {
  // [REPLAN_8 2026-08-24] Segment ENTIER, pas sous-chaîne. L'ancien `/(test|e2e|playwright)/i`
  // acceptait `protest`, `contest_prod`, `foodking_greatest`, `lecayenne_latest` — vérifié en
  // exécutant la fonction exportée. Le commentaire promettait « unambiguously dedicated to
  // tests » ; la regexp ne le tenait pas. Les bases réellement utilisées ici passent toujours :
  // foodking_e2e, foodking_test, foodking_dash_e2e, foodking_e2e_stress,
  // foodking_kiosk_p1_test, foodking_va_sys_final_test, playwright_db.
  const nom = String(databaseName || '');
  const segmentDeTest = /(^|[^a-z0-9])(test|tests|testing|e2e|playwright)([^a-z0-9]|$)/i.test(nom);
  return explicitOptIn === '1' && segmentDeTest;
}

function assertDedicatedE2EWriteScope(
  databaseName,
  explicitOptIn = process.env.FOODKING_E2E_DEDICATED_DB,
) {
  if (!isDedicatedE2EWriteScope(databaseName, explicitOptIn)) {
    throw new Error(
      'E2E database writes require FOODKING_E2E_DEDICATED_DB=1 and a '
      + `test/e2e/playwright database name (database=${String(databaseName || 'unknown')}).`,
    );
  }
  return true;
}

function assertCurrentE2EWriteScope() {
  const identity = parseArtisanJson(artisan(`
    echo json_encode(['database' => (string) DB::connection()->getDatabaseName()]);
  `));
  assertDedicatedE2EWriteScope(identity.database);
  return identity.database;
}

/**
 * [REPLAN_8 2026-08-24] La garde de base ne prouvait QUE l'identité de la base vue par le
 * processus CLI (`php artisan tinker`). Or toutes les écritures partent du serveur HTTP visé par
 * `PLAYWRIGHT_BASE_URL`, et `playwright.config.js` a `reuseExistingServer: true` : Playwright
 * adopte silencieusement un serveur démarré par quelqu'un d'autre, avec l'environnement de
 * quelqu'un d'autre. Un serveur pointant sur la base de production passait donc la garde.
 *
 * Un jeton Sanctum a la forme `{id}|{secret}`. On vérifie que la ligne
 * `personal_access_tokens` que le SERVEUR vient d'écrire est visible depuis la base vérifiée en
 * CLI. Si les deux processus ne partagent pas la même base, la ligne est absente et on s'arrête
 * AVANT la première commande — c'est-à-dire avant toute écriture métier ou fiscale.
 *
 * Résidu assumé et documenté : la création du jeton elle-même reste écrite avant le contrôle.
 * Une preuve entièrement pré-écriture demanderait un point d'entrée serveur exposant l'identité
 * de sa base — c'est du code produit, hors périmètre de ce cycle, et remonté au propriétaire.
 *
 * @param {string} token jeton Sanctum en clair renvoyé par /api/auth/kiosk-login
 * @returns {void} lève si le serveur n'écrit pas dans la base vérifiée
 */
let _serveurDejaVerifie = false;

function assertServerSharesVerifiedDatabase(token) {
  // [CORRECTIF 2026-08-25] Une seule vérification par processus.
  //
  // Ce contrôle coûte un aller-retour `php artisan` (~1-2 s) et il était exécuté à CHAQUE
  // émission de jeton — donc systématiquement ENTRE l'émission et le premier usage. Or une
  // borne n'a qu'un jeton : ce délai élargissait la fenêtre pendant laquelle l'auto-login du
  // SPA révoque le jeton du banc, transformant un garde de sûreté en fabrique de 401.
  //
  // La propriété qu'on protège — « le serveur écrit-il dans la base vérifiée ? » — ne change
  // pas d'une émission à l'autre au sein d'un même run : le serveur et sa configuration sont
  // fixes. Une vérification unique, faite avant la toute première commande, la garantit
  // pleinement, sans payer la latence à chaque fois.
  if (_serveurDejaVerifie) return;

  const rawId = String(token || '').split('|')[0];
  if (!/^\d+$/.test(rawId)) {
    throw new Error(
      'Impossible de vérifier que le serveur HTTP partage la base de test : '
      + "le jeton kiosk n'a pas la forme Sanctum `{id}|{secret}` attendue.",
    );
  }
  const vu = parseArtisanJson(artisan(`
    echo json_encode([
      'database' => (string) DB::connection()->getDatabaseName(),
      'token_visible' => DB::table('personal_access_tokens')->where('id', ${rawId})->exists(),
      'max_id' => (int) (DB::table('personal_access_tokens')->max('id') ?? 0),
    ]);
  `));

  // [CORRECTIF 2026-08-25] La première version exigeait que la LIGNE du jeton soit encore
  // présente. C'était un FAUX POSITIF : la borne se reconnecte pendant le parcours et Sanctum
  // révoque à la reconnexion (CLAUDE.md §9), donc un jeton parfaitement légitime disparaît
  // entre son émission et ce contrôle. Mesuré : jeton #10711 émis puis supprimé, #10713 créé
  // dans la foulée — le garde bloquait un run sain.
  //
  // Le discriminant robuste n'est pas la présence de la ligne mais l'AVANCEMENT du compteur :
  // si le serveur écrivait dans une AUTRE base, l'auto-incrément vu par le CLI n'aurait jamais
  // atteint l'identifiant que le serveur vient d'attribuer. Une révocation, elle, ne fait pas
  // reculer `max(id)`. On accepte donc « ligne visible » OU « compteur au moins à cet id ».
  const idEmis = Number(rawId);
  const compteurAtteint = Number(vu.max_id || 0) >= idEmis;
  if (vu.token_visible || compteurAtteint) {
    _serveurDejaVerifie = true;
    return;
  }
  if (!vu.token_visible && !compteurAtteint) {
    throw new Error(
      'ARRÊT : le serveur HTTP visé par PLAYWRIGHT_BASE_URL n\'écrit PAS dans la base vérifiée '
      + `(${String(vu.database)}). Il vient d'attribuer le jeton kiosk #${idEmis}, or le plus grand `
      + `identifiant de jetons visible depuis cette base est ${vu.max_id} : le compteur n'y est `
      + "jamais passé. Une révocation légitime est exclue (elle ne fait pas reculer le compteur). "
      + 'Un serveur réutilisé (playwright.config.js reuseExistingServer) pointe probablement sur '
      + 'une autre base. Arrêté avant toute écriture de commande.',
    );
  }
}

/**
 * Résout un article RÉELLEMENT commandable sans assistant, pour la branche visée.
 *
 * [FIX 2026-08-25] Pourquoi ce helper existe.
 *
 * Dix des onze specs consommatrices étaient rouges, avec dix causes différentes qui se
 * ramenaient toutes à la même racine : un identifiant d'article FIGÉ dans le banc, et un menu
 * qui a bougé depuis. Relevé en base : les items 361, 362 et 485 n'existent plus ; le
 * Coca-Cola 33cl (id 52) que le banc d'idempotence code en dur est l'UNIQUE produit en
 * rupture de la branche 1 — la pastille de santé affichait d'ailleurs « 1 en rupture », c'est
 * le même fait vu d'une autre surface. D'autres bancs tombaient sur « Sélectionnez au moins
 * 1 Viande 1 » : l'article choisi exige un assistant.
 *
 * Un banc ne devrait jamais dépendre d'un identifiant : il doit décrire ce dont il a BESOIN.
 * Ici : actif, disponible sur la branche, sans variation, et sans étape d'assistant
 * obligatoire — donc commandable avec un payload simple.
 *
 * @param {object} [options]
 * @param {number} [options.branchId=1] branche visée
 * @param {string} [options.preferName] nom exact préféré, s'il est commandable
 * @param {number[]} [options.excludeIds] identifiants à écarter (commande multi-lignes)
 * @returns {{id: number, name: string, price: string}} article commandable
 */
function resolveSimpleOrderableItem({ branchId = 1, preferName = null, excludeIds = [] } = {}) {
  const prefere = preferName ? phpString(preferName) : null;
  const exclus = (Array.isArray(excludeIds) ? excludeIds : [])
    .map((n) => Number(n))
    .filter((n) => Number.isInteger(n) && n > 0);
  const resultat = parseArtisanJson(artisan(`
    $branchId = ${Number(branchId)};
    // [CORRECTIF 2026-08-25] DB::table() CONTOURNE les SoftDeletes du modele Item.
    // Mesure : les articles 4 a 8 (Sauce supplementaire, Fromage supplementaire...) portent
    // deleted_at renseigne DEPUIS le 2026-05-28 tout en gardant status = 5. Le resolveur les
    // proposait donc comme commandables, et le devis les refusait en 422 Article X introuvable
    // — FrontendOrderService interroge Item::whereIn(...), qui applique bien le scope de
    // suppression douce. On l'applique ici aussi.
    $base = DB::table('items')
      ->whereNull('items.deleted_at')
      ->where('items.status', 5)
      ${exclus.length ? `->whereNotIn('items.id', [${exclus.join(',')}])` : ''}
      ->whereNotExists(function ($q) {
        $q->select(DB::raw(1))->from('item_variations')
          ->whereColumn('item_variations.item_id', 'items.id');
      })
      ->whereNotExists(function ($q) {
        $q->select(DB::raw(1))
          ->from('item_wizard_profiles')
          ->join('item_wizard_steps', 'item_wizard_steps.profile_id', '=', 'item_wizard_profiles.id')
          ->whereColumn('item_wizard_profiles.item_id', 'items.id')
          ->where('item_wizard_steps.min_select', '>', 0);
      })
      ->whereNotExists(function ($q) use ($branchId) {
        $q->select(DB::raw(1))->from('item_branch_availability')
          ->whereColumn('item_branch_availability.item_id', 'items.id')
          ->where('item_branch_availability.branch_id', $branchId)
          ->where('item_branch_availability.is_available', 0);
      });

    // [CORRECTIF 2026-08-25] Preferer un article REELLEMENT route vers une station de cuisine.
    //
    // Le tableau KDS filtre par station (filterOrdersByStation). Les articles 1 a 3
    // (Menu, Frites Seules, Boisson Seule) portent kds_station = 'none' : une commande qui ne
    // contient qu'eux n'apparait sur AUCUNE station, donc jamais sur le board. Les bancs
    // borne -> KDS echouaient alors sur une carte absente, alors que la commande etait bien en
    // statut ACCEPT et parfaitement visible cote requete. On privilegie donc un article ayant
    // une vraie station, avec repli sur n'importe quel article commandable.
    $avecStation = (clone $base)
      ->whereNotNull('items.kds_station')
      ->where('items.kds_station', '!=', 'none')
      ->orderBy('items.id');

    $choisi = null;
    ${prefere ? `$choisi = (clone $base)->where('items.name', '${prefere}')->first(['id','name','price']);` : ''}
    if (! $choisi) {
      $choisi = (clone $avecStation)->first(['id','name','price']);
    }
    if (! $choisi) {
      $choisi = $base->orderBy('items.id')->first(['id','name','price']);
    }
    echo json_encode(['item' => $choisi]);
  `));

  const item = resultat && resultat.item;
  if (!item || !item.id) {
    throw new Error(
      `Aucun article commandable sans assistant sur la branche ${branchId} : actif, disponible, `
      + 'sans variation et sans étape obligatoire. Le menu de cette base est-il seedé ?',
    );
  }
  return { id: Number(item.id), name: String(item.name), price: String(item.price) };
}

/**
 * Crypto-strength UUID v4 (RFC 4122). We avoid the Node 16 `crypto.randomUUID`
 * dependency to keep this helper usable from the in-browser evaluate context
 * if needed — but the primary caller path is Node-side.
 *
 * @returns {string} UUID v4
 */
function uuidV4() {
  // eslint-disable-next-line global-require
  const { randomBytes } = require('crypto');
  const b = randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = b.toString('hex');
  return (
    `${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-` +
    `${h.substring(16, 20)}-${h.substring(20, 32)}`
  );
}

/**
 * Resolve branch_id (and confirm machine is ACTIVE) by hitting the DB
 * directly via tinker. Used as a fallback when the caller does not pass
 * a branchId AND the cached token's machine record is unknown.
 *
 * @param {number} machineId
 * @returns {{ id: number, username: string, branch_id: number, status: number }}
 */
function lookupKioskMachine(machineId) {
  const id = Number(machineId);
  if (!Number.isFinite(id) || id <= 0) {
    throw new Error(`Invalid kiosk machine id: ${machineId}`);
  }
  return parseArtisanJson(artisan(`
    $m = \\App\\Models\\KioskMachine::withoutGlobalScopes()->find(${id});
    if (! $m) { echo json_encode(['error' => 'kiosk_machine_not_found', 'id' => ${id}]); return; }
    echo json_encode([
      'id' => (int) $m->id,
      'username' => (string) $m->username,
      'branch_id' => (int) $m->branch_id,
      'status' => (int) $m->status,
    ]);
  `));
}

/**
 * Authenticate as a kiosk machine and return a Sanctum bearer token with the
 * `kiosk:order` ability. Result is cached on this module — call
 * `resetKioskToken()` to force a fresh issuance.
 *
 * Endpoint: POST /api/auth/kiosk-login (routes/api.php line 158),
 * middleware `throttle:kiosk-login` (no auth — credentials in body).
 *
 * @param {import('@playwright/test').Page} page Playwright page (used to
 *   reuse the in-browser axios so apiKey + CSRF + base URL match the real
 *   client). Pass null/undefined to fall back to Node-side fetch (see below).
 * @param {number|null} [machineId] Optional explicit machine id; defaults to
 *   the seeded DEFAULT_KIOSK_MACHINE_ID. The id is currently only used to
 *   key the cache + resolve a branch — the actual auth uses username/password.
 * @returns {Promise<string>} bearer token (without the `Bearer ` prefix)
 */
/**
 * Clé API attendue par `ApiKeyMiddleware` sur le chemin Node.
 *
 * Ordre : variable d'environnement explicite, puis `.env` du dépôt (même source que
 * `config('app.api_key')`). On échoue avec un message qui NOMME le problème plutôt que de
 * laisser le serveur répondre un 400 « Clé API invalide » que l'appelant interprétera comme
 * un identifiant borne erroné — c'est exactement le contresens qui a coûté ce diagnostic.
 *
 * @returns {string}
 */
function resolveApiKeyForNodePath() {
  const direct = process.env.MIX_API_KEY || process.env.API_KEY;
  if (direct) return String(direct).trim();
  try {
    // eslint-disable-next-line global-require
    const fs = require('fs');
    const envPath = path.resolve(__dirname, '../../..', '.env');
    const brut = fs.readFileSync(envPath, 'utf8');
    const m = brut.match(/^\s*(?:MIX_API_KEY|API_KEY)\s*=\s*(.+)$/m);
    if (m) return m[1].trim().replace(/^["']|["']$/g, '');
  } catch (_) { /* pas de .env lisible : on tombe dans l'erreur explicite ci-dessous */ }
  throw new Error(
    "Chemin Node de getKioskApiToken : aucune clé API trouvée. ApiKeyMiddleware refuse toute "
    + "requête sans en-tête `x-api-key` (HTTP 400). Définis MIX_API_KEY ou API_KEY, ou rends "
    + "le .env du dépôt lisible.",
  );
}

async function getKioskApiToken(page, machineId = null) {
  assertCurrentE2EWriteScope();
  const targetMachineId = machineId == null ? DEFAULT_KIOSK_MACHINE_ID : Number(machineId);
  if (cachedToken && cachedTokenForMachineId === targetMachineId) {
    return cachedToken;
  }

  const credentials = {
    username: DEFAULT_KIOSK_USERNAME,
    password: DEFAULT_KIOSK_PASSWORD,
  };

  // In-browser path: window.axios already carries X-API-KEY / language / CSRF.
  if (page && typeof page.evaluate === 'function') {
    const result = await page.evaluate(async (creds) => {
      try {
        const response = await window.axios.post('auth/kiosk-login', creds);
        return { ok: true, status: response.status, data: response.data };
      } catch (err) {
        return {
          ok: false,
          status: err?.response?.status ?? 0,
          data: err?.response?.data ?? { message: String(err?.message || err) },
        };
      }
    }, credentials);

    if (!result.ok || !result.data || !result.data.token) {
      throw new Error(
        `Kiosk login failed (HTTP ${result.status}): ${JSON.stringify(result.data).slice(0, 400)}`,
      );
    }
    assertServerSharesVerifiedDatabase(result.data.token);
    cachedToken = result.data.token;
    cachedTokenForMachineId = targetMachineId;
    return cachedToken;
  }

  // Node-side fallback — not the primary path but kept so the helper is
  // standalone-runnable without a Playwright page (useful for unit-style probes).
  // eslint-disable-next-line global-require
  const http = require('http');
  const body = JSON.stringify(credentials);
  const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
  const url = new URL('/api/auth/kiosk-login', baseUrl);

  // [FIX 2026-08-25] Le chemin Node n'a JAMAIS porté `x-api-key`, alors que
  // `ApiKeyMiddleware::handle` refuse en 400 « Clé API invalide » toute requête sans cet
  // en-tête. Seul le chemin navigateur fonctionnait, parce que l'axios de la page l'injecte
  // (voir le commentaire du chemin in-browser plus haut). Les bancs qui choisissent
  // délibérément le repli Node — Wave D le fait pour échapper à la course de l'auto-login de
  // la borne — mouraient donc à l'émission du jeton, pour une raison sans rapport avec le
  // parcours testé. La clé n'est pas un secret (le middleware le documente : elle est publiée
  // dans un meta HTML et des bundles JS publics) ; on la lit depuis l'environnement, jamais
  // en dur.
  const apiKey = resolveApiKeyForNodePath();
  const result = await new Promise((resolve, reject) => {
    const req = http.request(
      {
        method: 'POST',
        hostname: url.hostname,
        port: url.port || 80,
        path: url.pathname,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'Content-Length': Buffer.byteLength(body),
          'x-api-key': apiKey,
        },
      },
      (res) => {
        let chunks = '';
        res.on('data', (c) => { chunks += c.toString(); });
        res.on('end', () => {
          try {
            resolve({ status: res.statusCode, data: JSON.parse(chunks) });
          } catch (parseErr) {
            resolve({ status: res.statusCode, data: { raw: chunks, parseErr: String(parseErr) } });
          }
        });
      },
    );
    req.on('error', reject);
    req.write(body);
    req.end();
  });

  if (result.status !== 201 || !result.data || !result.data.token) {
    throw new Error(
      `Kiosk login failed (HTTP ${result.status}): ${JSON.stringify(result.data).slice(0, 400)}`,
    );
  }
  assertServerSharesVerifiedDatabase(result.data.token);
  cachedToken = result.data.token;
  cachedTokenForMachineId = targetMachineId;
  return cachedToken;
}

/**
 * Resolve the branch_id to bill the order to. Order of precedence:
 *   1. explicit `branchId` argument
 *   2. machine record lookup (DB) — single round-trip cached at module level
 *
 * @param {number|null} branchId
 * @param {number} machineId
 * @returns {number}
 */
let cachedBranchForMachineId = null;
let cachedBranchId = null;
function resolveBranchId(branchId, machineId) {
  if (branchId != null && Number.isFinite(Number(branchId))) return Number(branchId);
  if (cachedBranchForMachineId === machineId && cachedBranchId != null) return cachedBranchId;
  const machine = lookupKioskMachine(machineId);
  if (machine.error) {
    throw new Error(`Cannot resolve branch_id: ${JSON.stringify(machine)}`);
  }
  cachedBranchForMachineId = machineId;
  cachedBranchId = machine.branch_id;
  return cachedBranchId;
}

/**
 * Place a kiosk order end-to-end (quote → store → payment-confirm).
 *
 * Runs the three HTTP calls inside `page.evaluate()` so the in-browser axios
 * instance handles CSRF, base URL, X-API-KEY, and Accept-Language headers
 * identically to a real kiosk client. The Sanctum bearer token is injected
 * per-call via the `Authorization` header (cleaner than mutating axios
 * defaults — leaves the in-browser session untouched).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} options
 * @param {Array<{
 *   item_id: number,
 *   quantity: number,
 *   item_variations?: Array<{ id: number, quantity?: number }>,
 *   item_extras?: Array<{ id: number, quantity?: number }>,
 *   item_addons?: Array<{ id: number, quantity?: number }>,
 *   instruction?: string,
 * }>} options.items
 * @param {number} options.paymentMethod 1=cash, 4=card, 5=prepaid
 * @param {string|null} [options.idempotencyKey] auto-UUID v4 if null
 * @param {number|null} [options.branchId] auto-resolved from machine if null
 * @param {number} [options.orderType=25] kiosk order type
 * @param {number} [options.source=5] SOURCE_KIOSK
 * @param {number} [options.machineId=DEFAULT_KIOSK_MACHINE_ID]
 * @param {boolean} [options.skipPaymentConfirm=false] skip the
 *   payment-confirm POST (useful for card flows that go via TPE reconcile)
 * @returns {Promise<{
 *   orderId: number,
 *   orderSerialNo: string,
 *   queueNumber: string|null,
 *   idempotencyKey: string,
 *   totalAmount: number,
 *   replayed: boolean,
 *   quote: any,
 *   order: any,
 *   paymentConfirm: any|null,
 * }>}
 */
async function placeKioskOrder(page, options) {
  assertCurrentE2EWriteScope();
  if (!page || typeof page.evaluate !== 'function') {
    throw new Error('placeKioskOrder requires a Playwright page (for in-browser axios).');
  }
  const {
    items,
    paymentMethod,
    idempotencyKey = null,
    branchId = null,
    orderType = ORDER_TYPE_KIOSK,
    source = SOURCE_KIOSK,
    machineId = DEFAULT_KIOSK_MACHINE_ID,
    skipPaymentConfirm = false,
    tokenPrefix = KIOSK_AUDIT_PREFIX,
  } = options || {};

  if (!Array.isArray(items) || items.length === 0) {
    throw new Error('placeKioskOrder: items must be a non-empty array.');
  }
  if (![PAYMENT_CASH, PAYMENT_CARD, PAYMENT_PREPAID].includes(Number(paymentMethod))) {
    throw new Error(
      `placeKioskOrder: paymentMethod must be 1 (cash), 4 (card) or 5 (prepaid); got ${paymentMethod}`,
    );
  }

  const token = await getKioskApiToken(page, machineId);
  const resolvedBranchId = resolveBranchId(branchId, machineId);
  const idemKey = idempotencyKey || uuidV4();
  const runStamp = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  // [GOAL CONSOLIDATION T-4.2.1] Le préfixe doit être validé ICI, à l'écriture, et pas
  // seulement au nettoyage : un préfixe non conforme produirait des lignes qu'aucun
  // `cleanupKioskAuditOrders` ne pourrait ensuite reprendre (il refuse les mêmes formes).
  assertPrefixeAuditValide(tokenPrefix, 'placeKioskOrder');
  const orderToken = `${tokenPrefix}-${runStamp}`;

  const evalResult = await page.evaluate(async ({
    bearer,
    items,
    paymentMethod,
    idemKey,
    branchId,
    orderType,
    source,
    orderToken,
    askNo,
    skipPaymentConfirm,
  }) => {
    const authHeader = { Authorization: `Bearer ${bearer}` };

    const basePayload = {
      branch_id: branchId,
      token: orderToken,
      discount: 0,
      order_type: orderType,
      is_advance_order: askNo,
      source,
      payment_method: paymentMethod,
      items: JSON.stringify(items),
    };

    // 1. Quote — backend returns signed totals.
    let quoteResp;
    try {
      quoteResp = await window.axios.post('frontend/order/quote', basePayload, {
        headers: { ...authHeader },
      });
    } catch (err) {
      return {
        ok: false,
        stage: 'quote',
        status: err?.response?.status ?? 0,
        data: err?.response?.data ?? { message: String(err?.message || err) },
      };
    }
    const quote = quoteResp.data?.data || quoteResp.data;

    // 2. Store — locks fiscal sequence + composition snapshot.
    let storeResp;
    try {
      storeResp = await window.axios.post('frontend/order', {
        ...basePayload,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
        subtotal: quote.subtotal,
        discount: quote.discount,
        delivery_charge: quote.delivery_charge,
        total: quote.total_ttc,
      }, {
        headers: {
          ...authHeader,
          'X-Idempotency-Key': idemKey,
        },
      });
    } catch (err) {
      return {
        ok: false,
        stage: 'store',
        status: err?.response?.status ?? 0,
        data: err?.response?.data ?? { message: String(err?.message || err) },
        quote,
        // Surface Idempotency-Replayed for 409 conflict diagnostics.
        headers: err?.response?.headers ?? null,
      };
    }
    const order = storeResp.data?.data || storeResp.data;
    const storeHeaders = storeResp.headers || {};
    const replayed =
      String(storeHeaders['idempotency-replayed'] || storeHeaders['Idempotency-Replayed'] || '')
        .toLowerCase() === 'true';

    // 3. Payment-confirm — cash flow only completes here. Card flow is normally
    // driven by PaymentReconcileController after TPE response, but the
    // payment-confirm endpoint accepts simulated transaction IDs for tests
    // (parity with createKioskCardOrderViaApi in sync-journey-trace.js).
    let paymentConfirm = null;
    if (!skipPaymentConfirm) {
      try {
        const confirmResp = await window.axios.post(
          `frontend/order/${order.id}/payment-confirm`,
          {
            transaction_id: `${orderToken}-TPE-${Date.now()}`,
            card_type: 'simulated-card',
            payment_method: paymentMethod,
          },
          {
            headers: {
              ...authHeader,
              'X-Idempotency-Key': `${idemKey}-confirm`,
            },
          },
        );
        paymentConfirm = confirmResp.data;
      } catch (err) {
        return {
          ok: false,
          stage: 'payment-confirm',
          status: err?.response?.status ?? 0,
          data: err?.response?.data ?? { message: String(err?.message || err) },
          quote,
          order,
          replayed,
        };
      }
    }

    return {
      ok: true,
      quote,
      order,
      paymentConfirm,
      replayed,
      headers: storeHeaders,
    };
  }, {
    bearer: token,
    items,
    paymentMethod: Number(paymentMethod),
    idemKey,
    branchId: resolvedBranchId,
    orderType: Number(orderType),
    source: Number(source),
    orderToken,
    askNo: ASK_NO,
    skipPaymentConfirm: Boolean(skipPaymentConfirm),
  });

  if (!evalResult.ok) {
    const err = new Error(
      `placeKioskOrder failed at stage="${evalResult.stage}" HTTP ${evalResult.status}: ` +
        `${JSON.stringify(evalResult.data).slice(0, 600)}`,
    );
    err.stage = evalResult.stage;
    err.status = evalResult.status;
    err.body = evalResult.data;
    err.idempotencyKey = idemKey;
    throw err;
  }

  const { quote, order, paymentConfirm, replayed } = evalResult;
  return {
    orderId: Number(order?.id ?? order?.order_id ?? 0),
    orderSerialNo: String(order?.order_serial_no ?? ''),
    // Queue numbers are opaque display identifiers (current format: "A0045"),
    // never numeric counters. Number("A0045") produced NaN and silently broke
    // identity assertions across KDS/OSS/POS audit evidence.
    queueNumber: order?.queue_number == null ? null : String(order.queue_number),
    idempotencyKey: idemKey,
    totalAmount: Number(order?.total ?? quote?.total_ttc ?? 0),
    replayed: Boolean(replayed),
    quote,
    order,
    paymentConfirm,
  };
}

/**
 * Place the SAME order payload twice with the same X-Idempotency-Key. The
 * second call should be replayed by IdempotencyKeyMiddleware (HTTP 2xx with
 * `Idempotency-Replayed: true` header — surfaced as `replayed: true`).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} payload same shape as `placeKioskOrder` options
 * @returns {Promise<[
 *   Awaited<ReturnType<typeof placeKioskOrder>>,
 *   Awaited<ReturnType<typeof placeKioskOrder>>
 * ]>}
 */
async function placeKioskOrderTwice(page, payload) {
  const key = payload.idempotencyKey || uuidV4();
  const first = await placeKioskOrder(page, { ...payload, idempotencyKey: key });
  const second = await placeKioskOrder(page, { ...payload, idempotencyKey: key });
  return [first, second];
}

/**
 * Send two DIFFERENT payloads under the SAME idempotency key. The second
 * call must produce HTTP 409 Conflict (payload-hash mismatch in
 * IdempotencyKeyMiddleware).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} payload1
 * @param {object} payload2
 * @returns {Promise<{
 *   first: Awaited<ReturnType<typeof placeKioskOrder>>,
 *   second: { status: number, body: any, idempotencyKey: string },
 * }>}
 */
async function placeKioskOrderTwiceDifferentPayload(page, payload1, payload2) {
  const key = payload1.idempotencyKey || payload2.idempotencyKey || uuidV4();
  const first = await placeKioskOrder(page, { ...payload1, idempotencyKey: key });

  // Second call must NOT throw — we want the structured 409 instead, so we
  // catch the placeKioskOrder error and unpack stage/status/body.
  let second;
  try {
    const okResult = await placeKioskOrder(page, { ...payload2, idempotencyKey: key });
    second = { status: 200, body: okResult, idempotencyKey: key };
  } catch (err) {
    second = {
      status: err.status ?? 0,
      body: err.body ?? { message: String(err.message || err) },
      idempotencyKey: key,
      stage: err.stage,
    };
  }
  return { first, second };
}

/**
 * Canonically neutralize active kiosk audit orders without deleting any
 * fiscal or lifecycle evidence. The signature is intentionally unchanged
 * because this helper is shared by historical audit specs.
 *
 * Safety contract:
 *   - refuse every write outside a dedicated test/E2E database;
 *   - select only rows carrying the exact caller prefix;
 *   - transition cancelable rows through OrderService::changeStatus so stock,
 *     audit, state-machine and after-commit dispatch rules stay authoritative;
 *   - preserve terminal/non-cancelable rows and every child/audit/event row;
 *   - fail explicitly if a cancelable row cannot be neutralized.
 *
 * @param {string} [prefix='AUDIT-KIOSK-WAVE-E']
 * @returns {{ matched: number, canceled: number, preserved: number, failed: Array }}
 */
/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-4.2.1]
 *
 * Règles communes au préfixe d'audit, appliquées AUSSI BIEN à l'écriture qu'au nettoyage.
 * Un préfixe trop court balaierait trop large ; `%`, `_` et `\\` sont des métacaractères
 * `LIKE` et transformeraient un nettoyage ciblé en purge.
 */
function assertPrefixeAuditValide(prefixe, appelant) {
  const net = String(prefixe == null ? '' : prefixe);
  if (net.trim().length < 8 || /[%_\\]/.test(net)) {
    throw new Error(
      `${appelant}: préfixe d'audit refusé — il doit faire au moins 8 caractères et ne contenir `
      + `aucun métacaractère LIKE (%, _, \\). Reçu : ${JSON.stringify(prefixe)}.`,
    );
  }
  return net;
}

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-4.2.1]
 *
 * Dérive un préfixe d'audit PROPRE À UNE SPEC à partir de son nom de fichier.
 *
 * POURQUOI : le 2026-08-25, huit specs écrivaient toutes sous `AUDIT-KIOSK-WAVE-E`. Chacune
 * nettoyait ensuite par `LIKE 'AUDIT-KIOSK-WAVE-E%'` — donc emportait les commandes VIVANTES
 * des sept autres. En séquentiel ça passe ; en parallèle, une spec voit ses lignes disparaître
 * sous elle, et l'échec ressemble à un défaut produit alors que c'est une collision de harnais.
 *
 * Exemple : 'test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js' → 'AUDIT-E2E-KIOSK-KDS-SYNC-WAVE-D'
 *
 * @param {string} cheminSpec chemin ou nom de fichier de la spec (typiquement `__filename`)
 * @returns {string} préfixe stable, disjoint, et conforme à assertPrefixeAuditValide
 */
function prefixeAuditPourSpec(cheminSpec) {
  const base = String(cheminSpec || '')
    .split(/[\\/]/)
    .pop()
    .replace(/\.spec\.js$/i, '');

  const noyau = base
    .toUpperCase()
    .replace(/\b20\d{2}-\d{2}-\d{2}\b/g, '')   // les dates n'ajoutent aucune distinction utile
    .replace(/^TEST-/, '')
    .replace(/[^A-Z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 44);

  const prefixe = `AUDIT-${noyau}`;
  // Un nom de spec trop court ne doit pas produire un préfixe trop large.
  return assertPrefixeAuditValide(prefixe.length >= 8 ? prefixe : `${prefixe}-SPEC`, 'prefixeAuditPourSpec');
}

function cleanupKioskAuditOrders(prefix = KIOSK_AUDIT_PREFIX) {
  // [REPLAN_8 2026-08-24] La garde est désormais la PREMIÈRE instruction : elle précédait un
  // aller-retour artisan, ce qui rendait la formule « appelée au début » littéralement fausse et
  // laissait toute ligne ajoutée avant elle hors garde.
  assertCurrentE2EWriteScope();

  // [REPLAN_8 2026-08-24] Un préfixe vide ou trop court donnerait `LIKE '%'` et annulerait TOUTES
  // les commandes annulables de la branche. Les onze appelants passent des constantes littérales,
  // mais rien ne l'imposait : on l'impose ici. `%` et `_` sont des jokers LIKE : les interdire
  // évite un balayage bien plus large que le préfixe annoncé.
  const prefixeNet = String(prefix == null ? '' : prefix);
  if (prefixeNet.trim().length < 8 || /[%_\\]/.test(prefixeNet)) {
    throw new Error(
      'cleanupKioskAuditOrders exige un préfixe littéral d\'au moins 8 caractères, sans joker '
      + `LIKE (%, _, \\). Reçu : ${JSON.stringify(prefix)}.`,
    );
  }

  const escaped = phpString(prefixeNet);
  const username = phpString(DEFAULT_KIOSK_USERNAME);
  const scope = parseArtisanJson(artisan(`
    $machine = App\\Models\\KioskMachine::query()
      ->where('username', '${username}')
      ->first();
    echo json_encode([
      'database' => (string) DB::connection()->getDatabaseName(),
      'machine_id' => $machine ? (int) $machine->id : null,
      'kiosk_username' => '${username}',
      'branch_id' => $machine ? (int) $machine->branch_id : 0,
    ]);
  `));
  assertDedicatedE2EWriteScope(scope.database);
  const branchId = Number(scope.branch_id || 0);
  if (!Number.isInteger(branchId) || branchId <= 0 || !scope.machine_id) {
    throw new Error(
      `cleanupKioskAuditOrders requires an existing branch-scoped kiosk machine: ${JSON.stringify(scope)}`,
    );
  }
  const result = parseArtisanJson(artisan(`
    $prefix = '${escaped}';
    $branchId = ${branchId};

    $orders = App\\Models\\Order::withoutGlobalScopes()
      ->where('branch_id', $branchId)
      ->where(function ($query) use ($prefix) {
        $query->where('token', 'like', $prefix . '%')
          ->orWhere('order_serial_no', 'like', $prefix . '%');
      })
      ->orderBy('id')
      ->get();
    $canceled = 0;
    $preserved = 0;
    $failed = [];

    foreach ($orders as $order) {
      $target = App\\Enums\\OrderStatus::CANCELED;
      if ((int) $order->status === $target) {
        $preserved++;
        continue;
      }
      $canCancel = (new App\\Rules\\ValidStatusTransition((int) $order->status))
        ->passes('status', $target);
      if (! $canCancel) {
        $preserved++;
        continue;
      }

      try {
        $request = App\\Http\\Requests\\OrderStatusRequest::create('/', 'POST', [
          'status' => $target,
          'reason' => 'Nettoyage canonique d une commande E2E préfixée',
        ]);
        $request->setContainer(app());
        app(App\\Services\\OrderService::class)->changeStatus($order, $request, false);
        $canceled++;
      } catch (Throwable $error) {
        $failed[] = [
          'order_id' => (int) $order->id,
          'status' => (int) $order->status,
          'error' => $error->getMessage(),
        ];
      }
    }

    $remaining = App\\Models\\Order::withoutGlobalScopes()
      ->where('branch_id', $branchId)
      ->where(function ($query) use ($prefix) {
        $query->where('token', 'like', $prefix . '%')
          ->orWhere('order_serial_no', 'like', $prefix . '%');
      })
      ->whereIn('status', [
        App\\Enums\\OrderStatus::PENDING,
        App\\Enums\\OrderStatus::ACCEPT,
        App\\Enums\\OrderStatus::PREPARING,
        App\\Enums\\OrderStatus::PREPARED,
        App\\Enums\\OrderStatus::OUT_FOR_DELIVERY,
      ])
      ->orderBy('id')
      ->pluck('id')
      ->map(fn ($id) => (int) $id)
      ->values();

    echo json_encode([
      'database' => (string) DB::connection()->getDatabaseName(),
      'branch_id' => $branchId,
      'matched' => $orders->count(),
      'canceled' => $canceled,
      'preserved' => $preserved,
      'failed' => $failed,
      'remaining_active_order_ids' => $remaining,
    ]);
  `));
  if ((Array.isArray(result.failed) && result.failed.length > 0)
    || (Array.isArray(result.remaining_active_order_ids) && result.remaining_active_order_ids.length > 0)) {
    throw new Error('Canonical kiosk cleanup failed: ' + JSON.stringify({
      failed: result.failed,
      remaining_active_order_ids: result.remaining_active_order_ids,
    }));
  }
  return result;
}

module.exports = {
  // Constants — exported so specs don't have to re-derive payment codes.
  SOURCE_KIOSK,
  ORDER_TYPE_KIOSK,
  PAYMENT_CASH,
  PAYMENT_CARD,
  PAYMENT_PREPAID,
  KIOSK_AUDIT_PREFIX,
  DEFAULT_KIOSK_MACHINE_ID,
  DEFAULT_KIOSK_USERNAME,
  // Token lifecycle.
  getKioskApiToken,
  resetKioskToken,
  // Placement primitives.
  placeKioskOrder,
  placeKioskOrderTwice,
  placeKioskOrderTwiceDifferentPayload,
  // Cleanup.
  cleanupKioskAuditOrders,
  prefixeAuditPourSpec,
  assertPrefixeAuditValide,
  isDedicatedE2EWriteScope,
  assertDedicatedE2EWriteScope,
  resolveSimpleOrderableItem,
  // Util re-exports for spec convenience.
  uuidV4,
};
