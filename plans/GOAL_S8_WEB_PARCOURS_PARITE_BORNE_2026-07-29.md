# GOAL S8 — WEB : PARCOURS 100 % FONCTIONNEL, PARITÉ BORNE ABSOLUE (2026-07-29)

> Tu es le LEAD PARCOURS WEB. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md`
> (surtout **§10 TRIO WEB** — S4 et S7 sont sur le MÊME repo) D'ABORD.
> Mission owner (prioritaire) : TOUT fonctionne sur le site — toutes les pages,
> toutes les fonctionnalités, et le parcours de commande de CHAQUE produit est
> aussi bien programmé que la BORNE (la référence) : personnalisation exacte,
> **aucune manipulation de prix possible**, logique maximale. Convergence §6.

## Ownership (STRICT — §10)
- Repo web : `wizard-v2.jsx`, `flows.jsx`, `upsell.jsx`, `funnel.jsx` (LOGIQUE),
  `api.js` (résolution noms→ids, garde expected_total), `data/menu.js` (parité data),
  `tests-e2e/**`.
- ⛔ INTERDITS : style vitrine (S7), backend (handoffs → S4), frozen backend.
- Référence de vérité : la BORNE (composants kiosk + DB items/variations/extras/
  profils) — en LECTURE pour comparer ; jamais d'édition côté borne (S1).

## Anchors (état réel vérifié)
- Parité viandes DÉJÀ exacte par item (famille A 7 viandes / B masquée) — 29/07.
- Gate = téléphone + email, code par EMAIL (réel) ; garde `expected_total` 422
  serveur = rempart prix ; coupons SSOT backend ; 86 sync polling 25 s.
- E2e durables : nav-smoke (13) + order-live REAL (1/demi-journée max).
- Wizard web : sauces 1ʳᵉ incluse (+0,50 après), crudités, suppléments, menu
  1,90/2,50 ratios, bols gratiné 2 €, tailles tacos — à re-prouver produit par produit.

## Vagues
### V1 — MATRICE PRODUIT×RÈGLE vs BORNE (le cœur)
Pour CHAQUE produit vendable (DB status=5, ~38) : dérouler le wizard WEB en e2e
local ET comparer aux règles borne (DB profils/variations/extras + comportement
kiosk) : étapes proposées, choix inclus/payants, min/max, prix affiché à chaque
étape, total final == PricingService (quote). Agents en fan-out par catégorie
(tacos/sandwichs/bols/galettes/menus enfant/formules/boissons/desserts).
Livrable : `V1-MATRICE.md` produit×règle avec ✅/❌ + captures des ❌.
Acceptance : 100 % des produits déroulés, chaque ❌ = finding P1 disputé RED.

### V2 — ANTI-MANIPULATION PRIX (adversarial dur)
Agents attaquants : modifier les payloads (prix client, extras forgés, ids
étrangers, quantités négatives, options d'un autre produit, replay quote,
double-submit, coupon forgé, points fidélité gonflés) → le serveur doit
TOUJOURS sceller juste ou 422. Le front do