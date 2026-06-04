// Le Cayenne — Loyalty data layer (V0 standalone)
//
// ─────────────────────────────────────────────────────────────────────────
// ⚠️  MOCK ONLY — alignment with backend SSOT
// ─────────────────────────────────────────────────────────────────────────
// This file aligns 1:1 with the backend LoyaltyController + LoyaltyTransaction
// schema as it exists at HEAD 2026-05-10, with HONEST gaps documented:
//
//   • REWARDS array : NO `loyalty_rewards` table or `/loyalty/rewards` route
//     exists backend. This is pure mock. Phase 6 requires backend migration
//     (B-11 in reports/review/mobile-loyalty-audit-2026-05-10/99_VERDICT.md §6).
//
//   • CONFIG.welcome_bonus, expires_after_days : NOT implemented backend.
//     V0 mocks the granting client-side via `LC.dev.earnPoints`. Phase 6 needs
//     listener on User::created or birthday cron.
//
//   • ACCOUNT.lifetime_earned/redeemed/next_threshold/progress_to_next/
//     plastic_card_linked : these columns DO NOT exist on backend `users`
//     table. They are derived/mock. Phase 6 computes lifetime_* from
//     `/loyalty/history` aggregations.
//
//   • Idempotency : V0 dedupes per-device via localStorage Map
//     (`LC.storage.idempotency`). Backend Phase 6 needs server-side
//     `Idempotency-Key` header on `/loyalty/redeem` (B-02 in 99_VERDICT.md).
//
//   • QR format : V0 emits `FK:<loyalty_code>` (D-A per 99_VERDICT.md §1) +
//     a separate `signature` field that is a SHA-256 mock (no HMAC secret).
//     Phase 6 backend `/loyalty/qr/sign` will return HMAC-keyed signature
//     (B-09 in 99_VERDICT.md §6).
//
// Backend endpoint mapping (cf. CONNECTION_PLAN.md §3) :
//   config       ↔ GET  /api/v1/frontend/loyalty/config
//   balance      ↔ GET  /api/v1/frontend/loyalty/balance
//   history      ↔ GET  /api/v1/frontend/loyalty/history (paginated)
//   redeem       ↔ POST /api/v1/frontend/loyalty/redeem (+ Idempotency-Key)
//   rewards      ↔ GET  /api/v1/frontend/loyalty/rewards (DOES NOT EXIST yet)
//   scan         ↔ POST /api/v1/frontend/loyalty/scan (kiosk:order ability)
// ─────────────────────────────────────────────────────────────────────────

(function () {
  'use strict';

  // ───────────────────────────────────────────────────────────────────────
  // CONFIG — backend defaults (admin-editable via Smartisan\Settings 'loyalty_setup')
  // Phase 6 : fetch from GET /loyalty/config + cache 1h; for V0 we hardcode the
  // backend defaults so the UI shows the correct numbers immediately.
  // ───────────────────────────────────────────────────────────────────────
  const CONFIG = {
    earn_ratio: 10,              // 1 € spent = 10 points (backend default; admin-editable)
    redeem_ratio: 100,           // 100 points = 1 € discount (backend default)
    min_redeem_points: 100,      // backend kiosk default; controller default is 50 (drift documented)
    expires_after_days: 365,     // V0 mock — backend has no expiry cron yet
    welcome_bonus: 25,           // V0 mock — backend has no granting trigger yet
    tiers: [100, 250, 500, 1000, 2000],   // matches KioskLoyaltyComponent line 336
  };

  // ───────────────────────────────────────────────────────────────────────
  // EARN_METHODS — catalog of 10 ways points can be credited
  //
  // V0 reality :
  //   • 6 methods are partially wired backend (listener-driven on order
  //     status change via AwardLoyaltyPointsOnDelivery.php).
  //   • 4 methods (welcome, referral, birthday, plastic_card_scan as link
  //     event) require backend triggers that do NOT exist yet (B-13 in
  //     99_VERDICT.md §6).
  //
  // Schema mapping (cf. 99_VERDICT.md §1 DEC-03) :
  //   • Order-rattached earns : type='earn', order_id set, source_surface variant.
  //   • Account-level earns (welcome/birthday) : type='manual_add', order_id=NULL,
  //     source_surface variant. Uses 'manual_add' because enum is fixed at
  //     ['earn','redeem','manual_add','manual_deduct','expire'] and we cannot
  //     add 'welcome_bonus' without an ALTER TABLE migration.
  // ───────────────────────────────────────────────────────────────────────
  /** @typedef {object} EarnMethod
   *  @property {string} code             Stable client-side identifier
   *  @property {'earn'|'manual_add'} type  loyalty_transactions.type enum value
   *  @property {string} source_surface   loyalty_transactions.source_surface
   *  @property {boolean} requires_order  true → order_id set; false → order_id=NULL
   *  @property {'wired'|'mock'|'planned'} status  wiring status backend
   *  @property {string} description_template  String used for transactions.description
   */
  const EARN_METHODS = Object.freeze([
    { code: 'purchase_app',         type: 'earn',        source_surface: 'mobile',                 requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'purchase_kiosk_phone', type: 'earn',        source_surface: 'kiosk',                  requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'purchase_pos_phone',   type: 'earn',        source_surface: 'pos',                    requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'qr_scan_kiosk',        type: 'earn',        source_surface: 'kiosk',                  requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'qr_scan_pos',          type: 'earn',        source_surface: 'pos',                    requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'plastic_card_scan',    type: 'earn',        source_surface: 'kiosk',                  requires_order: true,  status: 'wired',   description_template: 'Commande #{serial} (carte)' },
    { code: 'welcome_bonus',        type: 'manual_add',  source_surface: 'mobile_welcome',         requires_order: false, status: 'mock',    description_template: 'Bienvenue · Bonus inscription' },
    { code: 'manual_cashier',       type: 'manual_add',  source_surface: 'admin',                  requires_order: false, status: 'wired',   description_template: 'Ajout manuel par staff' },
    { code: 'referral',             type: 'earn',        source_surface: 'mobile_referral_giver', requires_order: true,  status: 'planned', description_template: 'Parrainage · {referee_first_name}' },
    { code: 'birthday',             type: 'manual_add',  source_surface: 'mobile_birthday',        requires_order: false, status: 'planned', description_template: 'Anniversaire · +{N} pts' },
  ]);

  function findEarnMethod(code) {
    return EARN_METHODS.find(m => m.code === code) || null;
  }

  // ───────────────────────────────────────────────────────────────────────
  // REWARDS — V0 MOCK CATALOG
  //
  // ⚠️ NO `loyalty_rewards` TABLE OR `/loyalty/rewards` ROUTE EXISTS BACKEND.
  // Mobile rewards array is 100% mock. Redeem flow calls POST /loyalty/redeem
  // with `{code, points}` — backend has no awareness of which "reward" was
  // chosen, just decrements the points. Reward catalog must be re-enforceable
  // server-side in Phase 6 (B-11 in 99_VERDICT.md §6).
  //
  // Until then : reward_id is local-state only. POS operator who honors the
  // reward must visually check the redemption screen displays a reward name —
  // there is NO server-side reward_id audit chain.
  // ───────────────────────────────────────────────────────────────────────
  // [ANTI-FICTION HEAL 2026-05-18 — Impl D Round 2] payload item_id/category_id
  // values now point to canonical menu.js. Removed fictional ids 2001 (Box Solo) +
  // 2003 (Box Familiale) + 7001 (Frites M variation). Fixed reward 5 category_id
  // mismatch (2=Galette ≠ Burger label → 4=Burgers).
  const REWARDS = Object.freeze([
    { id: 1, name: 'Petite Frites offerte',      points_cost: 100,  type: 'free_item',         payload: { item_id: 701 },                       icon: '🍟' },
    { id: 2, name: '−1 € de réduction',          points_cost: 100,  type: 'discount',          payload: { amount: 1.00 },                       icon: '🎁' },
    { id: 3, name: '−2,50 € sur ta commande',    points_cost: 250,  type: 'discount',          payload: { amount: 2.50 },                       icon: '🎁' },
    { id: 4, name: '−5 € sur ta commande',       points_cost: 500,  type: 'discount',          payload: { amount: 5.00 },                       icon: '🎁' },
    { id: 5, name: 'Burger gratuit (au choix)',  points_cost: 1000, type: 'free_item',         payload: { category_id: 4 },                     icon: '🍔' },
    { id: 6, name: 'Tacos offert',               points_cost: 1500, type: 'free_item',         payload: { item_id: 501 },                       icon: '🌮' },
    { id: 7, name: 'Big Cayenne −50 %',          points_cost: 2000, type: 'percent_discount',  payload: { item_id: 102, percent: 50 },          icon: '🌶️' },
    { id: 8, name: 'Repas complet à 1 €',        points_cost: 3000, type: 'flat_price',        payload: { amount: 1.00 },                       icon: '🎉' },
  ]);

  // ───────────────────────────────────────────────────────────────────────
  // ACCOUNT — V0 mock; Phase 6 reads from `users.loyalty_points` + history aggregates
  //
  // Fields with MOCK ONLY comments are NOT in the backend `users` schema.
  // ───────────────────────────────────────────────────────────────────────
  const DEFAULT_ACCOUNT = {
    user_id: 12345,
    member_number: 'FK-12345',
    loyalty_code: 'A1B2C3D4',         // 8 alphanum — matches users.loyalty_code shape
    balance: 347,                      // ↔ users.loyalty_points
    lifetime_earned: 1247,             // MOCK ONLY — compute from history Phase 6
    lifetime_redeemed: 900,            // MOCK ONLY — compute from history Phase 6
    plastic_card_linked: false,        // MOCK ONLY — no backend mechanism
    consent_status: 'opted_in',        // ↔ loyalty_consents (per-user audit trail)
    member_since: '2026-04-20',        // MOCK ONLY — derive from users.created_at Phase 6
    last_qr_preference: 'qr',          // 'qr' | 'barcode' — UI preference, persisted localStorage
  };

  // [ANTI-FICTION HEAL 2026-05-18 — Impl D Round 2] descriptions now reference
  // canonical menu.js item names matching the order summaries in mobile/data/orders.js.
  // Removed fictional refs: Box Nashville, Wrap Poulet, Smash Cheese, Le Gourmet,
  // "Box Nashville −50%".
  const DEFAULT_HISTORY = [
    { id: 1001, date: '2026-05-08', type: 'earn',       points: +30,  description: 'Big Cayenne · Big Tacos · Bowl Frites', order_id: 'C-1234', source_surface: 'mobile' },
    { id: 1002, date: '2026-05-05', type: 'earn',       points: +13,  description: 'Sandwich Cayenne · Grande Frites',     order_id: 'C-1212', source_surface: 'mobile' },
    { id: 1003, date: '2026-05-02', type: 'redeem',     points: -1000, description: 'Burger gratuit (Big Chicken)',         order_id: null,     source_surface: 'mobile', reward_id: 5 },
    { id: 1004, date: '2026-04-30', type: 'earn',       points: +17,  description: 'Galette Cayenne · Bowl Riz',           order_id: 'C-1190', source_surface: 'kiosk' },
    { id: 1005, date: '2026-04-28', type: 'earn',       points: +15,  description: 'Sandwich Classique',                   order_id: 'C-1180', source_surface: 'pos' },
    { id: 1006, date: '2026-04-24', type: 'earn',       points: +9,   description: 'Sandwich Classique · Coca-Cola',       order_id: 'C-1142', source_surface: 'kiosk' },
    { id: 1007, date: '2026-04-20', type: 'manual_add', points: +25,  description: 'Bienvenue · Bonus inscription',        order_id: null,     source_surface: 'mobile_welcome' },
  ];

  // ───────────────────────────────────────────────────────────────────────
  // QR SIGNING — V0 mock with forward-compatible structure
  //
  // V0 returns `{ payload, signature, expires_at }` where:
  //   - payload = "FK:<loyalty_code>"    (the actual QR rendered string)
  //   - signature = SHA-256(payload|expires_at) hex truncated  (mock, no HMAC secret)
  //   - expires_at = unix seconds, now+300 (5min TTL)
  //
  // V0 backend `scan()` strips the `FK:` prefix and matches loyalty_code — IGNORES
  // signature. Phase 6 backend `/loyalty/qr/sign` returns HMAC keyed by app.key
  // (B-09 in 99_VERDICT.md §6). The wire shape stays identical.
  //
  // The async crypto.subtle vs btoa choice (per Agent-2 §4) is intentional:
  // forward-compat with the eventual real HMAC (also async via fetch).
  // ───────────────────────────────────────────────────────────────────────
  async function generateSignedQR(loyaltyCode) {
    const code = (loyaltyCode || DEFAULT_ACCOUNT.loyalty_code || '').toUpperCase();
    const ts = Math.floor(Date.now() / 1000);
    const exp = ts + 300; // 5 min TTL
    const payload = 'FK:' + code;

    // V0 mock signature — NOT cryptographically secure (no secret key).
    // Only exercises the wire format. Phase 6 backend signs with HMAC(app.key).
    const data = new TextEncoder().encode(code + '|' + exp);
    let signature;
    try {
      const buf = await crypto.subtle.digest('SHA-256', data);
      signature = Array.from(new Uint8Array(buf))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('')
        .slice(0, 32);  // 128-bit truncated for shorter QR payload (mock-only)
    } catch (e) {
      // Fallback for environments without crypto.subtle (very old browsers, ssr)
      signature = String(code.length * exp).padStart(32, '0').slice(0, 32);
    }

    return { payload, signature, expires_at: exp };
  }

  // ───────────────────────────────────────────────────────────────────────
  // Helpers
  // ───────────────────────────────────────────────────────────────────────
  function nextRewardForBalance(balance) {
    return REWARDS.find(r => r.points_cost > balance) || REWARDS[REWARDS.length - 1];
  }

  function unlockedRewards(balance) {
    return REWARDS.filter(r => balance >= r.points_cost);
  }

  function rewardById(id) {
    return REWARDS.find(r => r.id === id) || null;
  }

  function pointsToDiscount(points) {
    return points / CONFIG.redeem_ratio;
  }

  function progressToNext(balance) {
    const next = nextRewardForBalance(balance);
    if (!next || balance >= next.points_cost) return { pct: 100, remaining: 0, target: next };
    return {
      pct: Math.round((balance / next.points_cost) * 100),
      remaining: next.points_cost - balance,
      target: next,
    };
  }

  // ───────────────────────────────────────────────────────────────────────
  // Live state — hydrated from localStorage if present, otherwise defaults
  //
  // Mobile components read `LC.loyalty.account` and `LC.loyalty.history`.
  // Mutations go through `LC.storage.setLoyalty(...)` (storage.js) which
  // updates this in-memory live state.
  // ───────────────────────────────────────────────────────────────────────
  function hydrate() {
    const persisted = window.LC && window.LC.storage && window.LC.storage.getLoyalty
      ? window.LC.storage.getLoyalty(null)
      : null;
    if (persisted && typeof persisted === 'object') {
      return {
        account: Object.assign({}, DEFAULT_ACCOUNT, persisted.account || {}),
        history: Array.isArray(persisted.history) ? persisted.history : DEFAULT_HISTORY,
      };
    }
    return {
      account: Object.assign({}, DEFAULT_ACCOUNT),
      history: [...DEFAULT_HISTORY],
    };
  }

  const live = hydrate();

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.loyalty = {
    config: CONFIG,
    rewards: REWARDS,
    earnMethods: EARN_METHODS,
    findEarnMethod,
    rewardById,
    // Live state — bind components to these:
    get account() { return live.account; },
    get history() { return live.history; },
    // Mutations — go through these to ensure persistence:
    _replaceAccount(newAccount) {
      live.account = Object.assign({}, live.account, newAccount);
      if (window.LC.storage && window.LC.storage.setLoyalty) {
        window.LC.storage.setLoyalty({ account: live.account, history: live.history });
      }
    },
    _replaceHistory(newHistory) {
      live.history = newHistory;
      if (window.LC.storage && window.LC.storage.setLoyalty) {
        window.LC.storage.setLoyalty({ account: live.account, history: live.history });
      }
    },
    _rehydrate() {
      const fresh = hydrate();
      live.account = fresh.account;
      live.history = fresh.history;
    },
    // Defaults exposed for tests + dev-helpers reset:
    _defaultAccount: DEFAULT_ACCOUNT,
    _defaultHistory: DEFAULT_HISTORY,
    // QR + helpers:
    generateSignedQR,
    nextRewardForBalance,
    unlockedRewards,
    pointsToDiscount,
    progressToNext,
  };

  // Backward-compat alias (some older callers still use this name) — emits the
  // new shape but as a string (legacy callers won't care about the signature).
  window.LC.loyalty.generateMockQR = function legacyGenerateMockQR(userIdIgnored) {
    return 'FK:' + (live.account.loyalty_code || DEFAULT_ACCOUNT.loyalty_code);
  };
})();
