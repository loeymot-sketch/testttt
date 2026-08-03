# Réfutation adverse hostile — 5 findings P1 candidats

> Mission : TUER chaque finding. Verdict CONFIRMÉ (survit) / RÉFUTÉ (faux positif) / DOWNGRADE Px.
> Read-only. DB `foodking_e2e` (tinker SELECT), serveur :8000. Aucune écriture.
> Date : 2026-07-18. HEAD `246434458` (branche `pos/category-first-caisse-2026-06-23`).

| # | Finding | Verdict |
|---|---------|---------|
| F1 | CAISSE web off-book (accepté → non encaissable) | **CONFIRMÉ (P1)** — preuve-data faible, mécanisme code béton |
| F2 | BORNE upsell Menu Enfant Nuggets → 422 | **CONFIRMÉ (P1)** |
| F3 | BORNE bols boisson gratuite (offerte + imprimée) | **DOWNGRADE P2** (bug réel, opt-in, 0 rupture chaîne) |
| F4 | ADMIN /sales-report/overview RBAC bypass | **CONFIRMÉ (P1)** |
| F5 | BORNE upsell aveugle rupture branche | **DOWNGRADE P3 (latent)** — non reproductible (item 86 absent) |

---

## F1 — CAISSE web off-book — **CONFIRMÉ (P1)**

Chaîne vérifiée (tout reproduit) :
- **File `/pos/counter-collect/pending`** (routes/api.php:804-862) : clauses source `kiosk` / `pos`+COUNTER_DEFERRED / `phone`+COUNTER_DEFERRED / NULL — **AUCUNE clause `web`**. Le commentaire L868 l'AVOUE : « le panneau borne à encaisser filtre kiosk/pos/phone, PAS web ».
- **File `/web-orders/pending`** (L871-885) : filtre `status = PENDING` seulement → dès qu'une commande web est ACCEPTÉE (status=ACCEPT), elle DISPARAÎT de cette file ET n'est pas dans counter-collect → invisible aux 2 files.
- **changePaymentStatus PENDING_COUNTER→PAID** : `abort(422)` explicite (OrderService.php:2646, garde ULTRA-AUDIT V4). Confirmé.
- **Bouton « Encaisser & Valider (Kiosk) »** (OnlineOrderShowComponent.vue:58-73) : tout le groupe est `v-if status===PENDING`. Après « Accepter » (status=ACCEPT), le bouton disparaît. Confirmé.
- **RENFORCEMENT (le finder l'a sous-estimé) : le sceau doorstep livraison COD est cassé par le heal accept.** Le flip doorstep `payment_status=PAID` + allocation `fiscal_sequence_no` (OrderService ~1994-2015, fix SELF-AUDIT R2 P1) est gardé par `$wasUnpaidCash = payment_status === UNPAID` (L1916). Le heal SYNC-WEB-KDS-01 (OnlineOrderController.php:146-151) bascule UNPAID→**PENDING_COUNTER** au clic « Accepter » (order_type != POS). Donc à DELIVERED, `$wasUnpaidCash=false` → **le flip ne tire pas** → vente livrée reste PENDING_COUNTER + fiscal NULL = **off-book NF525**. Pour la livraison, « Accepter » est OBLIGATOIRE (pour assigner le livreur) → c'est le flux PAR DÉFAUT qui casse, pas un footgun.

Attaques qui NE tuent PAS le finding :
- Chemin correct existe (« Encaisser & Valider » à PENDING, CASH-01) → oui pour TAKEAWAY sur place, mais contourné dès qu'on clique « Accepter » ; pour la LIVRAISON, encaisser-maintenant est faux (le client paie le livreur) → seul « Accepter » est correct, et il casse le sceau.
- Autre chemin d'encaissement (deliveryBoy) ? Le `DeliveryBoyCashMovement TYPE_ORDER_COLLECT` (OrderService:2091) n'est qu'un mirroir de caisse livreur, dans le même bloc `$wasUnpaidCash` → ne tire pas non plus si PENDING_COUNTER.

Faiblesse honnête (pourquoi « preuve-data faible ») :
- Les 2 commandes « preuve » (id **5722, 5721** : pay=15 PENDING_COUNTER, status=**19 REJECTED**, fiscal NULL, otype=2) sont **REJETÉES = void** → fiscal NULL est NORMAL (le Z exclut REJECTED). Elles ne PROUVENT PAS une vente off-book ; elles prouvent le pattern strand→reject (accepté, non encaissable, rejeté).
- DB : `web PENDING_COUNTER fulfilled(ACCEPT+, non-terminal) fiscal-NULL = 0` aujourd'hui, `web DELIVERED not-PAID = 1`. Le système échoue SAFE côté takeaway (bloque PAID → force reject), il ne crée pas une vente PAID off-book silencieuse.
- ⇒ Le mécanisme (cul-de-sac d'encaissement + sceau livraison cassé) est RÉEL et prouvé par code ; le risque off-book livraison est P1 ; mais la formulation « 2 REJECTED prouvent l'off-book » est fausse. Verdict : **CONFIRMÉ P1** sur le défaut, avec preuve-terrain à re-produire (compléter une livraison sur commande acceptée).

---

## F2 — BORNE upsell Menu Enfant Nuggets → 422 — **CONFIRMÉ (P1)**

- **Item 40** : is_upsell=5, catégorie « Menu enfant » kiosk_upsell_include=1, status ACTIVE, 12 variations, attribut requis **id=5 « Sauce (1ère Gratuite) » min_select=1**. Seul « landmine » d'un pool upsell de 13 items (les 12 autres = desserts/boissons sans attribut requis).
- **Live probe** `GET /api/frontend/item/kiosk-upsell` (x5, limit=12) : item 40 **présent dans les 5 réponses**. (À limit=6 par défaut : ~6/13 ≈ 46%.)
- **`addAndContinue()`** (KioskUpsellComponent.vue:220-257) : ajoute l'item en 1-tap avec `item_variations:{variations:{},names:{}}` (VIDE) puis `router.push('kiosk.payment')` — **pas de wizard, aucune garde attribut requis**.
- **Reproduction validateur** (READ-ONLY, MultiVariationConstraint) :
  - `item40 item_variations=[]` → **REJECT 422** « Sélectionnez au moins 1 Sauce (1ère Gratuite) (actuel : 0) »
  - `item40 {variations:{},names:{}}` → **REJECT 422** (même)
  - `item40 avec sauce id=578` → PASS
- **Correction de mécanisme (le finder visait le quote)** : `/order/quote` = `PosController::quote(Request)` valide seulement `ValidJsonOrder` → le quote PASSE. Le 422 tombe à l'**ORDER STORE** (`Frontend\OrderController::store(OrderRequest)` → `validateOrderItemVariationsAfter`, OrderRequest L246/271). Effet net identique : **« Payer » meurt**, commande non-plaçable.

Attaques qui NE tuent PAS : l'upsell ouvre bien le paiement direct (pas le wizard) ; item 40 est bien servi live ; aucune garde amont ne prune (la requête upsell l'inclut).

---

## F3 — BORNE bols boisson gratuite — **DOWNGRADE P2** (bug réel, moins grave)

Mécanisme RÉEL confirmé (live + code) :
- **Live** `GET /api/frontend/item/details/41` : has_menu=**false**, step `boisson` **addon_role='drink' min=0 max=1**, addon id=99 « Boisson Seule » role='drink' price=**2**.
- `ADDON_ROLE_TO_TYPE['drink']='menu'` (KioskWizardComponent.vue:341) → `resolveExplicitStepType` classe le step boisson en **type 'menu'** → rend `KioskStepMenu` (chooser formule Menu complet/Frites/Boisson/Sans) sur un bol qui n'est PAS un menu. Mis-render confirmé.
- `menuPrice = getKioskMenuAddonPrice()` cherche un addon dont le nom contient « menu » → « Boisson Seule » ne matche pas → **retourne 0** → aucun « +prix » affiché (perçu gratuit).
- `boissonList` : `kioskDrinkAddonRowsFromItem` filtre par `kioskIsDrinkAddon`. « Boisson Seule » matche `GENERIC_DRINK_OPTION_REGEX` → `kioskIsDrinkAddon=false` → l'addon propre (id=99) est **exclu** → repli sur `globalBoissonCatalogRows` (mappés SANS `addonId`, L336-342).
- Le push addon boisson (KioskWizardComponent.vue:2044-2050) exige `boissonMeta?.addonId` → **null** (catalogue global) → **addon NON poussé** → backend facture le bol seul (7,90).
- Le NOM de la boisson est poussé dans l'`instruction` (L2160-2163) → **imprimé au ticket cuisine**. Donc « choisie mais perdue (facturation) + imprimée » = RÉEL.

Pourquoi DOWNGRADE P2 (pas P1) :
- **Opt-in** : step `min_select=0` ; l'auto-select `full` (mounted) exige `has_menu && default_menu_kiosk` = false pour le bol. Le client doit ACTIVEMENT choisir la formule 'full'/'boisson' PUIS une boisson. « Sans menu » → bol 7,90 correct (optionnel non choisi = normal).
- **Pas de rupture de chaîne NF525** : la commande scellée est cohérente en interne (facturée 7,90, snapshot 7,90) ; le reçu fiscal client est correct. La boisson n'apparaît QUE dans l'instruction cuisine (fuite d'atelier + fuite de CA ~2 €), pas dans le Z signé.
- Reste un vrai défaut (bol propose « Menu complet 🍟🥤 », boisson préparée non facturée) — à corriger, mais severity P2.

---

## F4 — ADMIN /sales-report/overview RBAC bypass — **CONFIRMÉ (P1)**

Preuve FRAMEWORK (la plus forte possible) — `php artisan route:list --json`, middleware RÉSOLU :
```
GET api/admin/sales-report          @index               → ... PermissionMiddleware:sales-report  ✅
GET api/admin/sales-report/export   @export              → ... PermissionMiddleware:sales-report  ✅
GET api/admin/sales-report/pdf      @pdf                 → ... PermissionMiddleware:sales-report  ✅
GET api/admin/sales-report/overview @salesReportOverview → ... (AUCUN PermissionMiddleware)       ❌
```
- Route (routes/api.php:1158) mappe la méthode **`salesReportOverview`**.
- `->middleware(['permission:sales-report'])->only('index','export','pdf','overview')` (SalesReportController.php:40) — le `->only()` filtre par **NOM DE MÉTHODE** (`ControllerDispatcher::methodExcludedByOptions`, Laravel 9.52). `in_array('salesReportOverview', ['index','export','pdf','overview'])` = **false** → middleware EXCLU pour l'overview.
- Le heal daté « REP-AUTHZ-01 2026-06-01 » a mis le segment d'URI `'overview'` au lieu du nom de méthode `'salesReportOverview'` → **heal INEFFICACE** (faux sentiment de sécurité).
- Middleware restant sur overview : `Authenticate:sanctum` + `BlockKioskTokenFromAdminRoutes`. ⇒ TOUT staff authentifié non-borne (POS Operator, Chef, sans permission `sales-report`) peut GET l'agrégat de CA.
- Contexte V1-LOCAL : blast-radius = employés internes du resto ; mais la frontière RBAC est définitivement cassée, fix 1 ligne. **CONFIRMÉ P1.**

Attaques qui NE tuent PAS : pas d'autre gate (group/prefix) — le JSON prouve l'absence ; POS Operator peut l'atteindre (auth:sanctum + block_kiosk_token n'exigent pas la permission).

---

## F5 — BORNE upsell aveugle rupture branche — **DOWNGRADE P3 (latent)**

- **Item 86 : ABSENT** de la table items (0 rows) → **non reproductible aujourd'hui**. La « preuve » est purement théorique.
- Gap structurel RÉEL : `kioskUpsell` (Frontend/ItemController.php:81-107) requête `is_upsell` + `whereHas('category', kiosk_upsell_include)` — **ne joint PAS `item_branch_availability`**, retombe sur `is_available` global. Aucun scope global n'applique la dispo par branche sur cette requête (contrairement à `itemDetails` qui, lui, a reçu le fix branch-aware F-DETAILS-BRANCH-AVAIL 2026-07-15 via `?branch_id=`).
- ⇒ Un item is_upsell en rupture SUR UNE BRANCHE serait proposé à l'upsell → 422 au paiement (même famille que F2, contourne le prune). Mais LATENT : exige qu'un item du pool upsell soit mis en rupture par branche. Aujourd'hui aucun.
- Verdict : **DOWNGRADE P3 (latent/théorique)** — vrai trou de conception, non déclenchable en l'état, à durcir en prévention (joindre la dispo branche à l'upsell, comme itemDetails).

---

### Note transverse (F2 + F5 = même racine)
Les surfaces d'ajout « 1-tap » qui court-circuitent le wizard (upsell) ou la dispo-branche (upsell) injectent des lignes non-conformes que le validateur serveur rejette (422) AU PAIEMENT, sans recours client. F2 est déclenchable AUJOURD'HUI (item 40 réel) ; F5 est le même défaut, latent. Un fix commun (valider/normaliser la ligne upsell côté client OU pruner les items à profil requis / rupture branche du pool upsell) couvre les deux.
