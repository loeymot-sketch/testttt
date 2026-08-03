// =============================================================================
// scheduled-order-kds-banner-2026-07-20.spec.js
// PREUVE VISUELLE (mandat owner) — cycle commande PROGRAMMÉE sur le VRAI KDS local.
//
//   État 1 — commande programmée NOW+2h (hors fenêtre lead 20 min, config
//            kds.scheduled_lead_minutes) : PAS de carte sur le board, mais le
//            bandeau « ⏰ Programmées (1) : HH:MM — #serial » est visible.
//            → CAPTURE 1 (tests/e2e/__screenshots__/scheduled-2026-07-20/).
//   État 2 — une DEUXIÈME programmée entre dans la fenêtre (cible NOW+10min).
//            Le lead config est immuable à chaud → la commande est créée VALIDE
//            (NOW+30min, la validation PosOrderRequest exige >= now+lead) puis
//            son scheduled_at est déplacé à NOW+10min en DB (simulation exacte
//            du passage du temps). Reload /kds : ELLE est en carte normale ET
//            la première reste bandeau-seulement. → CAPTURE 2.
//
// Chemin de création : POS DIFFÉRÉ (phone_order=true → PENDING_COUNTER +
// COUNTER_DEFERRED) via POST /api/admin/pos — le chemin scheduled_at réel
// (intake caisse/téléphone, W4-E5), zéro tiroir, zéro fiscal_sequence_no
// (alloc différée à l'encaissement) → cleanup sans risque NF525.
//
// Patterns REPRIS de tests/e2e/_teste2e-blocCD-2026-07-18.spec.js :
// helpers/login (loginAsPosOperator/loginAsChefOperator), tinker/tinkerJson,
// browserApi (window.axios SPA), cleanup token-préfixé avec fiscal nullé avant
// delete (trigger orders_no_delete).
//
// Lancer : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test \
//          tests/e2e/scheduled-order-kds-banner-2026-07-20.spec.js
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { loginAsPosOperator, loginAsChefOperator } = require('./helpers/login');

const repoRoot = path.resolve(__dirname, '../..');
const SHOTS = path.join(repoRoot, 'tests/e2e/__screenshots__/scheduled-2026-07-20');
fs.mkdirSync(SHOTS, { recursive: true });

// -- Enums (miroir app/Enums, valeurs vérifiées dans le code) -----------------
const OT_TAKEAWAY = 10;         // OrderType::TAKEAWAY
const SRC_POS = 15;             // Source::POS
const PPM_CASH = 1;             // PosPaymentMethod::CASH (écrasé serveur → COUNTER_DEFERRED en différé)
const ASK_NO = 10;              // Ask::NO
const PS_PENDING_COUNTER = 15;  // PaymentStatus::PENDING_COUNTER (board-released)
const KDS_VISIBLE_STATUSES = [4, 7, 8]; // ACCEPT / PREPARING / PREPARED

// Idempotency keys figés au chargement du module : un retry Playwright rejoue
// le POST avec la même clé → posOrderStore retourne la commande EXISTANTE
// (aucun doublon). Les assertions temporelles lisent la DB (probe), pas
// l'intention initiale, donc elles restent exactes après retry.
const TK1 = `SCHEDTEST-A-${Date.now()}`;
const TK2 = `SCHEDTEST-B-${Date.now()}`;

// -----------------------------------------------------------------------------
// tinker helpers — mirror _teste2e-blocCD-2026-07-18.spec.js
// -----------------------------------------------------------------------------
function tinker(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000,
  });
}
function tinkerJson(code) {
  const out = tinker(code);
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`tinker: no JSON in output:\n${out.slice(0, 800)}`);
  return JSON.parse(jsonLine);
}

// In-browser API caller (window.axios → session SPA du user loggé + x-api-key
// via l'intercepteur). Mirror byte-identique du pattern blocCD.
async function browserApi(page, { method = 'get', url, params = null, data = null, idem = null }) {
  return page.evaluate(async ({ method, url, params, data, idem }) => {
    const cfg = {};
    if (params) cfg.params = params;
    if (idem) cfg.headers = { 'X-Idempotency-Key': idem };
    try {
      const m = method.toLowerCase();
      const r = m === 'get' ? await window.axios.get(url, cfg) : await window.axios[m](url, data || {}, cfg);
      return { status: r.status, data: r.data };
    } catch (e) {
      return { status: e?.response?.status ?? 0, data: e?.response?.data ?? { message: String(e?.message || e) } };
    }
  }, { method, url, params, data, idem });
}

// Horloge SERVEUR (PHP, TZ app Europe/Paris) — évite toute divergence Node/PHP.
function scheduleTarget(minutes) {
  return tinkerJson(`
    $t = now()->addMinutes(${minutes});
    echo json_encode(['at' => $t->format('Y-m-d H:i:s'), 'hm' => $t->format('H:i')]);
  `);
}

// Simulation exacte du passage du temps : la commande (créée VALIDE, >= now+lead)
// voit son scheduled_at ramené DANS la fenêtre — update DB brut, zéro observer.
function shiftScheduledAt(id, minutes) {
  return tinkerJson(`
    $t = now()->addMinutes(${minutes})->format('Y-m-d H:i:s');
    \\DB::table('orders')->where('id', ${id})->update(['scheduled_at' => $t]);
    echo json_encode(['shifted_to' => $t, 'hm' => \\Illuminate\\Support\\Carbon::parse($t)->format('H:i')]);
  `);
}

// Vérité DB de la commande + prédicat fenêtre calculé par la MÊME règle SSOT
// (KitchenReleaseRule::scheduledLeadMinutes) que le board.
function probeOrder(id) {
  return tinkerJson(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${id});
    echo json_encode($o ? [
      'id' => (int) $o->id,
      'serial' => (string) $o->order_serial_no,
      'token' => (string) $o->token,
      'status' => (int) $o->status,
      'payment_status' => (int) $o->payment_status,
      'branch_id' => (int) $o->branch_id,
      'order_type' => (int) $o->order_type,
      'queue' => (string) $o->queue_number,
      'scheduled_at' => $o->scheduled_at?->format('Y-m-d H:i:s'),
      'scheduled_hm' => $o->scheduled_at?->format('H:i'),
      'beyond_window' => $o->scheduled_at
        ? $o->scheduled_at->gt(now()->addMinutes(\\App\\Domain\\Kds\\KitchenReleaseRule::scheduledLeadMinutes()))
        : null,
      'fiscal_sequence_no' => $o->fiscal_sequence_no === null ? null : (int) $o->fiscal_sequence_no,
    ] : ['missing' => true]);
  `);
}

// Pollution éventuelle du bandeau : autres programmées à venir (mêmes gates que
// upcomingScheduled — statuts visibles + board-release + hors fenêtre, branche 1).
function upcomingPollution() {
  return tinkerJson(`
    $lead = \\App\\Domain\\Kds\\KitchenReleaseRule::scheduledLeadMinutes();
    $rows = \\App\\Models\\Order::withoutGlobalScopes()
      ->where('branch_id', 1)
      ->whereIn('status', [4, 7, 8])
      ->whereNotNull('scheduled_at')
      ->where('scheduled_at', '>', now()->addMinutes($lead))
      ->where(function ($q) {
        $q->whereIn('payment_status', [5, 15])
          ->orWhere(function ($c) { $c->where('order_type', 15)->where('pos_payment_method', 1); });
      })
      ->get(['id', 'order_serial_no', 'token', 'scheduled_at'])
      ->map(fn ($o) => ['id' => (int) $o->id, 'serial' => $o->order_serial_no, 'token' => $o->token, 'at' => (string) $o->scheduled_at])
      ->all();
    echo json_encode(['count' => count($rows), 'rows' => $rows]);
  `);
}

// Cleanup token-préfixé SCHEDTEST-% — mirror cleanupValgoal (blocCD) : enfants
// d'abord, fiscal_sequence_no nullé AVANT delete (trigger NF525 orders_no_delete ;
// nos différées ont fiscal NULL, ceinture-bretelles quand même).
function cleanupSchedtest() {
  return tinker(`
    $del = function (callable $fn) { try { $fn(); } catch (\\Throwable $e) {} };
    $ids = \\App\\Models\\Order::withoutGlobalScopes()->where('token', 'like', 'SCHEDTEST-%')->pluck('id');
    if ($ids->isNotEmpty()) {
      $del(fn() => Schema::hasTable('transactions') && DB::table('transactions')->whereIn('order_id', $ids)->delete());
      $del(fn() => Schema::hasTable('order_status_transitions') && DB::table('order_status_transitions')->whereIn('order_id', $ids)->delete());
      $del(fn() => Schema::hasTable('domain_events') && DB::table('domain_events')->whereIn('aggregate_id', $ids)->delete());
      $del(fn() => Schema::hasTable('order_coupons') && DB::table('order_coupons')->whereIn('order_id', $ids)->delete());
      $del(fn() => DB::table('order_items')->whereIn('order_id', $ids)->delete());
      $del(fn() => DB::table('orders')->whereIn('id', $ids)->update(['fiscal_sequence_no' => null]));
      $del(fn() => DB::table('orders')->whereIn('id', $ids)->delete());
    }
    echo json_encode(['swept' => $ids->count(), 'remaining' => \\App\\Models\\Order::withoutGlobalScopes()->where('token', 'like', 'SCHEDTEST-%')->count()]);
  `);
}

// Création POS DIFFÉRÉE programmée — quote (sceau HMAC OrderQuoteService,
// OBLIGATOIRE surface POS : sealForCommit 401 sans token+signature) puis
// POST /api/admin/pos sous session caissier. Payload mirror du V4b blocCD
// (item 2 « Frites Seules », shape validée par ce spec-là) + phone_order +
// scheduled_at (W4-E5). Idempotent par clé.
async function createScheduledPosOrder(posPage, { token, scheduledAt, customerName }) {
  const base = {
    token,
    branch_id: 1,
    discount: 0,
    order_type: OT_TAKEAWAY,
    is_advance_order: ASK_NO,
    source: SRC_POS,
    phone_order: true,
    pos_customer_name: customerName,
    pos_payment_method: PPM_CASH,
    pos_received_amount: 20,
    items: JSON.stringify([
      { item_id: 2, item_price: 2.00, quantity: 1, total_price: 2.00, item_variations: [], item_extras: [] },
    ]),
    scheduled_at: scheduledAt,
  };
  const quote = await browserApi(posPage, { method: 'post', url: 'admin/pos/quote', data: base });
  if (quote.status !== 200) {
    throw new Error(`[createScheduledPosOrder ${token}] quote HTTP ${quote.status}: ${JSON.stringify(quote.data).slice(0, 400)}`);
  }
  const q = quote.data?.data || quote.data;
  const resp = await browserApi(posPage, {
    method: 'post',
    url: 'admin/pos',
    data: { ...base, quote_token: q.quote_token, quote_signature: q.signature },
    idem: token,
  });
  if (![200, 201].includes(resp.status)) {
    throw new Error(`[createScheduledPosOrder ${token}] HTTP ${resp.status}: ${JSON.stringify(resp.data).slice(0, 400)}`);
  }
  const od = resp.data?.data || resp.data;
  if (!od?.id) throw new Error(`[createScheduledPosOrder ${token}] no id: ${JSON.stringify(resp.data).slice(0, 300)}`);
  return Number(od.id);
}

// Board + bandeau vus par l'API RÉELLE du KDS (GET admin/kds-order, session chef).
async function kdsApiState(chefPage) {
  const r = await browserApi(chefPage, { method: 'get', url: 'admin/kds-order' });
  if (r.status !== 200) throw new Error(`[kdsApiState] HTTP ${r.status}: ${JSON.stringify(r.data).slice(0, 300)}`);
  const boardIds = (Array.isArray(r.data?.data) ? r.data.data : []).map((o) => Number(o.id));
  const upcoming = Array.isArray(r.data?.meta?.scheduled_upcoming) ? r.data.meta.scheduled_upcoming : [];
  return { boardIds, upcomingIds: upcoming.map((e) => Number(e.id)), upcoming };
}

// -----------------------------------------------------------------------------
test.describe('KDS — commandes programmées : bandeau hors fenêtre, carte en fenêtre', () => {
  test.setTimeout(300_000);

  test.beforeAll(() => { console.log('[cleanup:before]', cleanupSchedtest().trim().split('\n').pop()); });
  test.afterAll(() => { console.log('[cleanup:after]', cleanupSchedtest().trim().split('\n').pop()); });

  test('cycle programmée : NOW+2h bandeau-seulement puis NOW+10min en carte', async ({ browser }) => {
    // Deux identités simultanées → deux contexts (le relogin même-user révoque
    // les tokens ; caissier et chef sont des users distincts, zéro interférence).
    const posCtx = await browser.newContext({ viewport: { width: 1600, height: 900 } });
    const chefCtx = await browser.newContext({
      viewport: { width: 1600, height: 900 },
      timezoneId: 'Europe/Paris', // = config('app.timezone') → l'heure affichée par le bandeau est comparable à H:i PHP
    });
    const posPage = await posCtx.newPage();
    const chefPage = await chefCtx.newPage();

    try {
      // ── Pré-condition : bandeau vierge (sinon « (1) » serait invérifiable) ──
      const pollution = upcomingPollution();
      expect(pollution.count, `Programmées résiduelles polluant le bandeau : ${JSON.stringify(pollution.rows)}`).toBe(0);

      // ═══ PHASE A — commande 1 : cible NOW+2h (HORS fenêtre lead 20 min) ═══
      await loginAsPosOperator(posPage);

      const t1 = scheduleTarget(120);
      console.log('[A] cible commande 1 (serveur):', JSON.stringify(t1));
      const id1 = await createScheduledPosOrder(posPage, { token: TK1, scheduledAt: t1.at, customerName: 'SCHEDTEST Bandeau +2h' });

      const probe1 = probeOrder(id1);
      console.log('[A] probe commande 1 :', JSON.stringify(probe1));
      expect(probe1.missing).toBeUndefined();
      expect(probe1.scheduled_at).toBeTruthy();                       // scheduled_at persisté
      expect(probe1.payment_status).toBe(PS_PENDING_COUNTER);         // différé → board-released
      expect(KDS_VISIBLE_STATUSES).toContain(probe1.status);          // statut visible KDS
      expect(probe1.beyond_window).toBe(true);                        // strictement HORS fenêtre (règle SSOT)
      expect(probe1.fiscal_sequence_no).toBeNull();                   // différé = zéro fiscal à la création
      const serial1 = probe1.serial;
      const hm1 = probe1.scheduled_hm;

      // ── /kds réel, session chef ──
      await loginAsChefOperator(chefPage);
      await chefPage.goto('/kds', { waitUntil: 'domcontentloaded' });   // alias mandaté — redirige vers la surface KDS
      await expect(chefPage).toHaveURL(/kitchen-display-system|\/kds/, { timeout: 25_000 });

      const banner = chefPage.locator('[data-testid="kds-scheduled-banner"]');
      await expect(banner, 'Bandeau programmées absent du KDS').toBeVisible({ timeout: 30_000 });

      // CAPTURE 1 — assertions AVANT screenshot : l'image prouve un état vérifié.
      // (cartes = layout V2 KdsOrderCard, racine [data-order-id="<id>"])
      await expect(banner).toContainText(`Programmées (1)`);
      await expect(banner).toContainText(`${hm1} — #${serial1}`);      // heure + serial exacts
      await expect(chefPage.locator(`[data-order-id="${id1}"]`), 'La programmée +2h ne doit PAS être en carte').toHaveCount(0);

      // Corroboration par l'API réelle du board (même endpoint que le poll KDS).
      const api1 = await kdsApiState(chefPage);
      console.log('[A] kds-order → board:', JSON.stringify(api1.boardIds), '| upcoming:', JSON.stringify(api1.upcoming));
      expect(api1.upcomingIds).toContain(id1);
      expect(api1.boardIds).not.toContain(id1);

      await chefPage.screenshot({ path: path.join(SHOTS, 'capture-1-banner-only.png'), fullPage: true });
      await banner.screenshot({ path: path.join(SHOTS, 'capture-1-banner-zoom.png') });
      console.log(`[CAPTURE 1] OK — #${serial1} (id ${id1}) bandeau-seulement, cible ${probe1.scheduled_at}`);

      // ═══ PHASE B — commande 2 : créée VALIDE (NOW+30) puis décalée NOW+10 ═══
      // (le lead config est immuable à chaud ; la validation création exige
      //  scheduled_at >= now+lead → on simule le passage du temps en DB)
      const t2create = scheduleTarget(30);
      const id2 = await createScheduledPosOrder(posPage, { token: TK2, scheduledAt: t2create.at, customerName: 'SCHEDTEST Carte +10min' });
      const shift = shiftScheduledAt(id2, 10);
      console.log('[B] commande 2 créée à', t2create.at, '→ décalée DANS la fenêtre :', JSON.stringify(shift));

      const probe2 = probeOrder(id2);
      console.log('[B] probe commande 2 :', JSON.stringify(probe2));
      expect(probe2.missing).toBeUndefined();
      expect(probe2.payment_status).toBe(PS_PENDING_COUNTER);
      expect(KDS_VISIBLE_STATUSES).toContain(probe2.status);
      expect(probe2.beyond_window).toBe(false);                       // DANS la fenêtre (<= now+lead, règle SSOT)
      const serial2 = probe2.serial;
      const hm2 = probe2.scheduled_hm;

      // ── Reload /kds → état 2 ──
      await chefPage.reload({ waitUntil: 'domcontentloaded' });
      const card2 = chefPage.locator(`[data-order-id="${id2}"]`);
      await expect(card2, 'La programmée +10min DOIT être en carte normale').toBeVisible({ timeout: 30_000 });
      await expect(card2).toContainText(`N°${probe2.queue}`);          // carte V2 = N° de file

      const banner2 = chefPage.locator('[data-testid="kds-scheduled-banner"]');
      await expect(banner2, 'Le bandeau doit rester visible (commande 1 toujours à venir)').toBeVisible({ timeout: 30_000 });
      await expect(banner2).toContainText(`Programmées (1)`);          // toujours UNE seule à venir…
      await expect(banner2).toContainText(`${hm1} — #${serial1}`);     // …la commande 1…
      await expect(banner2).not.toContainText(`#${serial2}`);          // …et PAS la commande 2 (sortie du bandeau)
      await expect(chefPage.locator(`[data-order-id="${id1}"]`), 'La +2h doit RESTER bandeau-seulement').toHaveCount(0);

      const api2 = await kdsApiState(chefPage);
      console.log('[B] kds-order → board:', JSON.stringify(api2.boardIds), '| upcoming:', JSON.stringify(api2.upcoming));
      expect(api2.boardIds).toContain(id2);
      expect(api2.upcomingIds).toContain(id1);
      expect(api2.upcomingIds).not.toContain(id2);
      expect(api2.boardIds).not.toContain(id1);

      await chefPage.screenshot({ path: path.join(SHOTS, 'capture-2-card-in-window.png'), fullPage: true });
      console.log(`[CAPTURE 2] OK — #${serial2} (id ${id2}, cible ${probe2.scheduled_at} ${hm2}) en carte ; #${serial1} (id ${id1}) toujours bandeau-seulement`);
      console.log(`[IDS CRÉÉS] order1=${id1} (${TK1}) | order2=${id2} (${TK2}) — supprimés par afterAll (cleanup SCHEDTEST-%)`);
    } finally {
      await posCtx.close();
      await chefCtx.close();
    }
  });
});
