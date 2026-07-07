// Le Cayenne — Orders mock data (V0 standalone)
//
// Mapping backend (cf. CONNECTION_PLAN.md) :
//   active orders   ↔ GET  /api/v1/frontend/order?status=in-progress
//   history         ↔ GET  /api/v1/frontend/order?status=delivered
//   detail          ↔ GET  /api/v1/frontend/order/show/{id}
//   create          ↔ POST /api/v1/frontend/order (idempotency-key header)
//   reorder         ↔ POST /api/v1/frontend/order avec composition_snapshot
//
// Status values alignés App\Enums\OrderStatus : in_progress, ready, delivered, cancelled.
//
// [ANTI-FICTION HEAL 2026-05-18 · MENU-CANON 2026-06-26] All `item_id` values and
// names are canonical members of `mobile/data/menu.js` (Le Cayenne canon — 31 items,
// SSOT = database/seeders/OwnerMenuUpdate20260623Seeder.php). Every fictional pre-reset
// product name/id has been purged. Invariants enforced by tests/ordersParity.spec.js :
//   • item name === canon menu.js name for that item_id
//   • line_total === canon unit price × qty
//   • total === sum(line_total)
//   • points === Math.round(total × loyalty.config.earn_ratio)  (10 pt / €)

(function () {
  'use strict';

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
      points_earned_estimate: 247,    // Math.round(24.70 × 10) — earn_ratio 10 pt/€
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
      points_earned: 133,   // Math.round(13.30 × 10)
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
      points_earned: 123,   // Math.round(12.30 × 10)
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
      points_earned: 168,   // Math.round(16.80 × 10)
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
      points_earned: 93,   // Math.round(9.30 × 10)
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
      points_earned: 125,  // Math.round(12.50 × 10)
    },
  ];

  function totalSpent() {
    return ORDERS_HISTORY.reduce((s, o) => s + o.total, 0);
  }
  function totalCount() {
    return ORDERS_HISTORY.length + ORDERS_ACTIVE.length;
  }
  function totalPointsEarned() {
    return ORDERS_HISTORY.reduce((s, o) => s + (o.points_earned || 0), 0);
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
    active: ORDERS_ACTIVE,
    history: ORDERS_HISTORY,
    totalSpent,
    totalCount,
    totalPointsEarned,
    groupByDate,
    dateLabel,
    findById(id) {
      return ORDERS_ACTIVE.find(o => o.id === id) || ORDERS_HISTORY.find(o => o.id === id) || null;
    },
  };
})();
