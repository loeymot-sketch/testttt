// Le Cayenne — Loyalty data layer
//
// ─────────────────────────────────────────────────────────────────────────
// [GOAL-SYNC 2026-07-08] Passage V0 mock → RÉEL fetch-first (contrat
// reports/goal-web-app-sync/CONTRACTS.md §2) :
//
//   • CONFIG n'est PLUS hardcodée à 10 pt/€ : défauts = valeurs backend LIVE
//     (points_per_euro=1, points_for_1_euro_discount=100, min_redeem_points=100)
//     puis resynchronisée au chargement via LC.mobileApi.loyaltyConfig()
//     (GET /api/frontend/loyalty/config, PUBLIC). Event 'lc:loyalty-changed'
//     émis après sync → les écrans re-rendent.
//   • balance/history : fetch-first (LC.mobileApi.profile() + loyaltyHistory())
//     quand authentifié ; le mock ci-dessous ne sert QUE de fallback HORS LIGNE
//     (bandeau « hors ligne » géré par les écrans via LC.loyalty.offline).
//   • Catalogue REWARDS (8 récompenses fictives) SUPPRIMÉ — remplacé par le
//     modèle CONTINU points→€ du backend (100 pts = 1 €, décision D6=A,
//     aucune route /loyalty/rewards). Helpers : pointsToEuros / eurosToPoints /
//     minRedeem / estimateEarn (FLOOR, aligné AwardLoyaltyPointsOnDelivery).
//   • Mint QR mock ('FK:'+code + fausse signature SHA-256 locale) SUPPRIMÉ —
//     le format legacy est REJETÉ backend (accept_legacy_plaintext=false).
//     Le QR réel vient de POST /api/frontend/loyalty/qr via hooks/useLoyaltyQR.
//
// Endpoints (vérifiés w1/by-spec/backend_loyalty-endpoints.json) :
//   config   ↔ GET  /api/frontend/loyalty/config          (public)
//   solde    ↔ GET  /api/profile (loyalty_points + loyalty_code — PAS /balance)
//   history  ↔ GET  /api/frontend/loyalty/history?page=   (auth)
//   qr       ↔ POST /api/frontend/loyalty/qr               (auth, token 'lqr.…')
//   redeem   ↔ POST /api/frontend/loyalty/redeem           (auth + X-Idempotency-Key)
// ─────────────────────────────────────────────────────────────────────────

(function () {
  'use strict';

  // ───────────────────────────────────────────────────────────────────────
  // CONFIG — MUTABLE. Défauts = valeurs backend LIVE 2026-07-08 (settings
  // group loyalty_setup vérifiées tinker : 1 / 100 / 100). Resynchronisée au
  // runtime via refreshConfig() — plus jamais de taux hardcodé divergent.
  // ───────────────────────────────────────────────────────────────────────
  const CONFIG = {
    earn_ratio: 1,               // 1 € dépensé = 1 pt (backend points_per_euro — LIVE)
    points_per_euro: 1,          // alias nom API backend (consommé par screens-modals)
    redeem_ratio: 100,           // 100 points = 1 € (backend points_for_1_euro_discount)
    points_for_1_euro_discount: 100, // alias nom API backend
    min_redeem_points: 100,      // minimum pour utiliser (valeur LIVE DB)
    tiers: [100, 250, 500, 1000, 2000], // jalons d'affichage (backend loyalty_tiers)
    // [GOAL-SYNC 2026-07-08] welcome_bonus : AUCUN trigger backend n'existe —
    // 0 honnête (l'ancien 25 était un mensonge client-side). Champ conservé
    // pour compat écrans ; à retirer avec la copy « pts offerts ».
    welcome_bonus: 0,
  };

  /** [GOAL-SYNC 2026-07-08] Applique une réponse GET /loyalty/config (clés backend). */
  function applyBackendConfig(c) {
    if (!c || typeof c !== 'object') return CONFIG;
    const per = Number(c.points_per_euro);
    const ratio = Number(c.points_for_1_euro_discount);
    const min = Number(c.min_redeem_points);
    if (isFinite(per) && per > 0) { CONFIG.earn_ratio = per; CONFIG.points_per_euro = per; }
    if (isFinite(ratio) && ratio > 0) { CONFIG.redeem_ratio = ratio; CONFIG.points_for_1_euro_discount = ratio; }
    if (isFinite(min) && min >= 0) CONFIG.min_redeem_points = min;
    // tiers backend = '100,250,500,1000,2000' (string) ou array
    let tiers = c.tiers;
    if (typeof tiers === 'string') tiers = tiers.split(',').map(function (t) { return parseInt(t, 10); });
    if (Array.isArray(tiers)) {
      tiers = tiers.filter(function (t) { return isFinite(t) && t > 0; });
      if (tiers.length) CONFIG.tiers = tiers;
    }
    return CONFIG;
  }

  // Event notifier — no-op dans les sandbox Node (tests vm sans CustomEvent).
  function emitChanged(detail) {
    try {
      if (typeof window !== 'undefined' && window.dispatchEvent && typeof CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('lc:loyalty-changed', { detail: detail || {} }));
      }
    } catch (e) { /* environnement sans events — silencieux */ }
  }

  function api() {
    return (window.LC && window.LC.mobileApi) || null;
  }

  /**
   * [GOAL-SYNC 2026-07-08] Resynchronise CONFIG depuis le backend (endpoint
   * PUBLIC). Jamais bloquant : en cas d'échec réseau on garde les défauts LIVE.
   */
  function refreshConfig() {
    const a = api();
    if (!a || typeof a.loyaltyConfig !== 'function') return Promise.resolve(CONFIG);
    return a.loyaltyConfig()
      .then(function (c) {
        applyBackendConfig(c);
        emitChanged({ type: 'config' });
        return CONFIG;
      })
      .catch(function () { return CONFIG; });
  }

  // ───────────────────────────────────────────────────────────────────────
  // EARN_METHODS — documentation des surfaces de crédit (inchangé, informatif).
  // EARN réel = 100% backend (floor(total TTC × rate) au statut PREPARED/
  // DELIVERED — AwardLoyaltyPointsOnDelivery). Le client N'AJOUTE JAMAIS de
  // points ; il envoie loyalty_code dans placeOrder.
  // ───────────────────────────────────────────────────────────────────────
  const EARN_METHODS = Object.freeze([
    { code: 'purchase_app',         type: 'earn',        source_surface: 'mobile', requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'purchase_kiosk_phone', type: 'earn',        source_surface: 'kiosk',  requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'purchase_pos_phone',   type: 'earn',        source_surface: 'pos',    requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'qr_scan_kiosk',        type: 'earn',        source_surface: 'kiosk',  requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'qr_scan_pos',          type: 'earn',        source_surface: 'pos',    requires_order: true,  status: 'wired',   description_template: 'Commande #{serial}' },
    { code: 'manual_cashier',       type: 'manual_add',  source_surface: 'admin',  requires_order: false, status: 'wired',   description_template: 'Ajout manuel par staff' },
  ]);

  function findEarnMethod(code) {
    return EARN_METHODS.find(m => m.code === code) || null;
  }

  // ───────────────────────────────────────────────────────────────────────
  // Modèle points→€ — helpers PURS (contrat §2 : 100 pts = 1 €, min 100,
  // redeem = multiple de 100 obligatoire sur POST /loyalty/redeem).
  // ───────────────────────────────────────────────────────────────────────

  /** € équivalents d'un solde de points (affichage : 347 → 3.47). */
  function pointsToEuros(points) {
    if (!isFinite(points) || points <= 0) return 0;
    return Math.round((points / CONFIG.redeem_ratio) * 100) / 100;
  }

  /** Points nécessaires pour un montant € (2,00 € → 200 pts). */
  function eurosToPoints(euros) {
    if (!isFinite(euros) || euros <= 0) return 0;
    return Math.round(euros * CONFIG.redeem_ratio);
  }

  /** Minimum de points pour utiliser (valeur config backend). */
  function minRedeem() {
    return CONFIG.min_redeem_points;
  }

  /**
   * Points échangeables MAINTENANT : plus grand multiple de redeem_ratio ≤ solde,
   * 0 sous le minimum (le backend REJETTE tout montant non multiple — 400).
   */
  function redeemablePoints(balance) {
    if (!isFinite(balance) || balance < CONFIG.min_redeem_points) return 0;
    return Math.floor(balance / CONFIG.redeem_ratio) * CONFIG.redeem_ratio;
  }

  /**
   * Estimation earn pour un total € — FLOOR, aligné backend
   * (AwardLoyaltyPointsOnDelivery : floor(total × rate) ; 12,50 € → 12 pts).
   * ⚠ Estimation d'affichage : le crédit RÉEL arrive au statut PREPARED/DELIVERED.
   */
  function estimateEarn(euros) {
    if (!isFinite(euros) || euros <= 0) return 0;
    return Math.floor(euros * CONFIG.earn_ratio);
  }

  // Compat : anciens appels pointsToDiscount → même sémantique que pointsToEuros.
  function pointsToDiscount(points) {
    return pointsToEuros(points);
  }

  /**
   * Progression vers le prochain jalon (CONFIG.tiers) — remplace l'ancien
   * progressToNext basé sur le catalogue REWARDS supprimé. target garde la
   * shape {points_cost, name, icon} consommée par les écrans.
   */
  function tierTarget(tier) {
    return {
      points_cost: tier,
      name: '−' + (tier / CONFIG.redeem_ratio).toFixed(2).replace('.', ',') + ' € en caisse',
      icon: '🎁',
    };
  }

  function progressToNext(balance) {
    const tiers = CONFIG.tiers || [];
    const bal = isFinite(balance) ? balance : 0;
    for (let i = 0; i < tiers.length; i++) {
      if (bal < tiers[i]) {
        return {
          pct: Math.round((bal / tiers[i]) * 100),
          remaining: tiers[i] - bal,
          target: tierTarget(tiers[i]),
        };
      }
    }
    const last = tiers.length ? tiers[tiers.length - 1] : CONFIG.min_redeem_points;
    return { pct: 100, remaining: 0, target: tierTarget(last) };
  }

  // ───────────────────────────────────────────────────────────────────────
  // [GOAL-SYNC 2026-07-08] Compat REWARDS — catalogue SUPPRIMÉ (aucune table
  // loyalty_rewards backend, modèle points→€ fait foi). Stubs conservés le
  // temps que les écrans migrent (WizardRedeem/dev-helpers null-guardent déjà).
  // ───────────────────────────────────────────────────────────────────────
  const REWARDS = Object.freeze([]);
  function rewardById() { return null; }
  function unlockedRewards() { return []; }
  function nextRewardForBalance() { return null; }

  // ───────────────────────────────────────────────────────────────────────
  // ACCOUNT/HISTORY — fallback HORS LIGNE UNIQUEMENT.
  // [GOAL-SYNC 2026-07-08] re-dérivé à 1 pt/€ FLOOR (parité backend) sur les
  // commandes livrées de orders.js. Plus de welcome bonus (+25) ni de redeem
  // fictif « Burger gratuit » — triggers/catalogue inexistants backend.
  // ───────────────────────────────────────────────────────────────────────
  const DEFAULT_ACCOUNT = {
    user_id: 12345,
    member_number: 'FK-12345',
    loyalty_code: 'A1B2C3D4',         // 8 alphanum — shape users.loyalty_code
    balance: 62,                       // Σ earns ci-dessous (floor 1 pt/€)
    lifetime_earned: 62,               // dérivé de l'historique
    lifetime_redeemed: 0,
    plastic_card_linked: false,
    consent_status: 'opted_in',
    member_since: '2026-04-20',
    last_qr_preference: 'qr',
  };

  const DEFAULT_HISTORY = [
    { id: 1001, date: '2026-05-09', type: 'earn', points: +13, description: 'Cayenne · Grande Frites',        order_id: 'C-1212', source_surface: 'mobile' },
    { id: 1002, date: '2026-05-09', type: 'earn', points: +12, description: 'Chicken Burger × 2 · Frites',    order_id: 'C-1208', source_surface: 'mobile' },
    { id: 1003, date: '2026-04-30', type: 'earn', points: +16, description: 'Galette Cayenne · Bol Riz',      order_id: 'C-1190', source_surface: 'kiosk' },
    { id: 1004, date: '2026-04-24', type: 'earn', points: +9,  description: 'Cayenne · Coca-Cola',            order_id: 'C-1142', source_surface: 'kiosk' },
    { id: 1005, date: '2026-04-20', type: 'earn', points: +12, description: 'Terminator · Tarte Daim',        order_id: 'C-1100', source_surface: 'pos' },
  ];

  // ───────────────────────────────────────────────────────────────────────
  // Live state — hydraté localStorage puis ÉCRASÉ par le serveur dès que
  // possible (fetch-first). live.offline=true ⇒ les écrans affichent le
  // bandeau hors-ligne et les données ci-dessus (fallback).
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
  live.offline = false;      // true après un échec réseau (fallback mock affiché)
  live.source = 'mock';      // 'mock' | 'server'

  /**
   * [GOAL-SYNC 2026-07-08] Fetch-first RÉEL : GET /api/profile (solde +
   * loyalty_code) + GET /loyalty/history. Fallback mock UNIQUEMENT hors ligne.
   * Émet 'lc:loyalty-changed' dans tous les cas (les écrans re-rendent).
   */
  function refreshFromServer() {
    const a = api();
    if (!a || typeof a.profile !== 'function' || !a.isAuthed || !a.isAuthed()) {
      return Promise.resolve(live); // non connecté : état local, pas un mode offline
    }
    return Promise.all([
      a.profile(),
      (typeof a.loyaltyHistory === 'function' ? a.loyaltyHistory(1).catch(function () { return null; }) : Promise.resolve(null)),
    ]).then(function (results) {
      const p = results[0] || {};
      const h = results[1];
      const rows = (h && (h.data || h.rows)) || (Array.isArray(h) ? h : []);
      const history = (Array.isArray(rows) ? rows : []).map(function (row, i) {
        return {
          id: row.id != null ? row.id : (9000 + i),
          date: String(row.date || row.created_at || '').slice(0, 10),
          type: row.type || 'earn',
          points: Number(row.points) || 0,
          description: row.description || '',
          order_id: row.order_id != null ? row.order_id : null,
          source_surface: row.source_surface || 'mobile',
          balance_after: row.balance_after != null ? Number(row.balance_after) : undefined,
        };
      });
      const earned = history.reduce(function (s, r) { return s + (r.points > 0 ? r.points : 0); }, 0);
      const redeemed = history.reduce(function (s, r) { return s + (r.points < 0 ? -r.points : 0); }, 0);
      live.account = Object.assign({}, live.account, {
        user_id: p.id != null ? p.id : live.account.user_id,
        member_number: p.id != null ? ('FK-' + p.id) : live.account.member_number,
        loyalty_code: p.loyalty_code || live.account.loyalty_code,
        balance: p.loyalty_points != null ? Number(p.loyalty_points) : live.account.balance,
        lifetime_earned: earned,     // dérivé de la page d'historique (approx. serveur)
        lifetime_redeemed: redeemed,
        member_since: (p.created_at ? String(p.created_at).slice(0, 10) : live.account.member_since),
      });
      if (history.length) live.history = history;
      live.offline = false;
      live.source = 'server';
      if (window.LC.storage && window.LC.storage.setLoyalty) {
        window.LC.storage.setLoyalty({ account: live.account, history: live.history });
      }
      emitChanged({ type: 'server-sync' });
      return live;
    }).catch(function (e) {
      // HORS LIGNE (ou API down) : on garde le fallback local, flag pour bandeau.
      live.offline = true;
      emitChanged({ type: 'offline', error: (e && e.kind) || 'network' });
      return live;
    });
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.loyalty = {
    config: CONFIG,
    applyBackendConfig,
    refreshConfig,
    refreshFromServer,
    earnMethods: EARN_METHODS,
    findEarnMethod,
    // Modèle points→€ (contrat §2) :
    pointsToEuros,
    eurosToPoints,
    minRedeem,
    redeemablePoints,
    estimateEarn,
    pointsToDiscount,       // alias compat
    progressToNext,         // jalons CONFIG.tiers
    // Compat REWARDS supprimé (stubs vides — cf. bloc ci-dessus) :
    rewards: REWARDS,
    rewardById,
    unlockedRewards,
    nextRewardForBalance,
    // Live state — bind components to these:
    get account() { return live.account; },
    get history() { return live.history; },
    get offline() { return live.offline; },
    get source() { return live.source; },
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
      live.source = 'mock';
    },
    // Defaults exposés pour tests + dev-helpers reset:
    _defaultAccount: DEFAULT_ACCOUNT,
    _defaultHistory: DEFAULT_HISTORY,
  };
  // [GOAL-SYNC 2026-07-08] generateSignedQR / generateMockQR SUPPRIMÉS —
  // format 'FK:<code>' REJETÉ backend. QR réel = hooks/useLoyaltyQR
  // (POST /api/frontend/loyalty/qr → token 'lqr.…' TTL 300 s).

  // [GOAL-SYNC 2026-07-08] Fetch-first au chargement navigateur (jamais dans
  // les sandbox de test Node : window.document absent). Config = public ;
  // solde/historique seulement si connecté.
  if (typeof window !== 'undefined' && window.document && typeof fetch === 'function') {
    const kick = function () {
      refreshConfig();
      refreshFromServer();
    };
    if (window.document.readyState === 'loading') {
      window.document.addEventListener('DOMContentLoaded', kick);
    } else {
      setTimeout(kick, 0);
    }
  }
})();
