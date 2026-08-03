// Le Cayenne — Orders data layer
//
// [GOAL-SYNC 2026-07-08] Passage V0 mock → RÉEL fetch-first (contrat §7) :
//   • active/history réels via LC.mobileApi.orderHistory() (GET /api/frontend/order,
//     Bearer) quand connecté ; mapping OrderResource backend → shape mobile.
//   • Le mock ci-dessous ne sert QUE de fallback HORS LIGNE / non connecté
//     (bandeau géré par les écrans via LC.orders.offline).
//   • Points re-dérivés à 1 pt/€ FLOOR (parité backend AwardLoyaltyPointsOnDelivery,
//     valeur LIVE points_per_euro=1) — fini le 10 pt/€ fantôme.
//
// Mapping backend :
//   liste   ↔ GET  /api/frontend/order            (OrderResource : status numérique)
//   detail  ↔ GET  /api/frontend/order/show/{id}
//   create  ↔ POST /api/frontend/order            (via LC.mobileApi.placeOrder)
//
// Statuts backend (App\Enums\OrderStatus) : 1 PENDING, 4 ACCEPT, 7 PREPARING,
// 8 PREPARED, 10 OUT_FOR_DELIVERY, 13 DELIVERED, 16 CANCELED, 19 REJECTED, 22 RETURNED.
//
// [ANTI-FICTION HEAL 2026-05-18 · MENU-CANON 2026-06-26] All `item_id` values and
// names are canonical members of `mobile/data/menu.js`. Invariants enforced by
// tests/ordersParity.spec.js (points = FLOOR(total × 1) depuis GOAL-SYNC 2026-07-08).

(function () {
  'use strict';

  // ───────────────────────────────────────────────────────────────────────
  // Fallback HORS LIGNE — points à 1 pt/€ FLOOR (backend LIVE).
  // ───────────────────────────────────────────────────────────────────────
  const ORDERS_ACTIVE = [
    {
      id: 'C-1234',
      number: 1234,
      status: 'in_progress',          // in_progress | ready | delivered | cancelled
      status_label: 'En préparation',
      created_at: '2026-05-10T19:33:00+02:00',
      ready_at_estimate: '2026-05-10T19:45:00+02:00',
      eta_minutes: 12,
      progress_pct: 50,
      branch_id: 1,
      branch_name: 'Le Cayenne',
      branch_city: 'Hénin-Beaumont',
      total: 24.70,                   // 7.00 + 7.90 + 7.90 + 1.90 (canon menu.js)
      payment_status: 'pending',      // pending | paid
      payment_method: 'cash_at_counter', // cash_at_counter | card_at_counter | stripe
      pickup_code: 'C-1234',
      qr_value: 'LECAY-ORDER-1234-abc123',
      items_summary: 'Suprême · Tacos L · Bol Riz · Coca-Cola',
      items: [
        { id: 1, item_id: 102,  name: 'Suprême',                  qty: 1, line_total: 7.00,  extras_summary: 'Cordon bleu · Cheddar · Sauce algérienne' },
        { id: 2, item_id: 502,  name: 'Tacos L',                  qty: 1, line_total: 7.90,  extras_summary: '2 viandes · Sauce fromagère' },
        { id: 3, item_id: 602,  name: 'Bol Riz',                  qty: 1, line_total: 7.90,  extras_summary: 'Viande au choix · Sauce fromagère' },
        { id: 4, item_id: 1001, name: 'Coca-Cola 33cl',           qty: 1, line_total: 1.90,  extras_summary: '' },
      ],
      points_earned_estimate: 24,     // Math.floor(24.70 × 1) — 1 pt/€ FLOOR (backend)
    },
  ];

  const ORDERS_HISTORY = [
    {
      id: 'C-1212', number: 1212, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-05-09T19:47:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 13.30, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Cayenne · Grande Frites · Coca-Cola',
      items: [
        { id: 1, item_id: 101,  name: 'Cayenne',           qty: 1, line_total: 7.40, extras_summary: 'Poulet mariné · Sauce fromagère maison' },
        { id: 2, item_id: 702,  name: 'Grande Frites',     qty: 1, line_total: 4.00, extras_summary: 'Nature' },
        { id: 3, item_id: 1001, name: 'Coca-Cola 33cl',    qty: 1, line_total: 1.90, extras_summary: '' },
      ],
      points_earned: 13,   // Math.floor(13.30 × 1)
    },
    {
      id: 'C-1208', number: 1208, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-05-09T13:21:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 12.30, payment_status: 'paid', payment_method: 'card_at_counter',
      items_summary: 'Chicken Burger × 2 · Petite Frites',
      items: [
        { id: 1, item_id: 401, name: 'Chicken Burger', qty: 2, line_total: 9.80, extras_summary: '' },
        { id: 2, item_id: 701, name: 'Petite Frites',  qty: 1, line_total: 2.50, extras_summary: 'Nature' },
      ],
      points_earned: 12,   // Math.floor(12.30 × 1)
    },
    {
      id: 'C-1190', number: 1190, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-30T18:05:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 16.80, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Galette Cayenne · Bol Riz · Coca Zero',
      items: [
        { id: 1, item_id: 202,  name: 'Galette Cayenne',          qty: 1, line_total: 7.00, extras_summary: 'Poulet mariné · Sauce fromagère maison' },
        { id: 2, item_id: 602,  name: 'Bol Riz',                  qty: 1, line_total: 7.90, extras_summary: 'Viande au choix · Sauce fromagère' },
        { id: 3, item_id: 1002, name: 'Coca-Cola Zero 33cl',      qty: 1, line_total: 1.90, extras_summary: '' },
      ],
      points_earned: 16,   // Math.floor(16.80 × 1)
    },
    {
      id: 'C-1142', number: 1142, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-24T20:12:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 9.30, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Cayenne · Coca-Cola',
      items: [
        { id: 1, item_id: 101,  name: 'Cayenne',            qty: 1, line_total: 7.40, extras_summary: 'Pain · Sauce fromagère maison' },
        { id: 2, item_id: 1001, name: 'Coca-Cola 33cl',     qty: 1, line_total: 1.90, extras_summary: '' },
      ],
      points_earned: 9,   // Math.floor(9.30 × 1)
    },
    {
      id: 'C-1100', number: 1100, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-20T14:30:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 12.50, payment_status: 'paid', payment_method: 'card_at_counter',
      items_summary: 'Terminator · Tarte Daim',
      items: [
        { id: 1, item_id: 104, name: 'Terminator', qty: 1, line_total: 9.00, extras_summary: '2 viandes au choix · Sauce algérienne' },
        { id: 2, item_id: 902, name: 'Tarte Daim',    qty: 1, line_total: 3.50, extras_summary: '' },
      ],
      points_earned: 12,  // Math.floor(12.50 × 1)
    },
  ];

  // ───────────────────────────────────────────────────────────────────────
  // Live state — écrasé par le serveur dès que possible (fetch-first).
  // ───────────────────────────────────────────────────────────────────────
  const live = {
    active: ORDERS_ACTIVE,
    history: ORDERS_HISTORY,
    offline: false,   // true après échec réseau (les écrans affichent le bandeau)
    source: 'mock',   // 'mock' | 'server'
  };

  function api() {
    return (window.LC && window.LC.mobileApi) || null;
  }

  function emitChanged(detail) {
    try {
      if (typeof window !== 'undefined' && window.dispatchEvent && typeof CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('lc:orders-changed', { detail: detail || {} }));
      }
    } catch (e) { /* sandbox sans events */ }
  }

  // Statut backend numérique → shape mobile {status, status_label} (FR).
  function mapStatus(s) {
    switch (Number(s)) {
      case 1:  return { status: 'in_progress', status_label: 'En attente' };
      case 4:  return { status: 'in_progress', status_label: 'Acceptée' };
      case 7:  return { status: 'in_progress', status_label: 'En préparation' };
      case 8:  return { status: 'ready',       status_label: 'Prête' };
      case 10: return { status: 'in_progress', status_label: 'En livraison' };
      case 13: return { status: 'delivered',   status_label: 'Récupérée' };
      case 16:
      case 19:
      case 22: return { status: 'cancelled',   status_label: 'Annulée' };
      default: return { status: 'in_progress', status_label: 'En cours' };
    }
  }

  // OrderResource backend → shape mobile consommée par les écrans.
  function mapOrder(o) {
    o = o || {};
    const st = mapStatus(o.status);
    const total = Number(o.total_amount_price != null ? o.total_amount_price : o.total) || 0;
    const serial = o.order_serial_no || String(o.id || '');
    const count = Number(o.order_items) || 0;
    const est = (window.LC && window.LC.loyalty && window.LC.loyalty.estimateEarn)
      ? window.LC.loyalty.estimateEarn(total)
      : Math.floor(total); // 1 pt/€ FLOOR par défaut
    return {
      id: serial,
      backend_id: o.id,
      number: o.queue_number != null ? o.queue_number : serial,
      status: st.status,
      status_label: o.status_name || st.status_label,
      created_at: o.order_datetime || o.created_at || '',
      branch_id: o.branch_id != null ? o.branch_id : 1,
      branch_name: o.branch_name || 'Le Cayenne',
      branch_city: 'Hénin-Beaumont',
      total: total,
      payment_status: Number(o.payment_status) === 5 ? 'paid' : 'pending', // PaymentStatus::PAID = 5
      payment_method: Number(o.payment_method) === 1 ? 'cash_at_counter' : 'card_at_counter',
      pickup_code: serial,
      items_summary: count ? (count + ' article' + (count > 1 ? 's' : '')) : '',
      items: [],   // liste backend = compte seulement ; détail via getOrder(id)
      points_earned_estimate: est,   // le crédit RÉEL est visible dans LC.loyalty.history
    };
  }

  /**
   * [GOAL-SYNC 2026-07-08] Fetch-first RÉEL : GET /api/frontend/order (Bearer).
   * Répartit actives (pending/preparing/ready) vs historique (delivered/cancelled).
   * Fallback mock UNIQUEMENT hors ligne / non connecté.
   */
  function refresh() {
    const a = api();
    if (!a || typeof a.orderHistory !== 'function' || !a.isAuthed || !a.isAuthed()) {
      return Promise.resolve(live); // non connecté : mock local (démo)
    }
    return a.orderHistory().then(function (rows) {
      const list = (Array.isArray(rows) ? rows : (rows && rows.data) || []).map(mapOrder);
      live.active = list.filter(function (o) { return o.status === 'in_progress' || o.status === 'ready'; });
      live.history = list.filter(function (o) { return o.status === 'delivered' || o.status === 'cancelled'; });
      live.offline = false;
      live.source = 'server';
      emitChanged({ type: 'server-sync', count: list.length });
      return live;
    }).catch(function (e) {
      live.offline = true;
      emitChanged({ type: 'offline', error: (e && e.kind) || 'network' });
      return live;
    });
  }

  function totalSpent() {
    return live.history.reduce((s, o) => s + o.total, 0);
  }
  function totalCount() {
    return live.history.length + live.active.length;
  }
  function totalPointsEarned() {
    return live.history.reduce((s, o) => s + (o.points_earned || 0), 0);
  }

  function groupByDate(list) {
    const groups = {};
    list.forEach(o => {
      const d = (o.created_at || '').slice(0, 10);
      groups[d] = groups[d] || [];
      groups[d].push(o);
    });
    return Object.keys(groups).sort().reverse().map(date => ({ date, items: groups[date] }));
  }

  // Date label like "AUJOURD'HUI" / "HIER" / "30 AVRIL"
  function dateLabel(isoDate) {
    if (!isoDate) return '';
    const d = new Date(isoDate);
    const now = new Date();
    const diff = Math.floor((now - d) / (1000 * 60 * 60 * 24));
    if (diff <= 0) return "AUJOURD'HUI";
    if (diff === 1) return 'HIER';
    const months = ['JAN','FÉV','MARS','AVR','MAI','JUIN','JUIL','AOÛT','SEPT','OCT','NOV','DÉC'];
    return `${d.getDate()} ${months[d.getMonth()]}`;
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.orders = {
    get active() { return live.active; },
    get history() { return live.history; },
    get offline() { return live.offline; },
    get source() { return live.source; },
    refresh,
    totalSpent,
    totalCount,
    totalPointsEarned,
    groupByDate,
    dateLabel,
    findById(id) {
      return live.active.find(o => o.id === id) || live.history.find(o => o.id === id) || null;
    },
    _mockActive: ORDERS_ACTIVE,
    _mockHistory: ORDERS_HISTORY,
  };

  // [GOAL-SYNC 2026-07-08] Fetch-first au chargement navigateur (skippé dans
  // les sandbox Node de test — pas de document/fetch).
  if (typeof window !== 'undefined' && window.document && typeof fetch === 'function') {
    const kick = function () { refresh(); };
    if (window.document.readyState === 'loading') {
      window.document.addEventListener('DOMContentLoaded', kick);
    } else {
      setTimeout(kick, 0);
    }
  }
})();
