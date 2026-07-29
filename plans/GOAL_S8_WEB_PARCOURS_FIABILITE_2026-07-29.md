# GOAL S8 — SITE WEB : PARCOURS DE COMMANDE 100 % FONCTIONNEL & FIDÈLE À LA BORNE (2026-07-29)

> Tu es le LEAD PARCOURS WEB. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md`
> (surtout **§10 TRIO WEB**) D'ABORD. Mission owner : que TOUT soit fonctionnel —
> toutes les fonctionnalités, toutes les pages, chaque bouton — et que la
> personnalisation de CHAQUE produit soit la MÊME logique que la borne (référence
> qui « fonctionne bien, bien programmée, zéro modification de prix côté client »).
> C'est la mission « technique & parcours » que l'owner veut prioritaire.
> Convergence §6, autonomie §7.

## Ownership (STRICT — §10)
- Repo web : `wizard-v2.jsx`, `flows.jsx`, `upsell.jsx`, `funnel.jsx` (logique
  parcours), `api.js` (résolution choix→ids, placeOrder), `data/menu.js` (data
  parité borne), `orders.jsx`, `account-v2.jsx` (logique compte).
- Partagés avec pull-rebase : `index.html`, cache-bust.
- ⛔ INTERDITS : style vitrine (S7 — screens/components/styles-v6) ; backend
  (S4 — handoff `plans/handoffs/S8-vers-S4-*.md`) ; frozen borne/caisse.

## Principe cardinal : la BORNE est la vérité
Le web ne doit JAMAIS diverger de la borne sur : produits, viandes (familles A/B
par item — cf. mémoire parité 7 viandes / 4 poulets), sauces (1ʳᵉ incluse, +0,50),
crudités, suppléments, formules (menu 2,50 / frites 1,90 / boisson 1,90),
gratiné bols-only 2 €. **Aucun prix n'est calculé côté client** : le web envoie
`item_id, quantity, option_ids` ; le backend scelle (PricingService SSOT).
Toute divergence borne↔web = finding P1.

## État connu (anchors)
- Gate commande = téléphone + email → code par EMAIL (prouvé réel, #290726216).
- Money-path scellé au centime prouvé ×3 ; `expected_total` garde anti-drop.
- Parité viandes vérifiée exacte (famille A/B) ; extras réels fail-loud.
- Historique de bugs ES5/Babel (hoisting → page blanche, faux 422) : PRUDENCE
  (pas de `?.`/`??` hors JSX, helpers déclarés avant usage).

## Vagues
### V1 — Audit fonctionnel EXHAUSTIF (toutes pages, tous boutons, cachés inclus)
Cartographie chaque route/état/modale + CHAQUE bouton cliqué en e2e réel :
home→menu→fiche→wizard (×4+ archétypes : tacos taille/viandes, sandwich
sauce/crudités, bol gratiné, menu enfant, formule) → panier → upsell → checkout
→ paiement (comptoir + carte TEST) → OTP email → confirmation → suivi → compte.
Plus : recherche, filtres, promo, offline, 404, double-clic, retour navigateur.
Chaque bouton MORT ou page cassée = finding P0/P1. Captures LUES.
Acceptance : `V1-AUDIT-FONCTIONNEL.md` — 100 % des boutons testés, 0 mort non listé.

### V2 — Parité personnalisation borne↔web (le cœur)
Matrice EXHAUSTIVE produit × option : pour chaque produit, comparer les choix
offerts web vs borne vs DB (items/variations/extras/profils). Prix scellé
identique au centime pour une même composition (test automatisé web→backend).
Corriger toute divergence côté web (data/menu.js + résolution api.js), jamais
en inventant. Extras fantômes → fail-loud (déjà en place, durcir).
Acceptance : matrice parité 100 % verte + tests de scellé prix par archétype.

### V3 — Robustesse du parcours
Réseau coupé à chaque étape, backend 422/500, session expirée, panier périmé
(TTL 24 h), idempotence double-submit paiement, retour Mollie multi-onglet
(anti-doublon déjà traité — re-prouver), coupon SSOT, quota 429.
Acceptance : chaque scénario e2e joué → issue UX propre, jamais d'état bloquant.

### V4 — Paiement TEST bout-en-bout (coordonné S4)
Avec `MOLLIE_TEST_API_KEY` : payer une commande (carte test) → webhook → PAID →
confirmation « payée » → visible caisse. (Si S4 possède le backend Mollie :
handoff pour toute garde serveur ; toi tu prouves le PARCOURS client.)
Acceptance : cycle TEST payé prouvé sans argent réel, consigné.

### V5 — Convergence
nav-smoke 13/13 ×2 + order-live réel + audit adversarial 2 cycles propres
(RED « chasseur de bouton mort ») + deploy + BRAIN + memory + COMMANDES_TEST.md.

## Coordination
Le style change sous tes pieds (S7) : re-teste après un rebase, ne stylise RIEN.
Un besoin backend (nouvel endpoint, garde) → handoff S4, continue autre chose.
