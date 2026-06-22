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
// [ANTI-FICTION HEAL 2026-05-18 — Impl D Round 2] All `item_id` values are now
// canonical members of `mobile/data/menu.js` (101/102/202/301/302/401/501/502/
// 602/608/701/702/902/1001/1002). Pre-MENU-RESET fictional ids 1001/2001/2002/
// 2003/4001/5001/7001/8001/9002 + product names (Box Nashville/Familiale/Solo,
// Le Cheese Smash, Bowl Cheesy, Wrap Poulet, Cookie XL, Frites M, Coca-Cola)
// purged. `line_total` arithmetic now matches the canonical unit price from
// menu.js; `total` recomputed for each order. Sentinel test enforces parity.

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
      total: 29.80,                   // 9.50 + 7.90 + 10.90 + 1.50
      payment_status: 'pending',      // pending | paid
      payment_method: 'cash_at_counter', // cash_at_counter | card_at_counter | stripe
      pickup_code: 'C-1234',
      qr_value: 'LECAY-ORDER-1234-abc123',
      items_summary: 'Big Cayenne · Tacos L · Bowl Frites Curry · Coca-Cola',
      items: [
        { id: 1, item_id: 102,  name: 'Big Cayenne',              qty: 1, line_total: 9.50,  extras_summary: 'Cheddar · Œuf · Jambon' },
        { id: 2, item_id: 502,  name: 'Tacos L',                  qty: 1, line_total: 7.90,  extras_summary: '2 viandes · Sauce fromagère' },
        { id: 3, item_id: 602,  name: 'Bowl Frites Poulet curry', qty: 1, line_total: 10.90, extras_summary: 'Boule gratinée +2,00 €' },
        { id: 4, item_id: 1001, name: 'Coca-Cola 33cl',           qty: 1, line_total: 1.50,  extras_summary: '' },
      ],
      points_earned_estimate: 33,
    },
  ];

  const ORDERS_HISTORY = [
    {
      id: 'C-1212', number: 1212, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-05-09T19:47:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 13.00, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Sandwich Cayenne · Grande Frites · Coca-Cola',
      items: [
        { id: 1, item_id: 101,  name: 'Sandwich Cayenne',  qty: 1, line_total: 7.50, extras_summary: 'Poulet mariné · Sauce Cayenne' },
        { id: 2, item_id: 702,  name: 'Grande Frites',     qty: 1, line_total: 4.00, extras_summary: 'Nature' },
        { id: 3, item_id: 1001, name: 'Coca-Cola 33cl',    qty: 1, line_total: 1.50, extras_summary: '' },
      ],
      points_earned: 13,
    },
    {
      id: 'C-1208', number: 1208, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-05-09T13:21:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 16.30, payment_status: 'paid', payment_method: 'card_at_counter',
      items_summary: 'Chicken Burger × 2 · Petite Frites',
      items: [
        { id: 1, item_id: 401, name: 'Chicken Burger', qty: 2, line_total: 13.80, extras_summary: '' },
        { id: 2, item_id: 701, name: 'Petite Frites',  qty: 1, line_total: 2.50,  extras_summary: 'Nature' },
      ],
      points_earned: 16,
    },
    {
      id: 'C-1190', number: 1190, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-30T18:05:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 17.40, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Galette Cayenne · Bowl Riz Crispy · Coca Zero',
      items: [
        { id: 1, item_id: 202,  name: 'Galette Cayenne',          qty: 1, line_total: 7.00, extras_summary: 'Poulet mariné · Sauce Cayenne' },
        { id: 2, item_id: 608,  name: 'Bowl Riz Poulet crispy',   qty: 1, line_total: 8.90, extras_summary: 'Sauce fromagère' },
        { id: 3, item_id: 1002, name: 'Coca-Cola Zero 33cl',      qty: 1, line_total: 1.50, extras_summary: '' },
      ],
      points_earned: 17,
    },
    {
      id: 'C-1142', number: 1142, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-24T20:12:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 8.50, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Sandwich Classique · Coca-Cola',
      items: [
        { id: 1, item_id: 301,  name: 'Sandwich Classique', qty: 1, line_total: 7.00, extras_summary: 'Poulet curry · Sauce blanche' },
        { id: 2, item_id: 1001, name: 'Coca-Cola 33cl',     qty: 1, line_total: 1.50, extras_summary: '' },
      ],
      points_earned: 9,
    },
    {
      id: 'C-1100', number: 1100, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-20T14:30:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 12.80, payment_status: 'paid', payment_method: 'card_at_counter',
      items_summary: 'Big Classique · Tarte Daim',
      items: [
        { id: 1, item_id: 302, name: 'Big Classique', qty: 1, line_total: 9.00, extras_summary: 'Poulet crispy · Sauce algérienne · Cheddar + Œuf + Jambon inclus' },
        { id: 2, item_id: 902, name: 'Tarte Daim',    qty: 1, line_total: 3.80, extras_summary: '' },
      ],
      points_earned: 38,  // 13 + welcome bonus 25
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
