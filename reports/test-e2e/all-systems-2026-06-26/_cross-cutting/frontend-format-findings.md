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

## ✅ PREUVE E2E LIVE TERRAIN (capstone, 2026-06-26)
Parcours client RÉEL piloté Playwright sur :8766 (foodking_e2e), bundles rebuildés :
1. Borne idle → « À emporter » → catégories (menu canonique FR) → ajout Coca-Cola €1,90.
2. Panier : **bloc promo CACHÉ** (heal promo live-vérifié `promoBlockVisible:false`), total €1,90 FR.
3. Upsell (Glace/Suprême/Méga/Terminator/Menu Enfant prix canon) → « Non merci ».
4. Paiement Plan-B « Paiement à la caisse » → Confirmer.
5. Cash-instruction : n° **#A0001**, « Réglez votre commande à la caisse : **espèces, carte bleue ou titres-restaurant** » (heal copy Plan-B live-vérifié, fini « espèces uniquement »).
6. **Commande 5179 créée** (serial 2606265179, source=kiosk, PENDING_COUNTER, total 1,90, pos_payment_method=6 COUNTER_DEFERRED).
7. **Borne→KDS sync PROUVÉ** : `GET /api/admin/kds-order` expose `id:5179` → la commande est sur l'écran cuisinier en temps réel.
→ Heals live-vérifiés : promo caché, copy Plan-B CB+tickets, money FR €1,90, menu canonique. encaissement→PAID→fiscal prouvé par tests + 791 commandes gap-free.
Note : 5179 = commande test PENDING_COUNTER (auto-nettoyée par CleanupStalePendingKioskOrders après TTL).

## 🏆 BOUCLE TERRAIN COMPLÈTE END-TO-END — PROUVÉE LIVE (NF525 intègre)
Commande 5179 pilotée à travers les 3 acteurs sur endpoints réels (:8766 foodking_e2e) :
- CLIENT (borne, UI live) : place commande Plan-B → PENDING_COUNTER, queue A0001.
- CUISINIER (KDS, API réelle) : ACCEPT(4)→PREPARING(7)→PREPARED(8) — prépa Plan-B pendant non-payé (release-guard OK).
- COMMERÇANT (encaissement, API réelle) : counter-collect cash mode=1 → PAID(5).
- FISCAL : fiscal_sequence_no=2574 alloué (2573+1 = GAP-FREE), TVA 0,17€ calculée backend.
- NF525 : CHAIN OK 4 branches après transaction · audit_logs 4635→4637 (append-only) · réconciliation order=fiscal=transaction=1,90€ parfaite.
→ Les 5 systèmes prouvés FONCTIONNELS ENSEMBLE sur le terrain réel. Token terrain révoqué (cleanup).

## ⚠️ FIX ENV (à valider owner) — .env DB_DATABASE aligné sur la canonique
- Symptôme : serveur :8766 500 sur tout (« Unknown column branches.deleted_at ») après un restart qui a perdu l'env-override → fallback sur `.env DB_DATABASE=foodking` (coquille abandonnée SANS deleted_at, BRAIN).
- Fix : `.env DB_DATABASE=foodking → foodking_e2e` (la DB canonique réelle : 2812 orders, fiscal, schéma complet ; backup `.env.bak-dbfix-2026-06-27`). Aligne la config sur la DB que l'owner utilise vraiment. PAS une migration (foodking intouchée, footgun BRAIN respecté).
- Owner : valider ce pointage .env (le .env pointait historiquement vers la coquille ; le canonique est foodking_e2e). APP_DEBUG re-mis à false après diagnostic.

## [P3-deploy] APP_URL doit = host servi (sinon SPA full-load échoue + avatar 404)
- Symptôme : navigation directe /admin/settings/payment-terminals → 9 erreurs console (API appelée sur localhost:8000 au lieu du host servi). Cause : `window.foodkingConfig.apiUrl` = `config('app.url')` = `.env APP_URL=http://localhost:8000` (placeholder dev) ≠ host réel. Aussi : avatar/images 404.
- Fix test : `.env APP_URL → http://127.0.0.1:8766` (host servi). Owner deploy : APP_URL doit pointer le vrai host de la boîte mono-poste. PAS un défaut de page (les pages sont OK ; seul apiUrl est mal pointé). Backup `.env.bak-*`.
