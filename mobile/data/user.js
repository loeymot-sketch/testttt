// Le Cayenne — User profile data layer
//
// [GOAL-SYNC 2026-07-08] Passage V0 mock → RÉEL fetch-first (contrat §2/§7) :
//   • profil réel via LC.mobileApi.profile() (GET /api/profile, Bearer) quand
//     connecté ; fallback = téléphone stocké à l'OTP (LC.storage.getAuth) puis
//     profil invité neutre. Le faux compte « Ikyes B. » + carte Visa •4242 ont
//     été SUPPRIMÉS (aucun moyen de paiement enregistré backend en V1).
//   • Émet 'lc:user-changed' après sync serveur (les écrans re-rendent).
//
// Mapping backend :
//   profile ↔ GET /api/profile (name, phone, loyalty_points, loyalty_code)

(function () {
  'use strict';

  // Profil INVITÉ neutre — fallback hors connexion / hors ligne uniquement.
  const DEFAULT_USER = {
    id: null,
    first_name: 'Invité',
    last_name: '',
    initials: 'LC',
    full_name: 'Invité',
    phone: '',
    phone_verified: false,
    email: null,
    avatar_url: null,
    // Préférences (locales V1 — pas de colonnes backend dédiées)
    allergens: [],
    notifications: { push: true, sms: true, email: false },
    language: 'fr',
    // [GOAL-SYNC 2026-07-08] plus de carte Visa fictive — vide tant que le
    // paiement en ligne est désactivé (flag onlineCardEnabled=false, contrat §4).
    payment_methods: [],
    // Loyalty (miroir de LC.loyalty.account après sync)
    loyalty_points: 0,
    member_number: null,
    plastic_card_linked: false,
    member_since: null,
  };

  function api() {
    return (window.LC && window.LC.mobileApi) || null;
  }

  function emitChanged(detail) {
    try {
      if (typeof window !== 'undefined' && window.dispatchEvent && typeof CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('lc:user-changed', { detail: detail || {} }));
      }
    } catch (e) { /* sandbox sans events */ }
  }

  // État courant : défaut invité + téléphone d'auth local si présent.
  function seed() {
    const u = Object.assign({}, DEFAULT_USER);
    const auth = (window.LC && window.LC.storage && window.LC.storage.getAuth)
      ? window.LC.storage.getAuth()
      : null;
    if (auth && auth.phone) {
      u.phone = auth.phone;
      u.phone_verified = true;
    }
    if (auth && auth.user_id != null) {
      u.id = auth.user_id;
      u.member_number = 'FK-' + auth.user_id;
    }
    return u;
  }

  const live = { current: seed(), offline: false, source: 'local' };

  /** Découpe « Prénom N. » → {first, last} (name backend = string unique). */
  function splitName(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return { first: DEFAULT_USER.first_name, last: '' };
    return { first: parts[0], last: parts.slice(1).join(' ') };
  }

  /**
   * [GOAL-SYNC 2026-07-08] Fetch-first : GET /api/profile quand connecté.
   * Fallback storage/invité sinon. Jamais bloquant.
   */
  function refresh() {
    const a = api();
    if (!a || typeof a.profile !== 'function' || !a.isAuthed || !a.isAuthed()) {
      live.current = seed();
      live.source = 'local';
      return Promise.resolve(live.current);
    }
    return a.profile().then(function (p) {
      p = p || {};
      const nm = splitName(p.name || ((p.first_name || '') + ' ' + (p.last_name || '')));
      const initials = (nm.first.charAt(0) + (nm.last.charAt(0) || '')).toUpperCase() || 'LC';
      live.current = Object.assign({}, live.current, {
        id: p.id != null ? p.id : live.current.id,
        first_name: nm.first,
        last_name: nm.last,
        initials: initials,
        full_name: (nm.first + ' ' + nm.last).trim(),
        phone: p.phone || live.current.phone,
        phone_verified: true,
        email: p.email || null,
        loyalty_points: p.loyalty_points != null ? Number(p.loyalty_points) : live.current.loyalty_points,
        member_number: p.id != null ? ('FK-' + p.id) : live.current.member_number,
        member_since: p.created_at ? String(p.created_at).slice(0, 10) : live.current.member_since,
      });
      live.offline = false;
      live.source = 'server';
      emitChanged({ type: 'server-sync' });
      return live.current;
    }).catch(function (e) {
      live.offline = true;
      emitChanged({ type: 'offline', error: (e && e.kind) || 'network' });
      return live.current;
    });
  }

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
    get current() { return live.current; },
    get offline() { return live.offline; },
    get source() { return live.source; },
    refresh,
    greeting,
    _default: DEFAULT_USER,
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
