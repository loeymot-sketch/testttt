# Cross-cutting FRONTEND format findings (lot heal différé — après audits W2/W3/W5)

## [P2] DURÉE BRUTE non humanisée — SYSTÉMIQUE (commerçant + cuisinier)
Live :8766 Admin. Rendu de durées en MINUTES BRUTES au lieu de humanizeMinutes() (« 6 j 4 h »).
- Dashboard alertes SLA : « 17549 minutes », « 10300 minutes », « 18002 minutes » (= ~12 j) — composant SLA/RealtimeReport widget.
- KDS « Récemment servies » : « il y a 9570 min », « il y a 13375 min » — KitchenDisplaySystemComponent.vue.
- Source helper attendu : appService.humanizeMinutes() (existe per BRAIN, heal antérieur durées « 8898 min »→« 6 j 4 h »). Migration incomplète.
- reco: router ces 2 sites (dashboard SLA widget + KDS recently-served) via humanizeMinutes(). NON-frozen. ⚠️ heal APRÈS W3(KDS)+W5(dashboard) pour éviter conflit d'écriture avec les audits en cours.

## [P2] MONEY non-FR « 0.00€ » — scopé POS-cart + frontend checkout/coupon (PAS dashboard)
- appService.currencyFormat (resources/js/services/appService.js:71-76) = `parseFloat(amount).toFixed(decimal) + currency` → « 0.00€ » (point décimal + AUCUN espace). Mandat FR ADR-007 = « 0,00 € ».
- Confirmé live : panier POS Sous-total/Total « 0.00€ ». Consommateurs : PosComponent (cart totals), CheckoutComponent, CouponComponent.
- ⚠️ Dashboard utilise un AUTRE chemin (FR « 38 091,42 € » OK). PosOrdersTracker rend déjà « 19,00 € ». Donc migration FR incomplète sur le panier POS.
- reco: soit rendre appService.currencyFormat FR-aware (séparateur ',' depuis settings + espace insécable avant €) — SHARED, vérifier Vitest qui asserte le format ; soit router PosComponent cart vers le helper FR. NON-frozen. ⚠️ vérifier si kiosk consomme appService.currencyFormat avant d'éditer (W2 en cours).

## [P3] APP_URL avatar/img 404
- avatar `http://localhost:8000/storage/18/clean.jpg` → ERR_CONNECTION_REFUSED sur :8766 (APP_URL=localhost:8000 ≠ host). Config/deploy V1.

## [P3] OSS colonnes empty « — »
- mur OSS authed : colonnes « En préparation »/« Prêt » vides = « — ». À confronter à la vue PUBLIQUE (client). Libellé « Aucune commande » plus clair.

## NOTES OPS (V1-LOCAL, non-bloquantes — poste dev)
- [P3-ops] 111 domain_events non-dispatchés (dispatched_at NULL) car `queue:work` n'est PAS lancé sur ce poste dev → broadcasts en attente. **0 perte** : poll fallback actif (KDS/OSS lisent `orders` directement). Dégradation gracieuse documentée (SYNC_CONTRACT §7). En prod : lancer `queue:work redis --queue=high,default` (cf. plans/core-bulletproof PR-01). MonitorOutboxStaleness détecte (Log::error only = gap alerting connu).
- [P3-ops] Serveur :8766 (foodking_e2e) est TOMBÉ pendant la session (disque 100% → PHP ne pouvait plus écrire sessions/logs) → relancé via env-override inline (APP_URL+DB_DATABASE sur artisan serve, .env intouché). Cause racine = incident disque (worktrees), pas un bug applicatif.
- [VALIDÉ visuel] Borne kiosk lit le menu CANONIQUE : Boissons (Eau 1,00€/Coca 1,90€/Capri-Sun 1,50€), Sandwichs (Suprême 7,00/Méga 8,00/Terminator 9,00/Cayenne+Personnaliser), badges HALAL/VÉGÉTARIEN, format FR « €1,90 », 0 raw-label. Catégorie-vide non-atteignable confirmé (9 cats peuplées).
