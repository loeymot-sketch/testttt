# GOAL — UX mobile site web MAX + caisse MAX intelligente pour commandes web
Date : 2026-08-06 · Owner directive : « améliorer au maximum l'expérience et l'interface utilisateur sur la version mobile du site web, et rendre maximum intelligente la caisse pour toutes les commandes qui viennent du site web »

## §0 Preamble
- **Working tree backend** : modifs pré-existantes hors-scope (`public/css/daily-book.css`, `reports/antigravity/playwright-latest.json`) — NON incluses dans mes commits (add fichier par fichier).
- **Repos** : backend testttt (HEAD `9d409a33`, branche `pos/category-first-caisse-2026-06-23`) · web déployé `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` (HEAD `fb1208c`, remote Site-lecayenne, arbre propre).
- **Parallélisme** : 2 voies DISJOINTES (repos séparés) → vague WEB (agent implémenteur) ∥ vague CAISSE (main thread). Autorisé par PARALLEL_PROTOCOL (standalone parallel).
- **Frozen** : aucune proposition ne touche pos-wizard.js / PaymentComponent / PosV5TrancheRow / PricingService / OrderStateMachine / fiscal. Vérifié par l'audit.
- **Convergence** : suites vitest tracker + specs web verts, frozen-diff 0, visuel mobile analysé, RED dispute sur le diff, 0 P0/P1 restant.
- **Pas de push** sans owner explicite. Commits locaux atomiques.

## §1 Vague WEB — UX mobile (audit HEAD fb1208c, 14 constats vérifiés)
Lot A (reachability panier) : barre basse « Voir le panier » route menu ≤800px · chips catégorie sticky · toast d'ajout au lieu du tiroir plein écran.
Lot B (checkout/suivi) : safe-area `.lc-cart-foot` · section « Quand récupérer ? » 1-option → ligne info · grille tips suivi responsive · contenu de commande dans le suivi · skeleton chargement commandes.
Lot C (claviers/perf) : attrs clavier promo/OTP/note · lazy images wizard · feedback étape requise wizard.
Différé (commit séparé, décision après vagues) : précompile Babel des 10 .jsx (levier perf n°1, M, risque pipeline Vercel) · logo WebP.
Acceptance : transpile-check 0 erreur · visuel 390×844 analysé · specs locales tests-e2e non cassées.

## §2 Vague CAISSE — intelligence commandes web (audit HEAD 9d409a33, 12 constats, frozen-risk 0)
P1 : `created_at` ISO8601 dans `SimpleOrderResource` (+ parité fixtures) · alerte sonore tracker (pattern PosComponent `_playNewOrderBeep`, branchée sur `_markFresh`, filtre web/kiosk) · badge « ✅ Payé en ligne (CB) » (payment_status=PAID + source web) · `scheduled_at` shippé + badge 🕐 « pour HH:MM » + exclusion aging avant échéance-lead (miroir KitchenReleaseRule).
P2/P3 : pill header « 🌐 N web en attente » cliquable · badge 🥡/🛵 `order_type` · `has_instruction`+`instruction` en bandeau ⚠️ · enchaînement accept→encaissement (openEncaissement) · `document.title` compteur · chips raisons de refus · tri composite (web PENDING d'abord puis âge).
Acceptance : `tests/js/{PosOrdersTrackerComponent,posOrdersTrackerWebVisibility,posTrackerStaleness,posTrackerRefundedOrphan,posOrdersTrackerPhantomCount,posTrackerCashPendingAllStatuses}.spec.js` + sentinelle `trackerEncaisserModalWiredSentinel` verts (baseline 29/29 sur les 4 principaux) + nouveaux specs (son, badges, aging programmé, tri) + PHPUnit resource si touché + build webpack OK + frozen-diff 0.

## §G Owner gates
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-D | Deploy backend VPS + push web Site-lecayenne | Owner | « deploy » explicite | BRAIN §2 | PENDING |
| G-B | Précompile Babel (change pipeline Vercel) | Owner | validation après démo locale | ce plan §1 | PENDING |

## §F DONE
Deux voies committées localement, gates verts, visuel analysé, RED dispute 0 P0/P1, BRAIN §2/§3 à jour. Deploy = gate owner.
