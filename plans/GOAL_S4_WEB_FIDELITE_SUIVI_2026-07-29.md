# GOAL S4 — SITE WEB : FIDÉLITÉ MAX + SUIVI DE COMMANDE + TEMPS DE PRÉPARATION (2026-07-29)

> Tu es le LEAD SITE CLIENT. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md`
> D'ABORD. Le site (www.lecayenne.fr) est LIVE : paiement carte Mollie actif,
> code de commande par EMAIL (contact@lecayenne.fr), Uber Eats branché. Mission
> owner : fidélité au MAXIMUM, chaque client voit SA commande avec temps restant
> estimé et sait quand elle est PRÊTE — expérience miroir borne. Convergence §6.

## Ownership (tes chemins)
- TOUT le repo web `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`
  (branche `main`, deploy Vercel auto, cache-bust `?v=` à chaque push)
- Backend côté client web : `app/Http/Controllers/Frontend/**` (Loyalty, Order
  show/tracker, wait-estimate), `app/Services/*Loyalty*`, `*WaitEstimate*`,
  `GuestSignupController` (email-OTP), mails (`app/Mail/**`, vues emails)
- Tests Feature Frontend/Loyalty/Wait + `tests-e2e/` web · rapports `reports/goal-s4-web/`
- ⚠️ Interdits : PricingService/OrderStateMachine/Fiscal (frozen) ; composants
  kiosk/pos/kds (S1/S2/S5) ; events/queues (S3 — handoffs).

## État connu (anchors)
- Gate commande = téléphone + email, code par EMAIL (`funnel.jsx` authEmail,
  `api.guestEmailOtp`, backend `/guest-signup/email-otp` vague C) — prouvé réel.
- Estimation attente + créneaux + programmée livrés 28/07 (`wait estimate` waves C+D).
- Fidélité existante : 10 pts/€ (earn floor), QR signé cross-système, redeem
  100 pts=1 € (continu), lookup téléphone caisse (Wave E verrouillée).
- Suivi : `TrackingPage` poll 20 s (funnel.jsx), 4 étapes statut.
- E2e durables : `tests-e2e/nav-smoke.local.js` (13) + `order-live.REAL-ORDER.js`.
- Mollie TEST : clé owner posée sur VPS (`MOLLIE_TEST_API_KEY`, script
  set-mollie-test.sh) — paiements de TEST uniquement avec elle.

## Vagues
### V1 — Audit total du site (toutes pages, même cachées)
Chaque route/état : home, menu+filtres+recherche, fiche, wizard complet ×4
archétypes, panier, upsell, checkout, paiement (comptoir + carte TEST), OTP
email, confirmation QR, suivi, compte (login email-code, points, historique),
pages légales, 404/erreurs, offline. Mobile ET desktop. Captures lues une à une.
Acceptance : `V1-SURFACES.md` 100 %, findings P0/P1 séparés.

### V2 — FIDÉLITÉ AU MAXIMUM (mandat owner)
Objectif : un client comprend et UTILISE ses points sans réfléchir. Auditer puis
améliorer : visibilité du solde partout (header ? confirmation ? suivi ?),
earn affiché AVANT paiement, redeem simple au checkout web (aligné caisse,
SSOT backend, kill-switch respecté), QR fidélité comptoir, historique points,
parité stricte borne/caisse/web (mêmes règles, mêmes textes). Emails : le code
signup est envoyé — améliorer le template (brandé Cayenne, lisible).
Acceptance : parcours fidélité e2e réel (earn prouvé en DB + redeem TEST) +
matrice parité 3 systèmes verte.

### V3 — SUIVI DE COMMANDE + TEMPS RESTANT (mandat owner)
Chaque client voit SA commande : progression claire, **temps restant estimé**
(consommer l'endpoint wait-estimate réel — file cuisine ; handoff S3/S5 si un
event « prête » manque), bascule visuelle forte quand PRÊTE (« Ta commande est
prête ! »), et retrouvable (lien depuis confirmation, compte, refresh-safe).
Multi-commandes : chacun la SIENNE (jamais celle d'un autre — test sécurité
IDOR). Polling raisonnable (20 s ok, pas moins sans event).
Acceptance : e2e réel — commande passée → caisse la fait avancer → le suivi web
reflète chaque étape <25 s + temps estimé cohérent ±20 % ; test IDOR vert.

### V4 — Paiement TEST bout-en-bout + robustesse checkout
Avec la clé TEST : payer une commande e2e (carte test Mollie) → webhook →
PAID → confirmation « payée » → visible caisse. Échec/abandon → repli comptoir
propre (déjà en place — prouver). Idempotence double-clic payer.
Acceptance : cycle TEST payé prouvé en DB (payment_status=5) sans argent réel.

### V5 — Convergence
nav-smoke 13/13 ×2 + order-live réel + suites backend Frontend/Loyalty/Wait +
adversarial 2 cycles propres + deploy + BRAIN + memory + COMMANDES_TEST.md.

## Style
UX inspirée borne (S1 = référence) : gros boutons, feedback immédiat, zéro
jargon. ES5-safe (pas de `?.`/`??` dans .js non-Babel), hoisting prudent.
