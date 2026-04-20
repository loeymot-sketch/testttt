# T06 — SSOT pricing / totals (kiosk + POS + web)

**Date.** 2026-04-20  
**Périmètre.** `testttt` (référence) ; comparaison ponctuelle `testttt-kiosk-p93` non bloquante pour chemins identiques.

## Verdict global (audit initial) : **FAIL**

> **Remédiation T06b (2026-04-20, même session).**  
> - `OrderDetailsResource` : ajout des champs numériques `subtotal`, `discount`, `total_tax`, `total` (arrondi 2 déc.).  
> - `KioskPaymentComponent.vue` : en ligne, refus explicite si le total serveur est absent ou non fini ; hors-ligne (`offline_*`) seul repli sur `cartTotal`.  
> Fichiers alignés sur **testttt** et **testttt-kiosk-p93**. Vitest `kioskPaymentRetryGate`, `KioskPaymentRestyle` + PHPUnit ciblés : **OK**.

## Verdict global (après remédiation) : **PASS (SSOT TPE aligné)**

- **SSOT persistance (DB)** : **PASS** — les montants enregistrés sur `frontend_orders` / `orders` proviennent de `PricingService` + services métier ; les totaux client dans `OrderRequest` / `PosOrderRequest` sont **nullable** et **retirés** avant `create` quand applicable.
- **SSOT montant affiché / TPE (kiosk)** : **FAIL** — la réponse `POST /frontend/order` utilise `OrderDetailsResource`, qui **n’expose pas** de clé numérique `total` (seulement `total_currency_price`, etc.). Le front lit `res.data.data.total` puis retombe sur **`this.cartTotal`** : le terminal peut donc être piloté sur un montant **client**, pas sur le total **serveur** réellement persisté (écart possible : fidélité, promo, taxes, arrondis).

---

## Checklist V1..V8

| # | Critère | Résultat | Preuve courte |
|---|---------|----------|---------------|
| V1 | Aucun controller ne persiste `request('total')` comme vérité | **OK** | `FrontendOrderService::myOrderStore` : `unset($validatedRequest['total'], 'subtotal', 'discount'])` avant `FrontendOrder::create` (L190–193). `OrderService::tableOrderStore` : idem sur `total`/`subtotal`/`discount` (L996–997). |
| V2 | Requests : montants `nullable` / `sometimes` | **Partiel** | `OrderRequest`, `PosOrderRequest` : `subtotal`, `discount`, `total` **nullable**. `TableOrderRequest` : `subtotal` et `total` **required** mais **ignorés** côté serveur (`unset` avant create) — pas de faille SSOT DB, mais exigence client inutile / risque UX. |
| V3 | `PricingService` comme calcul principal | **OK** | `FrontendOrderService` appelle `pricingService->calculateOrder` (kiosk SSOT). `OrderService` POS / table : branche `config('pricing.use_ssot_service', true)` avec `PricingRequest::forPos` / `forTable`. |
| V4 | Kiosk : pas de `total`/`subtotal` dans payload commande | **OK** | `buildKioskOrderPayload` : `items`, `order_type`, `payment_method`, `loyalty_code`, `kiosk_promo_code`, `source` — **aucun** `total` / `subtotal` / `discount` monétaire (fichier `resources/js/store/modules/kioskCart.js`). |
| V5 | `KioskPaymentComponent` : bloquer si total serveur absent (AX4-04) | **OK (post-T06b)** | Resource expose `total` numérique ; paiement en ligne exige un nombre fini, sinon erreur ; offline conserve `cartTotal`. |
| V6 | POS : même garantie | **OK (DB)** | `OrderService::posOrderStore` : total final depuis `PricingResult` ou recalcul ; validation caisse sur **`$this->order->total`** serveur (L838–847). Front POS remplit `form.total` pour affichage / pré-check, pas comme vérité finale. |
| V7 | Cross-item guard P9.5.6 + tests | **OK** | `PricingService` : `enforceCrossItemGuards` sur variations/extras (`InvalidArgumentException` si `item_id` incohérent). `tests/Feature/Orders/CrossItemGuardTest.php` : POS + table + web. |
| V8 | Tests couvrant refus `total` client comme vérité | **Partiel** | `PricingServiceTest`, `PricingIntegrityTest`, `CrossItemGuardTest` couvrent pricing / garde-fous. **Pas** de test Vitest/PHPUnit ciblant explicitement « réponse création commande kiosk expose `total` numérique » ni « refus paiement si absent ». |

---

## Synthèse par surface

### Backend

| Zone | Observation |
|------|-------------|
| `OrderRequest` | Totaux nullable ; aligné P9.5.8. |
| `PosOrderRequest` | Idem ; validation `pos_received_amount` vs `request('total')` **préliminaire** ; total **autoritatif** recalculé dans `OrderService`. |
| `TableOrderRequest` | `subtotal`/`total` required mais **unset** avant persistance ; SSOT via `PricingService` si flag actif. |
| `CouponCheckRequest` | `total` **required** — utilisé pour **éligibilité** coupon (surface distincte de la création de commande) ; à documenter comme « total panier déclaratif », pas comme montant final de commande persisté. |
| `Kiosk/PricingPreviewRequest` | Exclusion explicite des champs financiers injectables (commentaire fichier). |

### Frontend kiosk

| Fichier | Observation |
|---------|-------------|
| `kioskCart.js` | `buildKioskOrderPayload` conforme P9.5.8 (pas de totaux). `cart_total` uniquement pour **validation promo** (`/frontend/promo/validate`), avec remise calculée serveur. |
| `KioskPaymentComponent.vue` | **Écart SSOT charge TPE** : absence de `data.total` numérique dans la resource → usage systématique du fallback **`cartTotal`**. |

### Resource API

| Fichier | Observation |
|---------|-------------|
| `OrderDetailsResource.php` | Expose `total_currency_price`, pas **`total`** (float) ni **`subtotal`** brut. Les clients kiosk qui lisent `data.total` ne reçoivent pas le montant serveur. |

---

## Actions recommandées (T06b — hors scope audit read-only)

1. **P0** — Ajouter à `OrderDetailsResource` (ou sous-ensemble « kiosk order created ») des champs **numériques** stables : au minimum `'total' => (float) $this->total` (et éventuellement `subtotal`, `total_tax`, `discount`) pour que `KioskPaymentComponent` n’ait pas besoin du fallback `cartTotal` pour le TPE.
2. **P1** — Renforcer le front : si `!isOfflineId` et que `res.data.data.total` est `null`/`undefined`, **refuser** le flux paiement carte/TR avec erreur explicite (en complément de (1)).
3. **P2** — Assouplir `TableOrderRequest` (`subtotal`/`total` → `nullable`) pour cohérence avec `OrderRequest`, une fois les clients QR table mis à jour si nécessaire.
4. **Tests** — Ajouter un test PHPUnit ou Vitest d’intégration : création commande kiosk → réponse JSON contient `total` numérique égal au total persisté.

---

## Décision

- **Invariant « rien n’écrit en DB depuis un total client »** : **respecté** (inchangé).
- **Invariant « le montant chargé au TPE = total serveur »** : **garanti en ligne** après T06b (total numérique dans la réponse + garde front).

**Clôture T06 : PASS** après application T06b (voir encadré en tête de rapport).
