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
      total: 33.00,
      payment_status: 'pending',      // pending | paid
      payment_method: 'cash_at_counter', // cash_at_counter | card_at_counter | stripe
      pickup_code: 'C-1234',
      qr_value: 'LECAY-ORDER-1234-abc123',
      items_summary: 'Box Nashville · Le Cheese · Bowl Cheesy',
      items: [
        { id: 1, item_id: 2002, name: 'Box Nashville',  qty: 1, line_total: 17.00, extras_summary: 'Oignon caramélisé · Cheddar' },
        { id: 2, item_id: 1001, name: 'Le Cheese Smash', qty: 1, line_total: 9.50, extras_summary: '' },
        { id: 3, item_id: 4001, name: 'Bowl Cheesy',     qty: 1, line_total: 11.50, extras_summary: '' },
      ],
      points_earned_estimate: 33,
    },
  ];

  const ORDERS_HISTORY = [
    {
      id: 'C-1212', number: 1212, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-05-09T19:47:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 29.00, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Box Familiale',
      items: [
        { id: 1, item_id: 2003, name: 'Box Familiale', qty: 1, line_total: 29.00, extras_summary: '4 smash · 4 boissons' },
      ],
      points_earned: 29,
    },
    {
      id: 'C-1208', number: 1208, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-05-09T13:21:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 21.00, payment_status: 'paid', payment_method: 'card_at_counter',
      items_summary: 'Smash Cheese × 2 · Frites',
      items: [
        { id: 1, item_id: 1001, name: 'Le Cheese Smash', qty: 2, line_total: 19.00, extras_summary: '' },
        { id: 2, item_id: 7001, name: 'Frites M',        qty: 1, line_total: 3.50, extras_summary: '' },
      ],
      points_earned: 21,
    },
    {
      id: 'C-1190', number: 1190, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-30T18:05:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 22.00, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Wrap Poulet · Bowl Cheesy',
      items: [
        { id: 1, item_id: 5001, name: 'Wrap Poulet', qty: 1, line_total: 8.50, extras_summary: '' },
        { id: 2, item_id: 4001, name: 'Bowl Cheesy', qty: 1, line_total: 11.50, extras_summary: '' },
        { id: 3, item_id: 8001, name: 'Coca-Cola',   qty: 1, line_total: 2.50, extras_summary: '' },
      ],
      points_earned: 22,
    },
    {
      id: 'C-1142', number: 1142, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-24T20:12:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 18.00, payment_status: 'paid', payment_method: 'cash_at_counter',
      items_summary: 'Box Nashville · Coca',
      items: [
        { id: 1, item_id: 2002, name: 'Box Nashville', qty: 1, line_total: 15.00, extras_summary: '' },
        { id: 2, item_id: 8001, name: 'Coca-Cola',     qty: 1, line_total: 2.50, extras_summary: '' },
      ],
      points_earned: 18,
    },
    {
      id: 'C-1100', number: 1100, status: 'delivered', status_label: 'Récupérée',
      created_at: '2026-04-20T14:30:00+02:00',
      branch_id: 1, branch_name: 'Le Cayenne', branch_city: 'Hénin-Beaumont',
      total: 15.00, payment_status: 'paid', payment_method: 'card_at_counter',
      items_summary: 'Box Solo (Cheese Smash)',
      items: [
        { id: 1, item_id: 2001, name: 'Box Solo', qty: 1, line_total: 13.50, extras_summary: 'Cheese Smash · Frite M · Coca' },
        { id: 2, item_id: 9002, name: 'Cookie XL', qty: 1, line_total: 3.50, extras_summary: '' },
      ],
      points_earned: 25,  // 15 + welcome bonus
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
