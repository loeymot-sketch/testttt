# GOAL — Audit + test RÉEL sur le Web du parcours COMPLET de chaque fonctionnalité
— 2026-07-15 · HEAD `d69a9ccc4` · dual-team test-e2e (GStack doers + adversaire) · boucle jusqu'au vert total

## §0 Doctrine
- **Test RÉEL navigateur** : Playwright 1.58 (`tests/e2e/`, 295 specs existants) + helpers `admin-auth`/`kiosk-auth`/`place-order` + recorder `mega-audit-snap` (quartet PNG+DOM+console+network par état). baseURL `http://127.0.0.1:8000`.
- **Auth** (pré-vol vérifié) : login `POST /api/auth/login` + `X-API-KEY` (.env `MIX_API_KEY`). Admin `admin@lecayenne.fr` / `Password123!` (⚠️ export `E2E_ADMIN_PASS=Password123!` — le défaut helper `123456` est périmé). POS Operator `pos@lecayenne.fr`, Chef `chef@lecayenne.fr` = `Password123!` (reset e2e). Borne `kiosk-lecayenne` / `kiosk123`.
- **Dual-team, boucle jusqu'au vert** : GStack (capture+raisonne+répare) ‖ adversaire (dispute, VISUEL d'abord, puis technique). Convergence = **2 cycles consécutifs P0+P1=0 findings identiques**. Pas de plafond.
- **Sévérité** : P0 (argent/fiscal/sécu/silencieux) & P1 (défaut user visible, i18n brut, erreur console, intégrité numérique) BLOQUENT ; P2/P3 documentés.
- **Invariants non-négociables** : numérique identique cross-surface (panier=reçu=KDS=OSS=tracker) ; 4xx/5xx sans toast = P0 ; frozen 0 ; NF525 chain OK.
- **Heal discipline** : réparation par moi (main loop), TDD, scope-minimal, frozen-aware, commit par cluster avant re-run (anti stash-regression).

## §1 Ondes = parcours complets par système (grounded : 39 router modules réels)

### Onde A — CAISSE (POS) `posRoutes/posOrderRoutes/encaissementRoutes/cashOverviewRoutes/cashSessionReportRoutes`
Parcours : login → `/admin/pos` → commande (boisson simple, produit-wizard tacos/burger, menu enfant) → instruction spéciale → remise % + motif → **mettre en attente / rappeler** → paiement **espèces** (rendu monnaie) / **carte TPE** / **split multi** → « Confirmer & Imprimer » → file « À encaisser borne » + « Commandes web » → encaisser une commande borne → **annuler** une commande (payée→403 sans pos-refund ; non-payée→OK) → **ouvrir tiroir** → **clôture Z**.
Backend : pricing SSOT (champs client unset), `fiscal_sequence_no` gap-free, `cash_movements` IN/OUT cohérents, refund-parity authz, snapshot figé, idempotency.

### Onde B — BORNE (Kiosk) `kioskRoutes` + `components/frontend/kiosk/**`
Parcours : `/kiosk/idle` → touch-anywhere → catégories → wizard par forme (sandwich, tacos M/L crudités+viandes, burger sauces, bol, galette, menu formule) → suppléments payants → panier → **upsell** → checkout → **Plan B routage comptoir** → confirmation + n° file. Backend : token `kiosk:order` (ability, TTL, rate-limit quote vs order), preview pricing == serveur, composition snapshot, `OrderCreated`→KDS.

### Onde C — KDS + OSS `kitchenDisplaySystemRoutes/orderStatusScreenRoutes`
Parcours : `/kds` (nouvelle commande apparaît <1s WS / poll fallback) → **bump (Prêt)** → **recall** → impression ticket cuisine (Chef autorisé) → `/admin/order-status-screen` lanes « En préparation »/« Prêt » → tracker client. Backend : WS `private-branch.1`, dé-dup, transitions statut, badge synchro.

### Onde D — GESTION catalogue `itemRoutes/ingredientRoutes/offerRoutes/couponRoutes/stockRoutes`
Parcours : `/admin/items` → créer/éditer produit (prix, catégorie, image) → **variations** (2 groupes attributs, noms jumeaux) → **extras** → **catégories/sous-cat** → offres → **coupons** (créer scopé surface/branche, %, dates) → **stock/rupture** → propagation caisse+borne. Backend : CRUD intégrité, unicité (item,attribut), garde catégorie, propagation events, coupon isUsableNow==checkout.

### Onde E — GESTION admin/users/rapports `adminRoutes/administratorRoutes/employeeRoutes/chefRoutes/waiterRoutes/deliveryBoyRoutes/customerRoutes/salesReportRoutes/creditBalanceReportRoutes/transactionRoutes/historiqueRoutes/settingRoutes/observabilityRoutes/messageRoutes/pushNotificationRoutes/subscriberRoutes`
Parcours : `/admin/dashboard` (donut canal=100%, CA jour, EOD PDF) → users CRUD + **RBAC** (rôle faible bloqué sur endpoints sensibles) → **garde pair manager** → rapport ventes → **EOD PDF** (tenders == Z) → transactions → historique → réglages → observability. Backend : gates permission, exactitude rapports, RBAC/IDOR.

### Onde F — STOREFRONT WEB client (backend-served) `frontendRoutes/onlineOrderRoutes` + `components/frontend/{home,menu,account,auth,checkout,search}`
Parcours : `/` → menu → recherche → produit → panier → checkout → compte/auth → suivi commande + fidélité. Backend : `FrontendOrderService`, loyalty, coupon. (Site Vercel standalone déjà audité — hors scope ici.)

### Onde G — CROSS-SURFACE (intégrité numérique)
Parcours : commande borne 1 produit → **même total** affiché caisse (file) = KDS (carte) = OSS = tracker = backend. Puis encaissement → statuts propagés partout. Mismatch centime = P0.

## §A Orchestration dual-team (Workflow, max agents parallèle)
Round R :
1. **Capture** (‖ 7 GStack agents, 1/onde) : écrit + lance un spec Playwright réel du parcours complet de l'onde, quartet par état, retourne statut + anomalies observées + `file:line` backend vérifiés (curl/artisan). Env : `E2E_ADMIN_PASS=Password123! PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000`.
2. **Adversaire** (‖ 7 agents, 1/onde) : VISUEL d'abord (PNG multimodal : texte coupé, boutons superposés, palette, états vides, modals), puis technique (i18n brut regex `^[a-z]+\.[a-z_.]+$`, 4xx/5xx sans toast, warnings console, intégrité numérique cross-surface, a11y, snapshot fiscal). Émet findings JSON P0-P3 + repro.
3. **Agrégation** : compter P0+P1 ouverts. >0 → je **heal** (TDD, frozen-aware, commit/cluster) → R++ re-run tout. =0 → cycle propre de confirmation.
4. **Convergence** : 2 cycles consécutifs P0+P1=0 findings identiques → rapport `CONVERGENCE_FINAL.md` → livrer.

## §G Gates / exclusions
Frozen strict (wizard POS Vanilla, Payment/TrancheRow, kiosk Vue, Fiscal/*, Pricing, BranchScope, StateMachine, Idempotency) = audit lecture, heal via LOCK owner. Exclusions déjà escaladées (session précédente) : coupon scopé=gate frozen PricingService, avoir wallet cash=gate contrat, scheduler box=G4, site web Vercel P0 URL backend=G2, TPE simulé assumé. Non-poussé sans gate owner G1.

## §F DONE
Les 7 ondes : parcours complet exécuté en navigateur réel (quartet), 0 P0/P1 sur 2 cycles consécutifs identiques, intégrité numérique cross-surface prouvée, frozen 0, chain NF525 OK, defects réels healés+testés, BRAIN+memory à jour.
