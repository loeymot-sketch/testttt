// Le Cayenne — Loyalty data (V0 standalone, schéma aligné LoyaltyController + LoyaltyTransaction)
//
// Mapping backend (cf. CONNECTION_PLAN.md) :
//   config       ↔ /api/v1/frontend/loyalty/config (LoyaltySetupController)
//   balance      ↔ /api/v1/frontend/loyalty/balance
//   history      ↔ /api/v1/frontend/loyalty/history (LoyaltyTransaction)
//   rewards      ↔ /api/v1/frontend/loyalty/rewards (LoyaltyReward)
//   redeem       ↔ POST /api/v1/frontend/loyalty/redeem
//   qr           ↔ HMAC signé backend ; format LECAY-LOYALTY-{user_id}-{hmac} (5 min TTL)

(function () {
  'use strict';

  const CONFIG = {
    earn_ratio: 1,         // 1 € dépensé = 1 point gagné
    redeem_ratio: 100,     // 100 points = 1 €
    min_redeem_points: 100,
    expires_after_days: 365,
    welcome_bonus: 25,
  };

  const REWARDS = [
    { id: 1, name: 'Frites M offertes',          points_cost: 100,  type: 'free_item',  payload: { item_id: 7001, variation_id: 70011 } },
    { id: 2, name: '−1 € de réduction',          points_cost: 100,  type: 'discount',   payload: { amount: 1.00 } },
    { id: 3, name: '−5 € sur ta commande',       points_cost: 500,  type: 'discount',   payload: { amount: 5.00 } },
    { id: 4, name: 'Burger gratuit (au choix)',  points_cost: 1000, type: 'free_item',  payload: { category_id: 2 } },
    { id: 5, name: 'Box Familiale −50%',         points_cost: 2000, type: 'percent_discount', payload: { item_id: 2003, percent: 50 } },
    { id: 6, name: 'Box Solo offerte',           points_cost: 1500, type: 'free_item',  payload: { item_id: 2001 } },
  ];

  // Mock account state for V0 (replaced by /balance + /history en prod)
  const ACCOUNT = {
    user_id: 12345,
    member_number: 'FK-12345',
    balance: 347,
    lifetime_earned: 1247,
    lifetime_redeemed: 900,
    next_threshold: 500,        // prochain palier intéressant
    progress_to_next: 347 / 500,
    plastic_card_linked: false,
  };

  const HISTORY = [
    { id: 1001, date: '2026-05-08', type: 'earn',   amount: +25,  reason: 'Box Nashville',          order_id: 'C-1234' },
    { id: 1002, date: '2026-05-05', type: 'earn',   amount: +12,  reason: 'Smash Cheese',           order_id: 'C-1212' },
    { id: 1003, date: '2026-05-02', type: 'redeem', amount: -500, reason: 'Burger gratuit utilisé', order_id: 'C-1201', reward_id: 4 },
    { id: 1004, date: '2026-04-30', type: 'earn',   amount: +22,  reason: 'Wrap Poulet · Bowl',     order_id: 'C-1190' },
    { id: 1005, date: '2026-04-28', type: 'earn',   amount: +15,  reason: 'Le Gourmet',             order_id: 'C-1180' },
    { id: 1006, date: '2026-04-24', type: 'earn',   amount: +18,  reason: 'Box Nashville',          order_id: 'C-1142' },
    { id: 1007, date: '2026-04-20', type: 'earn',   amount: +25,  reason: 'Bienvenue (1ʳᵉ commande)', order_id: 'C-1100' },
  ];

  // QR payload — V0 mock ; en prod le HMAC est signé par le backend (LoyaltyController::generateQr)
  function generateMockQR(userId) {
    const ts = Math.floor(Date.now() / 1000);
    const fakeHmac = btoa(`${userId}:${ts}`).replace(/=/g, '').slice(0, 16);
    return `LECAY-LOYALTY-${userId || ACCOUNT.user_id}-${fakeHmac}`;
  }

  // Helpers
  function nextRewardForBalance(balance) {
    return REWARDS.find(r => r.points_cost > balance) || REWARDS[REWARDS.length - 1];
  }
  function unlockedRewards(balance) {
    return REWARDS.filter(r => balance >= r.points_cost);
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.loyalty = {
    config: CONFIG,
    rewards: REWARDS,
    account: ACCOUNT,
    history: HISTORY,
    generateMockQR,
    nextRewardForBalance,
    unlockedRewards,
  };
})();
