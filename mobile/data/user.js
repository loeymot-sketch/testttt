// Le Cayenne — User profile mock (V0 standalone)
//
// Mapping backend (cf. CONNECTION_PLAN.md) :
//   profile     ↔ GET  /api/v1/frontend/profile
//   update      ↔ POST /api/v1/frontend/profile
//   prefs       ↔ allergens / notifications / language stored on User model
//   avatar      ↔ Spatie MediaLibrary collection 'avatar'

(function () {
  'use strict';

  const DEFAULT_USER = {
    id: 12345,
    first_name: 'Ikyes',
    last_name: 'B.',
    initials: 'IB',
    full_name: 'Ikyes B.',
    phone: '+33 6 42 79 98 84',
    phone_verified: true,
    email: null,
    avatar_url: null,
    // Préférences
    allergens: ['gluten', 'lactose'],   // affichage badge "2 actives"
    notifications: { push: true, sms: true, email: false },
    language: 'fr',
    // Paiement
    payment_methods: [
      { id: 1, type: 'card', brand: 'Visa', last4: '4242', exp: '12/28', is_default: true },
    ],
    // Loyalty
    loyalty_points: 347,
    member_number: 'FK-12345',
    plastic_card_linked: false,
    // Dates
    member_since: '2026-04-20',
  };

  // Greeting dynamique selon l'heure (5h-20h = Bonjour, sinon Bonsoir)
  function greeting(now) {
    now = now || new Date();
    const h = now.getHours();
    return (h >= 5 && h < 20) ? 'Bonjour' : 'Bonsoir';
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.user = {
    current: DEFAULT_USER,
    greeting,
  };
})();
