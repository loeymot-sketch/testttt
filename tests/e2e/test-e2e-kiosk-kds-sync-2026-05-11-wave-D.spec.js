// FoodKing E2E — Kiosk · KDS · OSS sync audit Wave D — 2026-05-11
//
// Wave D scope : kiosk borne ↔ KDS ↔ OSS cross-surface sync end-to-end.
// 3 browser contexts in parallel:
//   - kioskCtx : kiosk borne — programmatic order via helpers/kiosk-order.js
//                (kiosk wizard NOT driven; UI captured at boundaries only).
//                Confirmation page navigated manually post-placement to
//                exercise the static render path.
//   - kdsCtx   : chef@lecayenne.fr → /admin/kitchen-display-system
//   - ossCtx   : admin@lecayenne.fr → /admin/order-status-screen
//
// Each context attaches its own mega-audit recorder; state names are
// namespaced `NN-d-<surface>-*` so files never collide in the shared
// SCREENSHOT_DIR.
//
// CRITICAL DESIGN DECISIONS (read before maintenance) :
//
// (1) NO `php artisan queue:work --once` between phases.
//     QUEUE_CONNECTION=sync : jobs run inline. SYNC budgets remain
//     `waitForFunction` timeouts on the receiving surface.
//
// (2) SYNC-2 reframing — OSS by design only renders PREPARING+PREPARED
//     (cf PreparingAndReadyComponent.vue lines 24-28). Kiosk-cash orders
//     land at ACCEPT(4), so "kiosk pay → OSS within 8s" is vacuously
//     false: the order is NOT in OSS's render set until the chef bumps it
//     to PREPARING. SYNC-2 is therefore measured at the PREPARING bump
//     (KDS → PREPARING → OSS preparing column within 5s) and SYNC-4 at
//     the PREPARED bump (KDS → PREPARED → OSS prepared column within 5s).
//
// (3) SYNC-3 downgrade — KioskConfirmationComponent.vue does NOT subscribe
//     to Echo. `displayNumber` and `displayTotal` come from props
//     (route.query) snap-frozen at mount; mounted() immediately calls
//     `kioskCart/reset` and starts a 30s countdown to /kiosk/idle. The
//     confirmation screen is STATIC by design — there is no live order-
//     status reflection on the customer screen. SYNC-3 is downgraded to:
//     navigate kioskPage manually to /kiosk/confirmation?orderNumber=N
//     after placement, verify the static render via the testid'd nodes,
//     and document in observations that no live status sync exists.
//
// (4) SYNC-5 numeric integrity — KDS card and OSS surface DO NOT render
//     the order TOTAL in DOM (verified by reading the components). Cross-
//     surface parity uses `order_serial_no` / `queue_number` identity:
//       - kiosk receipt total === orders.total (DB) ← T_kiosk_paid probe
//       - KDS card identity   === orders.queue_number (DOM N° pattern)
//       - OSS column entry    === orders.queue_number (DOM N° pattern)
//
// (5) SYNC-6 idempotency — uses the helper `placeKioskOrderTwice(page,
//     payload)` which posts the SAME idempotency key twice. Backend
//     dedupes via IdempotencyKeyMiddleware (header 'Idempotency-Replayed'
//     OR FrontendOrderService dedup against orders.idempotency_key
//     UNIQUE). Assertion: exactly 1 DB row sharing the key, both helper
//     calls return the same orderId. Scope query by captured key
//     (immune to parallel-agent contamination — Wave D-010 round-4).
//
// (6) Cleanup — placeKioskOrder hardcodes KIOSK_AUDIT_PREFIX
//     ('AUDIT-KIOSK-WAVE-E') for orders.token. We track captured order
//     IDs in `capturedOrderIds[]` and call `safeCancelOrder(id)` in the
//     `finally` block + `cleanupKioskAuditOrders(PREFIXE_AUDIT)`
//     belt-and-suspenders. Note: this prefix is shared with Wave E specs
//     (kiosk-order.js exports it as a constant — no per-call override).
//
// (7) Item — Frites Seules (id 361, 2.00€ TTC) — verified via tinker
//     2026-05-11. Same item Wave E uses; canonical kiosk POS catalog item.
//
// (8) ORDER_TYPE override to 10 (TAKEAWAY) — V1 ships dine-in disabled
//     (memory feedback_v1_dine_in_disabled_2026-05-06). Backend rejects
//     order_type=KIOSK(25) per OrderRequest.php enforcement; kiosk MUST
//     submit order_type=TAKEAWAY(10). kiosk-order.js defaults to 25 (it
//     predates the V1 lockdown); we override to 10 here.
//
// (9) Dev Pusher caveat — Pusher port 6001 unreachable in dev → KDS+OSS
//     pickup falls back to polling (~10-13s when disconnected). If
//     SYNC-1's 8s budget exceeds, we record the measured latency
//     truthfully and the adversarial reviewer flags as P1 dev-only known-
//     limitation (NOT P0). Hard assertions stay on: numeric integrity,
//     idempotency-1-order, source classification, DB state.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const {
  loginAsChefOperator,
  loginAsAdmin,
} = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  getKioskApiToken,
  placeKioskOrder,
  placeKioskOrderTwice,
  cleanupKioskAuditOrders,
  resetKioskToken,
  uuidV4,
  PAYMENT_CASH,
  KIOSK_AUDIT_PREFIX,
  prefixeAuditPourSpec,
} = require('./helpers/kiosk-order');

// [GOAL CONSOLIDATION T-4.2.1] Préfixe d'audit PROPRE à cette spec.
// Avant : huit specs écrivaient sous 'AUDIT-KIOSK-WAVE-E' et se nettoyaient
// mutuellement par LIKE. Dormant tant que playwright.config.js fixe workers:1,
// destructeur dès qu'on parallélise.
const PREFIXE_AUDIT = prefixeAuditPourSpec(__filename);

const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/test-e2e-kiosk-kds-sync-D'
);

// Mirror resources/js/enums/modules/orderStatusEnum.js.
const ORDER_STATUS = Object.freeze({
  PENDING: 1,
  ACCEPT: 4,
  PREPARING: 7,
  PREPARED: 8,
  DELIVERED: 13,
  CANCELED: 16,
});

// [FIX 2026-08-25] L'ID était figé à 361, « vérifié via tinker le 2026-05-11 ». Ce produit
// n'existe plus sous cet ID : la base ne contient AUCUNE ligne 361, et « Frites Seules » vit
// aujourd'hui sous l'id 2 (1,90 €). Le banc mourait donc au pré-vol, sans rapport avec le
// produit testé — et trois specs du dépôt codaient trois ID différents pour le MÊME article
// (361 ici, 2 dans goal-4chantiers et latency-cross-surface). Un identifiant figé dans un
// banc est une bombe à retardement : il survit exactement jusqu'au prochain re-seed.
//
// On résout donc l'article PAR SON NOM, branch-scopé et actif, avec repli sur l'ID historique
// et surcharge possible par variable d'environnement. C'est ce que le plan du cycle exigeait
// déjà (« résoudre dynamiquement l'article branch-scopé »).
const ITEM_FRITES_SEULES_NOM = process.env.ITEM_FRITES_SEULES_NOM || 'Frites Seules';
const ITEM_FRITES_SEULES_FALLBACK = Number(process.env.ITEM_FRITES_SEULES || 2);
let ITEM_FRITES_SEULES = ITEM_FRITES_SEULES_FALLBACK;

// V1 dine-in disabled — kiosk MUST use TAKEAWAY(10), not KIOSK(25).
const ORDER_TYPE_TAKEAWAY = 10;

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function parseLastJsonLine(output) {
  const lines = String(output)
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);
  const jsonLine = [...lines]
    .reverse()
    .find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON in artisan output:\n${output}`);
  }
  return JSON.parse(jsonLine);
}

function fetchOrderById(orderId) {
  try {
    const out = artisan(`
      $row = DB::table('orders')->where('id', ${Number(orderId)})
        ->first(['id','status','total','order_serial_no','queue_number','source','token','order_type','idempotency_key']);
      echo json_encode($row);
    `);
    return parseLastJsonLine(out);
  } catch (_e) {
    return null;
  }
}

// SYNC-6 — scope idempotency assertion to captured key (immune to
// parallel-agent DB contamination, per pos-kds-sync R3 commit 995b71ce).
function fetchOrdersByIdempotencyKey(key) {
  if (!key) return [];
  try {
    const safeKey = String(key).replace(/'/g, "\\'");
    const out = artisan(`
      $rows = DB::table('orders')->where('idempotency_key', '${safeKey}')
        ->orderBy('id')
        ->get(['id','total','status','token','source','queue_number','idempotency_key']);
      echo json_encode($rows);
    `);
    return parseLastJsonLine(out);
  } catch (_e) {
    return [];
  }
}

function safeCancelOrder(orderId) {
  if (!orderId) return;
  try {
    artisan(`
      $id = (int) ${Number(orderId)};
      $order = DB::table('orders')->where('id', $id)->first();
      if ($order && (int) $order->status !== ${ORDER_STATUS.CANCELED}
                 && (int) $order->status !== ${ORDER_STATUS.DELIVERED}) {
        DB::table('orders')->where('id', $id)->update([
          'status' => ${ORDER_STATUS.CANCELED},
          'updated_at' => now(),
        ]);
      }
    `);
  } catch (_e) {
    /* best-effort cleanup */
  }
}

// Pre-flight: kiosk machine seed + Frites Seules item must exist.
function verifyPreFlight() {
  const nom = ITEM_FRITES_SEULES_NOM.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  const out = artisan(`
    $m = \\App\\Models\\KioskMachine::withoutGlobalScopes()->where('id', 1)->first();
    // Résolution par NOM d'abord (l'ID figé dérive au re-seed), repli sur l'ID historique.
    // NB : \`items\` n'a pas de colonne \`branch_id\` — la disponibilité par branche vit dans
    // \`item_branch_availability\`. On ne filtre donc pas la branche ici ; le parcours vérifie
    // ensuite que l'article est bien commandable depuis la borne.
    // [CORRECTIF 2026-08-25] L'article doit etre ROUTE vers une station de cuisine.
    // Le tableau KDS filtre par station : un article kds_station = 'none' (c'est le cas de
    // « Frites Seules ») n'apparait sur AUCUNE station, donc jamais sur le board — la commande
    // etait pourtant bien creee en statut ACCEPT. On exige donc une vraie station, avec repli
    // sur le nom demande puis sur l'identifiant historique.
    $it = DB::table('items')
      ->where('name', '${nom}')
      ->where('status', 5)
      ->whereNull('deleted_at')
      ->whereNotNull('kds_station')
      ->where('kds_station', '!=', 'none')
      ->first(['id','name','price']);
    if (! $it) {
      $it = DB::table('items')
        ->where('status', 5)
        ->whereNull('deleted_at')
        ->whereNotNull('kds_station')
        ->where('kds_station', '!=', 'none')
        ->whereNotExists(function ($q) {
          $q->select(DB::raw(1))->from('item_variations')->whereColumn('item_variations.item_id', 'items.id');
        })
        ->orderBy('id')
        ->first(['id','name','price']);
    }
    if (! $it) {
      $it = DB::table('items')->where('id', ${ITEM_FRITES_SEULES_FALLBACK})->where('status', 5)->first(['id','name','price']);
    }
    echo json_encode([
      'machine' => $m ? ['id' => (int) $m->id, 'username' => (string) $m->username, 'branch_id' => (int) $m->branch_id, 'status' => (int) $m->status] : null,
      'item' => $it ? ['id' => (int) $it->id, 'name' => (string) $it->name, 'price' => (string) $it->price] : null,
    ]);
  `);
  return parseLastJsonLine(out);
}

// KDS chef advances status via the same backend endpoint the UI click
// would hit (no testid on the accordion buttons). Mirrors Wave D / E
// pattern. Returns { ok, status, error? } so the spec can log + retry.
async function kdsAdvanceStatus(chefPage, orderId, expectedStatus, nextStatus) {
  return chefPage.evaluate(
    async ({ orderId, expectedStatus, nextStatus }) => {
      try {
        // [GOAL CONSOLIDATION 2026-08-25] En-tête d'idempotence OBLIGATOIRE sur cette route.
        //
        // `config/idempotency.php:105` liste `api/admin/kds-order/change-status/*` dans
        // `required_routes`. Sans l'en-tête, le backend répond **422 « Header X-Idempotency-Key
        // requis pour cette opération »** — ce qui s'est produit ici sur state07 ET state09,
        // faisant échouer toute la chaîne aval (OSS preparing, OSS prepared).
        //
        // L'exigence est délibérée : un double bump enverrait deux notifications client. La
        // spec, elle, n'avait jamais été mise à jour après l'ajout de la route à la liste.
        // Clé unique par transition : un rejeu identique doit être déduit, pas rejoué.
        const cleIdempotence = `wave-d-kds-${orderId}-${nextStatus}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const response = await window.axios.post(
          `admin/kds-order/change-status/${orderId}`,
          {
            id: orderId,
            expected_status: expectedStatus,
            status: nextStatus,
          },
          { headers: { 'X-Idempotency-Key': cleIdempotence } }
        );
        window.dispatchEvent(
          new CustomEvent('realtime-order-update', {
            detail: { type: 'kiosk-kds-sync-d', order_id: orderId, status: nextStatus },
          })
        );
        return {
          ok: response.status >= 200 && response.status < 300,
          status: response.status,
        };
      } catch (err) {
        return {
          ok: false,
          status: err?.response?.status || 0,
          error: err?.response?.data?.message || err?.message || String(err),
        };
      }
    },
    { orderId, expectedStatus, nextStatus }
  );
}

test.describe('Kiosk · KDS · OSS sync audit Wave D — cross-surface lifecycle', () => {
  // 3 contexts × multi-step lifecycle + tinker probes — generous timeout.
  test.setTimeout(360_000);

  test.beforeAll(() => {
    // Pre-flight: kiosk machine seed + item must exist.
    const seed = verifyPreFlight();
    if (!seed || !seed.machine || seed.machine.status !== 5) {
      throw new Error(
        `Wave D pre-flight: kiosk machine seed missing or not ACTIVE. Got: ${JSON.stringify(seed?.machine)}. ` +
        `Run: php artisan db:seed --class=KioskMachineSeeder`
      );
    }
    if (!seed || !seed.item) {
      throw new Error(
        `Wave D pré-vol : aucun article actif nommé « ${ITEM_FRITES_SEULES_NOM} » sur la branche `
        + `de la borne, ni de repli actif sur l'id ${ITEM_FRITES_SEULES_FALLBACK}. `
        + `Reçu : ${JSON.stringify(seed?.item)}. Surcharge possible : ITEM_FRITES_SEULES_NOM / ITEM_FRITES_SEULES.`
      );
    }
    // L'ID réellement résolu pilote tout le reste du parcours.
    ITEM_FRITES_SEULES = Number(seed.item.id);
    console.log(`[Wave D] article résolu : #${ITEM_FRITES_SEULES} « ${seed.item.name} » @ ${seed.item.price}`);
    // Cleanup prior orphans sharing the kiosk audit prefix.
    try {
      const cleanup = cleanupKioskAuditOrders(PREFIXE_AUDIT);
      // eslint-disable-next-line no-console
      console.log(`[Wave D kiosk-kds-sync] beforeAll cleanup: ${JSON.stringify(cleanup)}`);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn(`[Wave D kiosk-kds-sync] cleanup warning: ${e?.message || e}`);
    }
    resetKioskToken();
  });

  test('Wave D : kiosk pay → KDS pile → OSS preparing → OSS prepared → idempotency', async ({
    browser,
  }) => {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    clearFoodKingRateLimits();

    // --------------------------------------------------------------------
    // Three isolated browser contexts. Cookie jars independent so logins
    // do not collide. Viewports differ to mirror real hardware.
    //   - kioskCtx : 1080x1920 portrait borne
    //   - kdsCtx   : 1440x900 wide kitchen display
    //   - ossCtx   : 1280x800 customer screen
    // --------------------------------------------------------------------
    const kioskCtx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const kdsCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const ossCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });

    const kioskPage = await kioskCtx.newPage();
    const kdsPage = await kdsCtx.newPage();
    const ossPage = await ossCtx.newPage();

    const kioskRec = attachMegaAuditRecorder(kioskPage, SCREENSHOT_DIR);
    const kdsRec = attachMegaAuditRecorder(kdsPage, SCREENSHOT_DIR);
    const ossRec = attachMegaAuditRecorder(ossPage, SCREENSHOT_DIR);

    const capturedOrderIds = [];
    const observations = [];
    const timings = {};

    try {
      // ================================================================
      // PHASE 1 — Baselines : all three surfaces parked on landing
      // States 01 / 02 / 03
      // ================================================================
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await kioskPage.waitForTimeout(2_500);
      await kioskRec.snap('01-d-kiosk-baseline');
      observations.push('state01: kiosk /kiosk/idle baseline captured');
      // [CORRECTIF 2026-08-25] Le « parking » de la page borne sur /admin/order-status-screen
      // est SUPPRIMÉ : il était la cause directe du 401 au stage « quote ».
      //
      // Pourquoi, avec la preuve : `resources/js/shared/axios-setup.js:97-98` installe un
      // intercepteur de requête qui ÉCRASE systématiquement l'en-tête —
      //     config.headers['Authorization'] = token ? `Bearer ${token}` : '';
      // — avec le jeton lu dans le store Vuex. Le Bearer explicite passé par
      // `placeKioskOrder` n'a donc JAMAIS d'effet sur une page où l'application est montée :
      // seul compte le jeton du store.
      //
      // Or `kioskCart` n'est pas dans les `paths` persistés (`resources/js/store/index.js`) :
      // quitter /kiosk détruit le jeton borne en mémoire. Sur la page garée, le store était
      // donc vide, `selectSurfaceBearerToken` retournait null, l'intercepteur envoyait
      // `Authorization: ''` — d'où le 401, quelle que soit la qualité du jeton émis à côté.
      //
      // Le parking soignait la révocation et provoquait une panne pire. On reste sur la
      // surface borne, comme Wave E et rush-sync qui passent, et on s'appuie sur la reprise
      // bornée ci-dessous pour absorber la révocation par auto-login.
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await kioskPage.waitForTimeout(3_000);

      await loginAsChefOperator(kdsPage);
      await expect(kdsPage).toHaveURL(
        /\/(kds|admin\/kitchen-display-system)/,
        { timeout: 25_000 }
      );
      await kdsPage.waitForTimeout(2_500);
      await kdsRec.snap('02-d-kds-baseline');
      observations.push('state02: KDS baseline captured');

      await loginAsAdmin(ossPage);
      await ossPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await ossPage.waitForURL(/\/admin\/order-status-screen/, { timeout: 25_000 }).catch(() => {});
      await ossPage.waitForTimeout(2_500);
      await ossRec.snap('03-d-oss-baseline');
      observations.push('state03: OSS /admin/order-status-screen baseline captured');

      // ================================================================
      // PHASE 2 — Token issuance + kiosk order placement (cash, kiosk lane)
      // State 04 — kiosk UI captured + DB write evidence
      // ================================================================
      let token;
      // Force a fresh Sanctum issuance — any cached token from prior test
      // runs / parallel agents may already be revoked by the SPA auto-login.
      // [CORRECTIF 2026-08-25] Le contournement « émettre depuis Node » date du 2026-05-11 et
      // n'est PLUS valide. Depuis le scopage multi-appareils du 2026-08-07, l'identité
      // d'appareil n'est pas prise dans un en-tête du client : elle est DÉRIVÉE DE LA MACHINE
      // (`KioskMachineLoginController` → `issueForDevice(..., 'kiosk-'.$kioskId)`). Le jeton
      // émis depuis Node et celui émis par la borne portent donc le MÊME `device_id`, et
      // `DeviceTokenService` supprime le précédent jeton de ce device (ligne 127-128). Passer
      // par Node ne contourne plus rien : ça garantit au contraire de se faire révoquer par
      // l'auto-login de la borne, d'où le 401 au stage « quote ».
      //
      // Mesuré le 2026-08-25 : jeton #10711 émis depuis Node, supprimé, remplacé par #10713
      // émis par la borne — puis 401 sur le devis. Wave E et rush-sync, qui passent, utilisent
      // tous deux la voie navigateur. On s'aligne sur elles : un seul émetteur par appareil.
      resetKioskToken();
      try {
        token = await getKioskApiToken(kioskPage);
      } catch (e) {
        throw new Error(
          `Wave D: kiosk Sanctum token issuance failed: ${e?.message || e}. ` +
          `Verify routes/api.php POST /api/auth/kiosk-login is reachable and ` +
          `KioskMachine id=1 password 'kiosk123' matches the seed.`
        );
      }
      observations.push(`state04: kiosk token issued (${token.substring(0, 10)}...) — Sanctum kiosk:order ability`);

      const itemsPayload = [
        {
          item_id: ITEM_FRITES_SEULES,
          quantity: 1,
          item_variations: [],
          item_extras: [],
          item_addons: [],
        },
      ];

      const t0 = Date.now();
      let placement;
      // [CORRECTIF 2026-08-25] Reprise bornée sur 401.
      //
      // Une borne n'a QU'UN jeton : `device_id` est dérivé de la machine, donc toute nouvelle
      // émission révoque la précédente (`DeviceTokenService` ligne 127-128). Le SPA de la borne
      // relance son auto-login dès qu'il voit un 401, ce qui révoque le jeton du banc — et le
      // banc, en réémettant, révoque celui du SPA. Les deux se volent le jeton à tour de rôle.
      // Aucune des deux voies (Node ou navigateur) n'échappe à cette course.
      //
      // On fait donc ce que fait une vraie borne face à un 401 : réémettre et rejouer, un nombre
      // BORNÉ de fois. Le devis est idempotent côté serveur, donc rejouer ne crée pas de
      // commande fantôme — et si les trois tentatives échouent, on remonte l'échec tel quel
      // plutôt que de le maquiller.
      const MAX_TENTATIVES_JETON = 3;
      let derniereErreur = null;
      for (let tentative = 1; tentative <= MAX_TENTATIVES_JETON; tentative += 1) {
        try {
          placement = await placeKioskOrder(kioskPage, {
            tokenPrefix: PREFIXE_AUDIT,
            items: itemsPayload,
            paymentMethod: PAYMENT_CASH,
            orderType: ORDER_TYPE_TAKEAWAY,
            // Cash kiosk = pay-at-counter; status lands ACCEPT immediately on
            // store, payment-confirm endpoint is CARD/TR-only. Skipping is
            // the correct path — the order surfaces on KDS via status=ACCEPT.
            skipPaymentConfirm: true,
          });
          derniereErreur = null;
          break;
        } catch (e) {
          derniereErreur = e;
          const est401 = /HTTP 401|Unauthenticated/i.test(String(e?.message || e));
          observations.push(
            `state04: placeKioskOrder tentative ${tentative}/${MAX_TENTATIVES_JETON} échouée`
            + `${est401 ? ' (401 — jeton révoqué par l’auto-login borne)' : ''}: ${e?.message || e}`
          );
          if (!est401 || tentative === MAX_TENTATIVES_JETON) break;
          // Laisser l'auto-login de la borne se stabiliser, puis reprendre SON jeton.
          resetKioskToken();
          await kioskPage.waitForTimeout(3_000);
          token = await getKioskApiToken(kioskPage);
        }
      }
      if (derniereErreur) {
        await kioskRec.snap('04-d-kiosk-pay-FAIL');
        throw derniereErreur;
      }
      timings.kiosk_pay_to_response_ms = Date.now() - t0;
      capturedOrderIds.push(placement.orderId);

      observations.push(
        `state04: kiosk order placed orderId=${placement.orderId} ` +
        `serial=${placement.orderSerialNo} queue=${placement.queueNumber} ` +
        `total=${placement.totalAmount}€ idem=${placement.idempotencyKey} ` +
        `replayed=${placement.replayed} latency_ms=${timings.kiosk_pay_to_response_ms}`
      );

      // SYNC-5 numeric integrity leg 1 : helper-returned total === DB total.
      const dbOrder = fetchOrderById(placement.orderId);
      if (dbOrder) {
        const dbTotal = parseFloat(dbOrder.total);
        observations.push(
          `state04: DB probe order_id=${dbOrder.id} db_total=${dbTotal} ` +
          `source=${dbOrder.source} order_type=${dbOrder.order_type} ` +
          `status=${dbOrder.status} queue=${dbOrder.queue_number}`
        );
        expect(
          Math.abs(placement.totalAmount - dbTotal) < 0.01,
          `SYNC-5 leg 1: T_kiosk_paid (${placement.totalAmount}) must equal orders.total (${dbTotal})`
        ).toBe(true);
        // Source must be SOURCE_KIOSK(5) — kiosk lane assertions downstream.
        expect(
          Number(dbOrder.source),
          `SYNC-1: DB source must be SOURCE_KIOSK (5) for kiosk-placed order; got ${dbOrder.source}`
        ).toBe(5);
      } else {
        observations.push(`state04: WARN DB probe returned null for order_id=${placement.orderId}`);
      }

      await kioskPage.waitForTimeout(800);
      await kioskRec.snap('04-d-kiosk-order-placed');

      // ================================================================
      // PHASE 3 — KDS must show the kiosk card within 8s (SYNC-1)
      // State 05 — assert + capture
      // ================================================================
      const t1 = Date.now();
      let kdsPickedUp = false;
      try {
        await kdsPage.waitForFunction(
          (orderId) => {
            const cards = Array.from(
              document.querySelectorAll('[data-kds-order-card]')
            );
            return cards.some((c) => {
              const hdr = c.querySelector(`[id^="order-${orderId}-"]`);
              if (hdr) return true;
              const text = c.textContent || '';
              return text.includes('#' + orderId) || text.includes('N°' + orderId);
            });
          },
          placement.orderId,
          { timeout: 8_000 }
        );
        kdsPickedUp = true;
      } catch (_e) {
        observations.push(
          'state05: SYNC-1 REALTIME 8s BUDGET EXCEEDED — KDS did not pick up kiosk card. ' +
          'Pusher unreachable in dev (port 6001 down); KDS polling fallback ~10-13s when disconnected. ' +
          'Fallback reload to capture useful PNG (does NOT count toward 8s budget).'
        );
        try {
          await kdsPage.reload({ waitUntil: 'domcontentloaded' });
          await kdsPage.waitForTimeout(4_000);
          const reloadHasCard = await kdsPage.evaluate((orderId) => {
            const cards = Array.from(
              document.querySelectorAll('[data-kds-order-card]')
            );
            return cards.some((c) => {
              const hdr = c.querySelector(`[id^="order-${orderId}-"]`);
              if (hdr) return true;
              const text = c.textContent || '';
              return text.includes('#' + orderId) || text.includes('N°' + orderId);
            });
          }, placement.orderId);
          observations.push(`state05: post-reload KDS card present=${reloadHasCard}`);
        } catch (_e2) { /* ignore reload error */ }
      }
      timings.sync1_kiosk_pay_to_kds_ms = Date.now() - t1;
      observations.push(
        `state05: SYNC-1 kdsPickedUp=${kdsPickedUp} latency_ms=${timings.sync1_kiosk_pay_to_kds_ms} ` +
        `(measured from immediately-after-placeKioskOrder resolve)`
      );
      // SYNC-1 source-isolation : assert the card lands in the kiosk lane.
      // [FIX 2026-08-25] Attente BORNÉE au lieu d'un relevé instantané.
      //
      // Ce contrôle était un unique `evaluate` exécuté juste après la vérification de réception,
      // sans la moindre attente : il courait contre le rendu du tableau KDS et rendait `false`
      // pour une carte qui arrivait une seconde plus tard. La propriété à prouver n'est pas
      // « la carte est là À CET INSTANT » mais « elle atterrit bien dans la file BORNE ».
      //
      // Vérifié en base pour cette commande : `source_surface = 'kiosk'` est correctement
      // renseigné et `SimpleOrderResource:63` le sérialise — le produit classe donc bien. On
      // sonde jusqu'à 15 s, ce qui reste une exigence stricte pour un tableau de cuisine.
      let kdsKioskLanePresent = false;
      const limiteIsolation = Date.now() + 15_000;
      while (Date.now() < limiteIsolation && !kdsKioskLanePresent) {
        try {
          kdsKioskLanePresent = await kdsPage.evaluate((orderId) => {
            const cards = Array.from(
              document.querySelectorAll('[data-kds-order-card="kiosk"]')
            );
            return cards.some((c) => {
              const hdr = c.querySelector(`[id^="order-${orderId}-"]`);
              if (hdr) return true;
              const text = c.textContent || '';
              return text.includes('#' + orderId) || text.includes('N°' + orderId);
            });
          }, placement.orderId);
        } catch (_e) { /* le tableau peut se re-rendre pendant la sonde */ }
        if (!kdsKioskLanePresent) await kdsPage.waitForTimeout(1_000);
      }
      observations.push(`state05: SYNC-1 kiosk-lane source-isolation present=${kdsKioskLanePresent}`);
      expect.soft(
        kdsKioskLanePresent,
        `SYNC-1 source-isolation: KDS card should land under [data-kds-order-card="kiosk"] for kiosk-placed order ${placement.orderId}`
      ).toBe(true);
      await kdsRec.snap(
        kdsPickedUp ? '05-d-kds-after-kiosk-pay-within-8s' : '05-d-kds-after-kiosk-pay-DEBUG'
      );

      // ================================================================
      // PHASE 4 — SYNC-3 (downgraded) : navigate kiosk to /kiosk/confirmation
      // State 06 — capture confirmation static render
      //
      // KioskConfirmationComponent.vue does NOT subscribe to Echo — the
      // displayNumber/displayTotal come from props (route.query) and are
      // snap-frozen at mount. mounted() immediately resets the cart and
      // starts a 30s countdown to /kiosk/idle. There is NO live status
      // reflection on the customer-facing screen by design.
      //
      // We exercise the static render path: navigate manually to
      // /kiosk/confirmation?orderNumber=N&orderTotal=T and verify the
      // confirmation testids render. No live status assertion.
      // ================================================================
      // Route uses query keys `number` + `total` (NOT orderNumber/orderTotal,
      // verified via kioskRoutes.js l.225-226). The route guard
      // `requireConfirmationContext` (l.102-108) ALSO requires
      // `store.state.kioskCart.orderRef` to be set — otherwise it redirects
      // to /kiosk/idle. We inject orderRef directly into the Vuex store
      // before navigation so the guard passes (we cannot drive the wizard
      // from API placements). This exercises the same render path a real
      // kiosk customer would see post-pay.
      const confirmTotal = placement.totalAmount;
      const confirmDisplay =
        placement.queueNumber && !Number.isNaN(Number(placement.queueNumber))
          ? placement.queueNumber
          : (dbOrder?.queue_number || placement.orderId);
      const confirmUrl =
        `/kiosk/confirmation?number=${encodeURIComponent(confirmDisplay)}` +
        `&total=${encodeURIComponent(confirmTotal)}`;
      let kioskConfirmRendered = false;
      let kioskConfirmDisplayedNumber = null;
      let kioskConfirmDisplayedTotal = null;
      try {
        // Navigate kiosk page back to /kiosk/idle (it was parked on OSS
        // during PHASE 2 to avoid auto-login token revocation). Then use
        // router.push to /kiosk/confirmation, injecting orderRef into the
        // Vuex store so the guard requireConfirmationContext passes.
        await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
        await kioskPage.waitForTimeout(2_500);
        const navOk = await kioskPage.evaluate(
          async ({ orderId, queueNumber, orderTotal }) => {
            try {
              const store =
                window.__store__ ||
                window.store ||
                window.app?.config?.globalProperties?.$store ||
                null;
              const router =
                window.__router__ ||
                window.router ||
                window.app?.config?.globalProperties?.$router ||
                null;
              if (!store || !router) {
                return { ok: false, reason: `no store/router (store=${!!store} router=${!!router})` };
              }
              if (store.state?.kioskCart) {
                store.state.kioskCart.orderRef = String(orderId);
                store.state.kioskCart.queueNumber = queueNumber;
                store.state.kioskCart.lastOrderId = orderId;
              }
              try {
                await router.push({
                  name: 'kiosk.confirmation',
                  query: { number: String(queueNumber), total: String(orderTotal) },
                });
                return { ok: true, reason: 'router.push resolved' };
              } catch (e) {
                return { ok: false, reason: 'router.push rejected: ' + (e?.message || String(e)) };
              }
            } catch (e) {
              return { ok: false, reason: 'eval threw: ' + (e?.message || String(e)) };
            }
          },
          {
            orderId: placement.orderId,
            queueNumber: confirmDisplay,
            orderTotal: confirmTotal,
          }
        );
        observations.push(`state06: SYNC-3 router.push attempt = ${JSON.stringify(navOk)}`);
        // Fall back to direct goto if router.push not reachable (no store).
        if (!navOk?.ok) {
          await kioskPage.goto(confirmUrl, { waitUntil: 'domcontentloaded' });
        }
        await kioskPage.waitForSelector('[data-testid="kiosk-confirmation-root"]', {
          timeout: 8_000,
        });
        kioskConfirmRendered = true;
        kioskConfirmDisplayedNumber = await kioskPage
          .locator('[data-testid="kiosk-confirmation-number"]')
          .innerText()
          .catch(() => null);
        kioskConfirmDisplayedTotal = await kioskPage
          .locator('[data-testid="kiosk-confirmation-total"]')
          .innerText()
          .catch(() => null);
      } catch (_e) {
        const finalUrl = await kioskPage.url();
        observations.push(
          'state06: SYNC-3 confirmation render FAILED — kiosk-confirmation-root selector not visible within 8s. ' +
          'Route guard requireConfirmationContext likely redirected to /kiosk/idle ' +
          '(programmatic orderRef injection into Vuex store did not survive the route transition). ' +
          'Final URL: ' + finalUrl + '. ' +
          'EVIDENCE: KioskConfirmationComponent.vue has NO Echo / live-status subscription ' +
          '(verified by reading source 2026-05-11) — displayNumber/displayTotal come from ' +
          'route.query props snap-frozen at mount; mounted() resets cart and starts 30s ' +
          'countdown to /kiosk/idle. SYNC-3 is therefore architecturally STATIC by design.'
        );
      }
      observations.push(
        `state06: SYNC-3 (DOWNGRADED — no live Echo subscription on kiosk confirmation page by design) ` +
        `rendered=${kioskConfirmRendered} number_text="${kioskConfirmDisplayedNumber}" ` +
        `total_text="${kioskConfirmDisplayedTotal}"`
      );
      // SYNC-5 leg 2 : kiosk confirmation displayed total === T_kiosk_paid.
      if (kioskConfirmDisplayedTotal) {
        const m = kioskConfirmDisplayedTotal.match(/(\d+(?:[.,]\d+)?)/);
        const displayedTotal = m ? parseFloat(m[1].replace(',', '.')) : null;
        if (displayedTotal !== null) {
          expect.soft(
            Math.abs(placement.totalAmount - displayedTotal) < 0.01,
            `SYNC-5 leg 2: kiosk confirmation displayed total (${displayedTotal}) should equal T_kiosk_paid (${placement.totalAmount})`
          ).toBe(true);
        }
      }
      await kioskRec.snap('06-d-kiosk-confirmation-static-render');

      // ================================================================
      // PHASE 5 — KDS chef advances ACCEPT → PREPARING (state 07)
      // OSS only renders PREPARING+PREPARED, so this is when SYNC-2 starts
      // measuring : KDS PREPARING transition → OSS preparing column.
      // ================================================================
      clearFoodKingRateLimits();
      let inProgressResult = null;
      const tInProgressTransition = Date.now();
      const fresh1 = fetchOrderById(placement.orderId);
      const expectedFromAccept = fresh1 ? Number(fresh1.status) : ORDER_STATUS.ACCEPT;
      const r1 = await kdsAdvanceStatus(
        kdsPage,
        placement.orderId,
        expectedFromAccept,
        ORDER_STATUS.PREPARING
      );
      inProgressResult = r1;
      observations.push(
        `state07: KDS→PREPARING expected=${expectedFromAccept} result=${JSON.stringify(r1)}`
      );
      await kdsPage.waitForTimeout(2_000);
      if (r1 && r1.status === 429) {
        await kdsPage.waitForTimeout(1_500);
        clearFoodKingRateLimits();
        const r1b = await kdsAdvanceStatus(
          kdsPage,
          placement.orderId,
          expectedFromAccept,
          ORDER_STATUS.PREPARING
        );
        inProgressResult = r1b;
        observations.push(`state07: retry result=${JSON.stringify(r1b)}`);
        await kdsPage.waitForTimeout(2_000);
      }
      // Force fresh DOM read before snap — in dev with Pusher down, KDS SPA
      // may not re-render until the next polling tick.
      await kdsPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await kdsPage.waitForTimeout(1_500);
      await kdsRec.snap('07-d-kds-mark-preparing');

      // ================================================================
      // PHASE 6 — OSS preparing column reflects within 5s (SYNC-2)
      // State 08 — assert OSS shows the order in preparing column
      //
      // OSS preparing column = PreparingAndReadyComponent.vue first
      // <transition-group tag="ul"> with li keyed by order.id rendering
      // `N°${queue_number}` or `${token}`.
      // ================================================================
      const t2 = Date.now();
      let ossPreparingPickedUp = false;
      try {
        await ossPage.waitForFunction(
          ({ queueNumber, token, orderId }) => {
            const lis = Array.from(document.querySelectorAll('li'));
            return lis.some((li) => {
              const text = (li.textContent || '').trim();
              if (queueNumber && text.includes(`N°${queueNumber}`)) return true;
              if (token && text === token) return true;
              if (orderId && text.includes(`#${orderId}`)) return true;
              return false;
            });
          },
          {
            queueNumber: placement.queueNumber,
            token: dbOrder ? dbOrder.token : null,
            orderId: placement.orderId,
          },
          { timeout: 5_000 }
        );
        ossPreparingPickedUp = true;
      } catch (_e) {
        observations.push(
          'state08: SYNC-2 REALTIME 5s BUDGET EXCEEDED — OSS preparing column did not pick up the order. ' +
          'Echo unreachable in dev → OSS falls back to ossSyncService polling. Fallback reload to capture PNG.'
        );
        try {
          await ossPage.reload({ waitUntil: 'domcontentloaded' });
          await ossPage.waitForTimeout(3_000);
          const reloadFound = await ossPage.evaluate(
            ({ queueNumber, token, orderId }) => {
              const lis = Array.from(document.querySelectorAll('li'));
              return lis.some((li) => {
                const text = (li.textContent || '').trim();
                if (queueNumber && text.includes(`N°${queueNumber}`)) return true;
                if (token && text === token) return true;
                if (orderId && text.includes(`#${orderId}`)) return true;
                return false;
              });
            },
            {
              queueNumber: placement.queueNumber,
              token: dbOrder ? dbOrder.token : null,
              orderId: placement.orderId,
            }
          );
          observations.push(`state08: post-reload OSS preparing-col found=${reloadFound}`);
        } catch (_e2) { /* ignore */ }
      }
      timings.sync2_kds_preparing_to_oss_ms = Date.now() - t2;
      observations.push(
        `state08: SYNC-2 (REFRAMED — measured from KDS→PREPARING transition, not kiosk pay; ` +
        `OSS only renders PREPARING+PREPARED by design) ` +
        `ossPreparingPickedUp=${ossPreparingPickedUp} latency_ms=${timings.sync2_kds_preparing_to_oss_ms}`
      );
      await ossRec.snap(
        ossPreparingPickedUp ? '08-d-oss-preparing-column-within-5s' : '08-d-oss-preparing-column-DEBUG'
      );

      // ================================================================
      // PHASE 7 — KDS chef advances PREPARING → PREPARED (state 09)
      // ================================================================
      clearFoodKingRateLimits();
      const tReadyTransition = Date.now();
      const fresh2 = fetchOrderById(placement.orderId);
      const expectedFromPreparing = fresh2 ? Number(fresh2.status) : ORDER_STATUS.PREPARING;
      const r2 = await kdsAdvanceStatus(
        kdsPage,
        placement.orderId,
        expectedFromPreparing,
        ORDER_STATUS.PREPARED
      );
      observations.push(
        `state09: KDS→PREPARED expected=${expectedFromPreparing} result=${JSON.stringify(r2)}`
      );
      await kdsPage.waitForTimeout(2_000);
      if (r2 && r2.status === 429) {
        await kdsPage.waitForTimeout(1_500);
        clearFoodKingRateLimits();
        const r2b = await kdsAdvanceStatus(
          kdsPage,
          placement.orderId,
          expectedFromPreparing,
          ORDER_STATUS.PREPARED
        );
        observations.push(`state09: retry result=${JSON.stringify(r2b)}`);
        await kdsPage.waitForTimeout(2_000);
      }
      await kdsPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await kdsPage.waitForTimeout(1_500);
      await kdsRec.snap('09-d-kds-mark-prepared');

      // ================================================================
      // PHASE 8 — OSS prepared column reflects within 5s (SYNC-4)
      // State 10 — assert OSS prepared column (green pop-in animation)
      //
      // PreparingAndReadyComponent.vue second transition-group renders
      // ready items with class text-[#2AC769] font-extrabold + oss-new-ready
      // when freshly bumped. Match by `N°${queue_number}` or token.
      // ================================================================
      const t4 = Date.now();
      let ossPreparedPickedUp = false;
      try {
        await ossPage.waitForFunction(
          ({ queueNumber, token, orderId }) => {
            // The PRÊT (ready) col items carry class text-[#2AC769].
            const greenLis = Array.from(
              document.querySelectorAll('li.text-\\[\\#2AC769\\], li.oss-new-ready')
            );
            const allLis = Array.from(document.querySelectorAll('li'));
            const candidates = greenLis.length ? greenLis : allLis;
            return candidates.some((li) => {
              const text = (li.textContent || '').trim();
              if (queueNumber && text.includes(`N°${queueNumber}`)) return true;
              if (token && text === token) return true;
              if (orderId && text.includes(`#${orderId}`)) return true;
              return false;
            });
          },
          {
            queueNumber: placement.queueNumber,
            token: dbOrder ? dbOrder.token : null,
            orderId: placement.orderId,
          },
          { timeout: 5_000 }
        );
        ossPreparedPickedUp = true;
      } catch (_e) {
        observations.push(
          'state10: SYNC-4 REALTIME 5s BUDGET EXCEEDED — OSS prepared column did not pick up the order. ' +
          'Echo unreachable in dev. Fallback reload to capture PNG.'
        );
        try {
          await ossPage.reload({ waitUntil: 'domcontentloaded' });
          await ossPage.waitForTimeout(3_000);
        } catch (_e2) { /* ignore */ }
      }
      timings.sync4_kds_prepared_to_oss_ms = Date.now() - t4;
      observations.push(
        `state10: SYNC-4 ossPreparedPickedUp=${ossPreparedPickedUp} latency_ms=${timings.sync4_kds_prepared_to_oss_ms}`
      );
      await ossRec.snap(
        ossPreparedPickedUp ? '10-d-oss-prepared-column-within-5s' : '10-d-oss-prepared-column-DEBUG'
      );

      // SYNC-5 leg 3 : queue_number identity across DB ↔ KDS card ↔ OSS li.
      // We've already asserted T_kiosk_paid === DB.total in PHASE 2.
      // Here verify the queue_number propagated identically.
      const finalDb = fetchOrderById(placement.orderId);
      const dbQueue = finalDb ? finalDb.queue_number : null;
      const kdsQueueText = await kdsPage.evaluate((orderId) => {
        const cards = Array.from(document.querySelectorAll('[data-kds-order-card]'));
        for (const c of cards) {
          const hdr = c.querySelector(`[id^="order-${orderId}-"]`);
          if (hdr) {
            const txt = c.textContent || '';
            const m = txt.match(/N°\s*(\w+)/);
            if (m) return m[1];
          }
        }
        return null;
      }, placement.orderId).catch(() => null);
      const ossQueueText = await ossPage.evaluate((queueNumber) => {
        if (!queueNumber) return null;
        const lis = Array.from(document.querySelectorAll('li'));
        const found = lis.find((li) => (li.textContent || '').includes(`N°${queueNumber}`));
        return found ? (found.textContent || '').trim() : null;
      }, placement.queueNumber).catch(() => null);
      observations.push(
        `state10: SYNC-5 queue_identity db_queue="${dbQueue}" kds_queue_text="${kdsQueueText}" oss_queue_text="${ossQueueText}" placement_queue="${placement.queueNumber}"`
      );
      // Soft-assert : at least one cross-surface confirmation of the queue number.
      if (placement.queueNumber && (kdsQueueText || ossQueueText)) {
        expect.soft(
          (kdsQueueText && String(kdsQueueText).includes(String(placement.queueNumber))) ||
            (ossQueueText && String(ossQueueText).includes(String(placement.queueNumber))),
          `SYNC-5: queue_number ${placement.queueNumber} should appear on at least one of KDS/OSS surfaces`
        ).toBeTruthy();
      }

      // ================================================================
      // PHASE 9 — SYNC-6 idempotency : same key + IDENTICAL body twice
      // State 11 — assert exactly 1 DB row + replay returns identical body
      //
      // NOTE on helper limitation : kiosk-order.js placeKioskOrderTwice is
      // BROKEN when IdempotencyKeyMiddleware is enabled (default in this
      // env, verified via `config('idempotency.enabled')`=true). Each call
      // to placeKioskOrder generates a FRESH orderToken
      // (`AUDIT-KIOSK-WAVE-E-${Date.now()}-${random}`) which becomes part
      // of the request body, so the second call's payload hash mismatches
      // the cached one and the middleware (correctly) returns 409
      // IDEMPOTENCY_KEY_CONFLICT. To exercise the actual replay path, we
      // bypass the helper and POST the SAME bytes twice ourselves via
      // page.evaluate (mirroring what the in-browser kiosk SPA would do
      // on a true network double-tap : the user clicks pay, the SPA fires
      // one POST, the request stalls, the SPA debounce fails / user
      // re-tabs and the SAME serialized body retransmits).
      // ================================================================
      const idemKey = uuidV4();
      // Build the EXACT body once, reuse for both POSTs (identical payload
      // hash → middleware enters the replay branch on the 2nd call).
      const idemBranchId = dbOrder ? Number(dbOrder.source) === 5 ? 1 : 1 : 1;
      const idemOrderToken = `${KIOSK_AUDIT_PREFIX}-SYNC6-${Date.now()}`;
      const idemItems = JSON.stringify([
        {
          item_id: ITEM_FRITES_SEULES,
          quantity: 1,
          item_variations: [],
          item_extras: [],
          item_addons: [],
        },
      ]);
      let idemFirst = null;
      let idemSecond = null;
      let idemRows = [];
      let idemDistinctIds = [];
      let idemError = null;
      try {
        const idemResult = await kioskPage.evaluate(
          async ({ bearer, idemKey, idemOrderToken, idemItems, branchId, orderType }) => {
            const authHeader = { Authorization: `Bearer ${bearer}` };
            const basePayload = {
              branch_id: branchId,
              token: idemOrderToken,
              discount: 0,
              order_type: orderType,
              is_advance_order: 10,
              source: 5,
              payment_method: 1, // PAYMENT_CASH
              items: idemItems,
            };
            // 1. Quote — used to build the signed totals for store.
            let quote;
            try {
              const quoteResp = await window.axios.post(
                'frontend/order/quote',
                basePayload,
                { headers: { ...authHeader } }
              );
              quote = quoteResp.data?.data || quoteResp.data;
            } catch (err) {
              return {
                ok: false,
                stage: 'quote',
                status: err?.response?.status || 0,
                data: err?.response?.data || { message: String(err?.message || err) },
              };
            }
            const storeBody = {
              ...basePayload,
              quote_token: quote.quote_token,
              quote_signature: quote.signature,
              subtotal: quote.subtotal,
              discount: quote.discount,
              delivery_charge: quote.delivery_charge,
              total: quote.total_ttc,
            };
            // 2. POST #1 — stores cached response under (key, body-hash).
            let resp1, resp2;
            try {
              resp1 = await window.axios.post('frontend/order', storeBody, {
                headers: { ...authHeader, 'X-Idempotency-Key': idemKey },
              });
            } catch (err) {
              return {
                ok: false,
                stage: 'store-1',
                status: err?.response?.status || 0,
                data: err?.response?.data || { message: String(err?.message || err) },
                headers1: err?.response?.headers || null,
              };
            }
            // 3. POST #2 — IDENTICAL body bytes + IDENTICAL key → middleware
            //    returns the cached response with Idempotency-Replayed: true.
            try {
              resp2 = await window.axios.post('frontend/order', storeBody, {
                headers: { ...authHeader, 'X-Idempotency-Key': idemKey },
              });
            } catch (err) {
              return {
                ok: false,
                stage: 'store-2',
                status: err?.response?.status || 0,
                data: err?.response?.data || { message: String(err?.message || err) },
                first: resp1.data?.data || resp1.data,
                headers1: resp1.headers || null,
              };
            }
            const replayed1 =
              String(
                resp1.headers?.['idempotency-replayed'] ||
                  resp1.headers?.['Idempotency-Replayed'] ||
                  ''
              ).toLowerCase() === 'true';
            const replayed2 =
              String(
                resp2.headers?.['idempotency-replayed'] ||
                  resp2.headers?.['Idempotency-Replayed'] ||
                  ''
              ).toLowerCase() === 'true';
            return {
              ok: true,
              first: resp1.data?.data || resp1.data,
              second: resp2.data?.data || resp2.data,
              firstStatus: resp1.status,
              secondStatus: resp2.status,
              replayed1,
              replayed2,
              headers1: resp1.headers || null,
              headers2: resp2.headers || null,
            };
          },
          {
            bearer: token,
            idemKey,
            idemOrderToken,
            idemItems,
            branchId: idemBranchId,
            orderType: ORDER_TYPE_TAKEAWAY,
          }
        );
        if (idemResult.ok) {
          idemFirst = {
            orderId: Number(idemResult.first?.id ?? 0),
            total: Number(idemResult.first?.total ?? 0),
            replayed: Boolean(idemResult.replayed1),
            status: idemResult.firstStatus,
          };
          idemSecond = {
            orderId: Number(idemResult.second?.id ?? 0),
            total: Number(idemResult.second?.total ?? 0),
            replayed: Boolean(idemResult.replayed2),
            status: idemResult.secondStatus,
          };
          if (idemFirst.orderId) capturedOrderIds.push(idemFirst.orderId);
          if (idemSecond.orderId && idemSecond.orderId !== idemFirst.orderId) {
            capturedOrderIds.push(idemSecond.orderId);
          }
          idemRows = fetchOrdersByIdempotencyKey(idemKey) || [];
          idemDistinctIds = Array.from(new Set(idemRows.map((r) => r.id)));
        } else {
          idemError = `stage=${idemResult.stage} status=${idemResult.status} body=${JSON.stringify(idemResult.data)}`;
        }
      } catch (e) {
        idemError = e?.message || String(e);
      }
      observations.push(
        `state11: SYNC-6 idempotency idem_key=${idemKey} order_token=${idemOrderToken} ` +
        `first_orderId=${idemFirst?.orderId} first_replayed=${idemFirst?.replayed} first_status=${idemFirst?.status} ` +
        `second_orderId=${idemSecond?.orderId} second_replayed=${idemSecond?.replayed} second_status=${idemSecond?.status} ` +
        `db_rows_count=${idemRows.length} distinct_order_ids=${JSON.stringify(idemDistinctIds)} ` +
        `error=${idemError}`
      );
      // Snap kiosk page first so reviewers see context even if assertion fails.
      await kioskRec.snap('11-d-kiosk-idempotency-double-post');

      // P0 — exactly 1 DB row shared the key (no duplicate order created).
      if (!idemError) {
        expect(
          idemDistinctIds.length,
          `SYNC-6 P0: exactly 1 distinct order in DB for idempotency_key=${idemKey} ` +
          `(got ${idemDistinctIds.length}: ${JSON.stringify(idemDistinctIds)})`
        ).toBe(1);
        // Same orderId returned across both posts.
        expect(
          idemSecond.orderId,
          `SYNC-6 P0: replay returns same orderId (first=${idemFirst.orderId} second=${idemSecond.orderId})`
        ).toBe(idemFirst.orderId);
        // P1 — `Idempotency-Replayed: true` header on 2nd POST. Middleware
        // is verified enabled in this env, so we hard-assert.
        expect.soft(
          idemSecond.replayed === true,
          `SYNC-6 P1: 2nd POST should surface Idempotency-Replayed: true header (got ${idemSecond.replayed}); ` +
          `middleware is verified config('idempotency.enabled')=true.`
        ).toBe(true);
      }

      // ================================================================
      // PHASE 10 — Silent-error sweep across all 3 surfaces
      // States 12 / 13 / 14 — re-snap each surface to flush network buffer
      // ================================================================
      await kioskRec.snap('12-d-kiosk-network-sweep');
      await kdsRec.snap('13-d-kds-network-sweep');
      await ossRec.snap('14-d-oss-network-sweep');

      // Walk SCREENSHOT_DIR network.json files. Allowlist:
      //   304 (cache), 401 (auth probes), 422 (form validation),
      //   409 (idempotency conflict — that's the expected dedupe response),
      //   429 on /change-status (already retried).
      const swept = [];
      const networkFiles = fs
        .readdirSync(SCREENSHOT_DIR)
        .filter((f) => f.endsWith('.network.json'));
      for (const nf of networkFiles) {
        try {
          const arr = JSON.parse(
            fs.readFileSync(path.join(SCREENSHOT_DIR, nf), 'utf8')
          );
          for (const entry of arr) {
            if (
              entry.status >= 400 &&
              entry.status !== 304 &&
              entry.status !== 401 &&
              entry.status !== 422 &&
              entry.status !== 409
            ) {
              if (
                entry.status === 429 &&
                /change-status/.test(entry.url || '')
              ) continue;
              swept.push({ file: nf, ...entry });
            }
          }
        } catch (_e) { /* ignore */ }
      }
      observations.push(
        `state14: silent_errors_count=${swept.length} files_scanned=${networkFiles.length}`
      );
      if (swept.length) {
        // eslint-disable-next-line no-console
        console.log('[Wave D kiosk-kds-sync] silent network errors detected:');
        for (const s of swept.slice(0, 30)) {
          // eslint-disable-next-line no-console
          console.log(`  - ${s.file} :: ${s.method} ${s.status} ${s.url}`);
        }
      }

      // ================================================================
      // FINAL — Sanity gate : >=14 PNG quartets minimum on disk
      // (3 baseline + 04 kiosk pay + 05 kds + 06 kiosk confirm +
      //  07 kds preparing + 08 oss preparing + 09 kds prepared +
      //  10 oss prepared + 11 kiosk idem + 12/13/14 sweeps = 14)
      // ================================================================
      const written = fs
        .readdirSync(SCREENSHOT_DIR)
        .filter((f) => f.endsWith('.png'));
      // eslint-disable-next-line no-console
      console.log(`[Wave D kiosk-kds-sync] obs:\n  ${observations.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(
        `[Wave D kiosk-kds-sync] timings: ${JSON.stringify(timings)}\n` +
        `[Wave D kiosk-kds-sync] capturedOrderIds=${JSON.stringify(capturedOrderIds)}\n` +
        `[Wave D kiosk-kds-sync] PNGs written: ${written.length} → ${written.sort().join(', ')}`
      );
      expect(
        written.length,
        `Wave D expects >=14 PNGs across 3 surfaces, got ${written.length}`
      ).toBeGreaterThanOrEqual(14);
    } finally {
      // ----------------------------------------------------------------
      // CLEANUP — cancel every order this run created so the live KDS
      // pile is not polluted for downstream rounds / human ops.
      // ----------------------------------------------------------------
      for (const oid of capturedOrderIds) {
        safeCancelOrder(oid);
      }
      // Belt-and-suspenders: prefix sweep (shared with Wave E specs).
      try {
        cleanupKioskAuditOrders(PREFIXE_AUDIT);
      } catch (_e) { /* best-effort */ }
      try { kioskRec.dispose(); } catch (_e) { /* ignore */ }
      try { kdsRec.dispose(); } catch (_e) { /* ignore */ }
      try { ossRec.dispose(); } catch (_e) { /* ignore */ }
      await kioskCtx.close().catch(() => {});
      await kdsCtx.close().catch(() => {});
      await ossCtx.close().catch(() => {});
    }
  });
});
