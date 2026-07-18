# Chasse READ-ONLY — Parité de logique BORNE (kiosk) vs SITE WEB

**Date** : 2026-07-18 · **Mode** : read-only (aucune modification) · **DB** : foodking_e2e
**Question owner** : « la borne et le site web doivent être la MÊME logique. » Trouver les
divergences RÉELLES borne≠web sur les CHEMINS BACKEND partagés.

## Verdict d'ensemble

**Sur le money-path partagé, borne et web sont TRAITÉS IDENTIQUEMENT.** Les deux surfaces
POST `/api/frontend/order` (web : `source=5`, api.js:448) → `FrontendOrderService::myOrderStore`
→ `PricingService::calculateOrder(PricingRequest::forKiosk(...))` (FrontendOrderService.php:302-312).
Le web est délibérément traité comme « kiosk » (context=kiosk, `surface` par défaut 'kiosk').
Aucune divergence P0/P1/P2 de logique métier trouvée. Les 3 divergences réelles sont toutes
**P3** (couche affichage / défense-en-profondeur / config latente), la plupart non visibles pour
l'utilisateur web (le site standalone rend son wizard depuis `data/menu.js` local). Un gap coupon
existe mais il est **symétrique** (borne==web).

---

## DIVERGENCES RÉELLES (borne ≠ web)

### D1 [P3] `NormalItemResource.php:204-233` + `:38-41` — projection composer branch-aware pour la borne, NULL pour le web
`resolveComposerBranchId()` : pour un token `kiosk:order` (ligne 211), il lit `KioskMachine.branch_id`.
- **Borne** : le token est lié à une vraie `KioskMachine` (13 machines, toutes branch 1) → branchId=1.
- **Web** : le token guest porte `kiosk:order` MAIS aucune KioskMachine (comment FrontendOrderService:148,
  OrderRequest:352-353) → `value('branch_id')`=0 → **retourne null en early-return**. Même si le web passe
  `?branch_id=1`, il est ignoré (le branch requête n'est honoré que pour Admin, ligne 220).

Conséquence : la borne obtient `ChoiceAvailabilityResolver::snapshotForItem` (rupture par branche des
sauces/crudités/extras/addons) ; le web obtient `branchId=null` → `['variations'=>[],'extras'=>[],'addons'=>[]]`
→ **tous les choix `is_available=true`**. Idem le profil composer : borne = branch-scoped OR global ;
web = global uniquement (`whereNull('branch_id_scope')`, ligne 189).

**Pourquoi P3 (non-bloquant)** : (a) les 30 profils composer ont TOUS `branch_id_scope=NULL` → aucune
divergence de profil aujourd'hui ; (b) le site web rend son wizard localement et ignore le composer backend ;
(c) l'enforcement à la commande (`PricingService::assertItemsOrderableForBranch`, incl. composants menu)
est IDENTIQUE pour les deux. C'est une asymétrie d'affichage, pas de correction.
Preuve : NormalItemResource.php:211-217 ; KioskMachine.count()=13 (users kiosk only) ; profils branch_id_scope tous NULL.

### D2 [P3] `FrontendOrderService.php:554` — `OrderQuoteService::sealForCommit` borne-only
`if ($isKioskMachineOrder) { sealForCommit(...) }` : seule la borne scelle un devis signé
(vérif tamper total). Le web ne scelle rien. **Pas un trou de sécurité** : les deux recalculent le
total serveur via PricingService SSOT (autoritaire, non falsifiable client — FrontendOrderService:271
`unset total/subtotal/discount`). Le sceau borne = défense-en-profondeur redondante. Asymétrie de
durcissement uniquement.

### D3 [P3 latent] Data `visible_on=["pos","kiosk"]` exclut 'web' mais jamais réalisé
112 steps composer (`item_wizard_steps.visible_on`) + 2 extras de l'item 41 (« Bol Frites » : #178 Jambon,
#179 Champignons) portent `visible_on=["pos","kiosk"]` → intention data = MASQUER au web. Or le web est
traité surface='kiosk' partout : à l'affichage (`NormalItemResource.php:132` défaut `($surface ?: 'kiosk')`,
web n'envoie pas `?surface` sur `itemDetails` — api.js:184) ET à la commande (`forKiosk` → surface='kiosk',
PricingService:461-551). Donc **web voit/commande ces choix EXACTEMENT comme la borne (parité aujourd'hui)**.
**Landmine** : si le web est un jour câblé pour envoyer `surface=web` (il le fait DÉJÀ pour la validation
coupon, api.js:531), le wizard composer + ces extras DISPARAÎTRAIENT / 422 sur web. Intention config ≠ runtime.

---

## GAP SYMÉTRIQUE (borne==web, hors axe parité mais notable)

### G1 [P2-symétrique] Scope coupon `surfaces`/`branch_scope` enforced au pré-check, PAS au commit
`coupons.surfaces` existe : `ADVKIOSKB1` surfaces=["kiosk"] branch=[1], `ADVWEBONLY` surfaces=["web"] branch=[1].
- **Pré-check** (`CouponService::couponChecking`, ligne 359-366) : surface-aware. Web valide surface=web,
  borne surface=kiosk → jeux de coupons valides DIFFÉRENTS **par design** (correct).
- **Commit** : `FrontendOrderService.php:505-511` appelle `resolveCouponById(id, subtotal, userId)` — 3 args →
  `surface=null, branch=null` → `isUsableNow` **saute** les checks surfaces+branch_scope (CouponService:469-471,
  commentaire explicite). Idem POS `OrderService:561/1080/1646` (aussi 3 args).

Donc un coupon surface-restreint est redeemable depuis N'IMPORTE QUELLE surface en forçant `coupon_id`.
**Symétrique borne==web** (les deux bypassent identiquement) → PAS une divergence de parité, mais ça défait
la restriction surface voulue par l'admin. `pos.manual_discount_enabled=true` runtime → atteignable. À
signaler si l'owner veut que les coupons surface-restreints restreignent vraiment.

---

## RASSURANCES (borne == web confirmé)

| Axe | Preuve | Verdict |
|---|---|---|
| **Pricing/TVA/arrondi** | Les deux → `PricingRequest::forKiosk` (context=kiosk, tous flags round=true). `forWeb` (non arrondi) n'est utilisé QUE par `OrderService::myOrderStore` = **code mort** (aucun route/controller ne l'appelle). | Totaux byte-identiques |
| **Validation surface** (visible_on variations/extras/addons) | PricingService:461-551, `$req->context`='kiosk' pour les deux ; 0 variation restreinte, 0 item channels, 0 choix web-only → rien à rejeter | Identique |
| **Contraintes composer** (min/max_select) | `assertComposerStepConstraints` surface='kiosk' pour les deux (PricingService:557-602) | Identique |
| **Garde dispo** | `assertItemsOrderableForBranch` (incl. composants menu) PricingService:50,102 pour les deux | Identique |
| **Cross-item injection** | `enforceCrossItemGuards=true` (forKiosk) pour les deux | Identique |
| **Idempotence + authz** | même middleware + `OrderRequest::authorize` tokenCan('kiosk:order') partagé | Identique |
| **Catalogue/channels** | 0 item avec `channels` non-null → tous visibles sur toute surface ; featured/popular/list partagent `applyChannelsFilter` (surface déclarée, aucun `if kiosk/web` codé en dur) | Identique |
| **Fidélité accrual** | `AwardLoyaltyPointsOnDelivery`:84-102 taux global unique (`loyalty_points_per_euro`=1), formule `floor(total*rate)` ; seul `source_surface` diffère (tag analytics) | Identique |
| **Fidélité burn** | `applyKioskLoyaltyDiscount` appelé sans condition (FrontendOrderService:513) ; gaté par le même `assertDiscretionaryDiscountAllowed` pour les deux | Identique |
| **Stock** | `StockService::decrementForOrder` appelé pour les deux (FrontendOrderService:563) | Identique |

---

## ÉCARTÉS (analysés, non retenus)

- **Catégorie 27 « Technique (interne — upsell) » channels=["admin"]** (3 items : Menu Frites+Boisson,
  Frites Seules, Boisson Seule). Le filtre `applyChannelsFilter` porte sur `items.channels` (tous NULL),
  PAS sur la catégorie → ces items apparaissent sur `/api/frontend/item` pour les DEUX (channels item NULL).
  Le filtre channels catégorie ne joue que sur le listing catégories. Web fetch `/api/frontend/item` sans
  surface (api.js:171) = index de résolution d'ID, pas d'affichage (rend local). Pas de divergence item.
- **item-level `effective_is_available` branch-aware** (heal F-DETAILS-BRANCH-AVAIL 2026-07-15) : le backend
  `itemDetails` accepte `?branch_id` pour les DEUX ; le web ne l'envoie simplement pas (api.js:184) = gap
  CLIENT web (hors scope), pas divergence backend. Replié sous D1.
- **order_type/statut** (kiosk KIOSK+PENDING_COUNTER+auto-accept vs web TAKEAWAY+PENDING) : différence
  intentionnelle (borne=au comptoir) + déjà tracké (P1-3 healed, exclu).
- **`forWeb` non arrondi** : existe mais code mort sur le chemin frontend → aucun effet runtime.

## Reproduction (chemins comparés)
- Web : `POST /api/frontend/order` body `{source:5, order_type:10, ...}` (api.js:423-448) → FrontendOrderService::myOrderStore → forKiosk.
- Borne : `POST /api/frontend/order` (token KioskMachine) → même méthode → forKiosk (order_type forcé KIOSK/TAKEAWAY, FrontendOrderService:204-211).
- Delta pricing/validation : **0** (même PricingRequest factory, mêmes flags).
- Delta projection détails : branchId=machine (borne) vs null (web) — NormalItemResource:211-217.
