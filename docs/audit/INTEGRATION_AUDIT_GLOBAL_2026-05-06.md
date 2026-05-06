# INTEGRATION AUDIT GLOBAL — Cycle 6 (2026-05-06)

**Mission** : Audit READ-ONLY de la cohérence intégration des 2 nouvelles features POS — Codes Promo Dashboard + Multi-paiement / Split — à travers TOUTE la chaîne FoodKing (POS / borne / KDS / sync centrale / web futur / mobile futur).

**Auditeur** : Claude (orchestrateur central, CLAUDE.md §1)
**Méthode** : Lecture de code primaire (chemins:lignes traçables), aucun fichier modifié.
**Verdict global** : `heal` (cf. §10) — la feature Codes Promo a une régression silencieuse de scope au niveau backend ; Split Payment est sain (frontend autonome, plan backend P12 propre).

---

## RÉSUMÉ EXÉCUTIF (3 lignes)

1. **Codes Promo Dashboard** : UI, DB, broadcast, validation FormRequest et invariant `Coupon::isUsableNow()` sont **tous présents et cohérents** — MAIS `validateCouponForOrder()` (le seul point d'entrée runtime de validation) **n'appelle PAS `isUsableNow()`**. Les nouveaux champs `status / valid_days_of_week / valid_hours_* / branch_scope / surfaces / max_uses_global` sont écrits, fanned-out par Pusher, MAIS **non vérifiés à la rédemption**. Tests `CouponValidityTest` passent sur l'invariant directement, jamais sur le path production.
2. **Split Payment Frontend** : refonte additive propre, frozen-zone respectée, `payment_breakdown[]` envoyé mais silencieusement ignoré par Laravel (intentionnel V1, plan P12 prêt). Helpers receipt purement additifs, kiosk non impacté.
3. **Sync centrale** : `CouponChanged` correctement enregistré dans `EventContract` (BROADCAST_MAP + REQUIRED_PAYLOAD_KEYS) ; listener fan-out par branche idempotent ; outbox + `DispatchDomainEventsJob` cohérents.

---

## SECTION 1 — POS (Caisse)

### 1.1 Codes Promo — POS input flow (RÉGRESSION SILENCIEUSE)

**Path tracé** :
1. Cashier saisit code promo dans cart POS (`PaymentComponent.vue` / cart) → POST `/api/frontend/coupon/coupon-checking` (`routes/api.php:1070`)
2. → `Frontend\CouponController::couponChecking()` (`app/Http/Controllers/Frontend/CouponController.php:36`)
3. → `CouponService::couponChecking()` (`app/Services/CouponService.php:350`)
4. → `CouponService::resolveCouponByCode()` (`app/Services/CouponService.php:376`)
5. → `CouponService::validateCouponForOrder()` (`app/Services/CouponService.php:404`) — **point de validation final**
6. À la rédemption (création commande POS) : `OrderService` lignes 469 / 803 / 1204 → `resolveCouponById()` → mêmes mêmes mêmes 3 étapes ⇒ mêmes vulnérabilités.

**Constat** :

```php
// CouponService.php:404-438 — actuel
private function validateCouponForOrder(?Coupon $coupon, float $subtotal, int $userId): Coupon
{
    if (!$coupon) { ... }
    if ((float) $coupon->minimum_order > $subtotal) { ... }
    $now = Carbon::now();
    if ($coupon->start_date && $now->lt(Carbon::parse($coupon->start_date))) { ... }
    if ($coupon->end_date && $now->gt(Carbon::parse($coupon->end_date))) { ... }
    $limitPerUser = (int) ($coupon->limit_per_user ?? 0);
    if ($limitPerUser > 0) { ... } // limite par user
    return $coupon;
}
```

**JAMAIS appelé** :
- `Coupon::isUsableNow($branchId, $surface, $now)` (`app/Models/Coupon.php:90`) qui valide `status`, `valid_days_of_week`, `valid_hours_start/end` (incl. wrap minuit), `branch_scope`, `surfaces`, `max_uses_global`.

**Conséquence opérationnelle** :
- Un coupon `status=INACTIVE` peut être appliqué (Eloquent ne filtre pas).
- Un coupon scopé à `branch_scope=[1]` peut être utilisé sur la branche 5.
- Un coupon scopé à `surfaces=['kiosk']` peut être appliqué en POS.
- Un coupon scopé à `valid_days_of_week=['mon','tue']` peut être utilisé un dimanche.
- Un coupon `max_uses_global=100` avec `usage_count=200` reste utilisable (note : `usage_count` n'est de toute façon pas auto-incrémenté — cf. §8.3).

**Sous-gap** : `CouponCheckRequest` (`app/Http/Requests/CouponCheckRequest.php:25-32`) ne valide ni `branch_id` ni `surface`. Donc même si `validateCouponForOrder` appelait `isUsableNow($branchId, $surface)`, le service ne reçoit pas ces 2 paramètres depuis le request HTTP.

**Sévérité** : P0 — régression de business invariant (CLAUDE.md §3.7 "Backend is the source of truth"). La feature est **publiquement annoncée comme livrée** mais n'est pas effective.

### 1.2 Split Payment — POS payment modal (FRONTEND OK, BACKEND ATTENDU P12)

**Path tracé** :
1. Cashier toggle modal mode `multi` dans `PaymentComponent.vue` (Vue local data `paymentMode`).
2. Tranches éditées via `PosV5TrancheRow.vue`.
3. Validation locale via `posSplitPayment.js` (cents-int math, `canConfirm`, `serializeTranches`).
4. `runConfirmOrderAttempt()` (`PaymentComponent.vue:631-672`) injecte :
   ```js
   {
     pos_payment_method: dominantMode,
     payment_breakdown: breakdown,         // <-- nouveau, ignoré
     pos_received_amount: cashTranche?.tendered ?? null,
     pos_payment_note: 'multi-tender',
   }
   ```
5. POST `/api/admin/pos` → `PosOrderRequest` (`app/Http/Requests/PosOrderRequest.php:55-96`) — **`payment_breakdown` n'est PAS dans `rules()`**.
6. Laravel `$request->validated()` ne le retourne donc pas → `OrderService::posOrderStore` continue son path single-tender legacy.

**Conséquence** :
- Côté UI : feature visible et fonctionnelle (toggle OK, tranches OK, `canConfirm` correct, change calc OK).
- Côté backend : ordre persisté en single-tender via le path legacy. Le ticket NF525 affiche `pos_payment_method` legacy = mode dominant.
- **Aucune persistance en table `order_payments`** — c'est intentionnel V1 (cf. PLAN_P12).

**Sentinel** : `PosOrderRequest` accepte le champ inconnu sans erreur (Laravel default behavior). Le `PaymentComponent.vue` ligne 638 documente explicitement ce comportement. Backward compat single-mode 100% préservée.

**Sévérité** : P3 — à exécuter PLAN_P12 pour activation backend.

### 1.3 A11y POS

Les 2 nouvelles UIs touchent le POS :
- `CouponListComponent.vue` + `CouponCreateComponent.vue` (admin)
- `PaymentComponent.vue` (refonte 3-mode)
- `PosV5TrancheRow.vue` (atom V5)

L'audit `axe-core` du cycle 5 (0 violations critiques sur POS) **n'a pas été re-couru**. Les rapports d'implémentation (§9 Coupon, §2.2 Split) déclarent les a11y correctes (`role`, `aria-live`, `aria-label`, labels associés) mais **aucune preuve `axe-core` post-cycle 6**.

**Sévérité** : P2 — à re-tester cycle 7 (recommandation §9.1).

---

## SECTION 2 — Borne Kiosk

### 2.1 Codes Promo Kiosk

**Recherche** : `grep -rn "coupon\|Coupon" resources/js/components/frontend/kiosk` → **1 seule occurrence** dans `KioskCartComponent.vue:531` (commentaire de fonction parlant de "coupons attachés à un slot précis"). **Aucun appel `coupon-checking` depuis la borne**.

**Conclusion** : la borne ne propose actuellement **PAS** de saisie de code promo. La feature Codes Promo Dashboard prévoit `surfaces=['kiosk']` mais **aucun consommateur kiosk n'existe**. C'est un trou produit, pas une régression.

**Sévérité** : P3 — feature backlog. Si business veut activer kiosk-only promos, il faut ajouter un input UI dans le flow `KioskCartComponent` → `KioskPaymentComponent`.

### 2.2 Split Payment Kiosk

La borne paie via TPE unique (single-tender hardware). Aucune action multi-tender possible. Vérification `KioskPaymentComponent.vue` :
- N'importe **pas** `posReceiptBuilder` (vérifié : `grep posReceiptBuilder` → 0 résultat sur `KioskPaymentComponent.vue` — seulement sur `PosOrderReceiptComponent.vue` et `ReceiptComponent.vue`).
- N'utilise pas `posSplitPayment.js` (helpers admin POS-only).

**Conclusion** : aucune régression kiosk possible des modifs `posReceiptBuilder.js` (purement additives, fonctions nouvelles `buildPaymentBreakdownLines` + `sumPaymentBreakdownTotal`). `formatPaymentsBreakdown` (ligne 34, signature inchangée) est l'unique fonction consommée par les receipts existants.

**Sévérité** : aucune.

### 2.3 Cohérence backend

Si un jour la borne consomme `coupon-checking`, elle hérite **du même bug Section 1.1** : aucun filtrage `surfaces=['kiosk']` côté validation. Donc une promo POS-only sera honorée en borne si saisie. Régression latente.

---

## SECTION 3 — KDS (Cuisine)

### 3.1 Codes Promo KDS

KDS ne touche pas les coupons (scope = items en préparation, statut commande). `KitchenDisplaySystemOrderService` n'importe ni `Coupon` ni `OrderCoupon`.

**Sévérité** : aucune.

### 3.2 Split Payment KDS

KDS lit `OrderCreated` (broadcast `private-branch.{id}` event_type=`order.created`) pour afficher les nouvelles commandes. Vérification `PersistOrderCreatedToOutbox.php:24-35` :

```php
'payload' => [
    'order_id', 'queue_number', '_origin', 'payment_method',
    'payment_status', 'payment_pending_counter', 'status',
    'order_type', 'total', 'created_at',
],
```

**Aucun champ `payment_breakdown`** — V1 acceptable car KDS ne se soucie pas du moyen de paiement. Pour le Z-report fiscal et l'audit NF525 par tranche, il faudra (post-P12) :
- Soit ajouter `payment_breakdown` au payload `OrderCreated` (rétrocompat additive),
- Soit créer un nouvel event `OrderPaymentTrancheConfirmed` par tranche (recommandé §11 du PLAN_P12, audit fiscal granulaire).

**Sévérité** : P2 (post-P12) — à planifier dans le suivi P12.

### 3.3 OrderStatusChanged broadcast

Aucune modif `PersistOrderStatusChangedToOutbox` ni `OrderStatusChanged` event. Frozen.

---

## SECTION 4 — Sync centrale (Outbox + Events)

### 4.1 EventContract — extension `CouponChanged`

`app/Domain/Events/EventContract.php:34-66` :

```php
BROADCAST_MAP = [
    ...
    'CouponChanged' => EventType::COUPON_CHANGED, // ligne 46 — additif
];
REQUIRED_PAYLOAD_KEYS = [
    ...
    EventType::COUPON_CHANGED => ['coupon_id', 'change_type'], // ligne 65
];
```

`assertEnvelopeValid` / `assertPayloadValid` : forward-compat (ligne 149) — un type non listé pass. Donc l'ajout est non-breaking.

**Validation contractuelle** : OK.

### 4.2 PersistCouponChangedToOutbox (fan-out + idempotence)

`app/Listeners/PersistCouponChangedToOutbox.php` :
- **Fan-out par branche** : si `event->branchScope` non vide → loop sur ces branches. Sinon → loop sur toutes les branches `Status::ACTIVE` (`Branch::query()->where('status', Status::ACTIVE)`).
- Channel : `private-branch.{id}` (ligne 60) ✓ identique à `PersistCatalogChangedToOutbox`.
- `broadcast_as` = 'CouponChanged' (ligne 61) ✓ aligné `BROADCAST_MAP`.
- Idempotence (ligne 80-90) : dedup sur `(event_type, aggregate_type, aggregate_id, branch_id, correlation_id, payload->change_type)`. ✓
- Dispatch après commit (ligne 73-77) via `DispatchDomainEventsJob` ✓.

**Validation listener** : OK.

### 4.3 Payload contient-il `branch_id` ?

`payload` (lignes 51-59) inclut `branch_id` (denormalisé), `coupon_id`, `change_type` (mutation type), `code`, `status`, `surfaces`, `payload_diff`. Le DomainEvent column `branch_id` (ligne 50) porte aussi le scope.

**Sécurité branch isolation** (CLAUDE.md §3.8 "Branch isolation must never be weakened") : le client Pusher subscribe à `private-branch.{ownBranchId}` uniquement → broadcast bien contenu. ✓

### 4.4 Test EventContract

`tests/Feature/EventContractTest.php` étendu à `'promo.coupon_changed'`. 9 tests PASS selon agent 1. Pas de re-vérification possible (sandbox phpunit non lancé). Crédible vu la cohérence du diff.

### 4.5 Pas de nouvel event pour Split Payment V1

Intentionnel — la feature backend P12 introduira `OrderPaymentTrancheConfirmed` (par tranche, audit fiscal NF525 granulaire). Pour V1 frontend-only, aucun event requis (rien n'est persisté).

---

## SECTION 5 — Site web futur (V2)

### 5.1 Lecture des coupons publics

**État actuel** : `routes/api.php:1067-1072` :
```
GET  /api/frontend/coupon          → CouponResource collection (auth?)
POST /api/frontend/coupon/coupon-checking  → CouponCheckResource (auth si auth()->id())
```

Ces routes sont sous le préfixe `frontend` middleware `['installed', 'apiKey', 'localization']` (api.php:986) — **PAS** `auth:sanctum` au niveau du group, mais l'index appelle `couponDateWise()` qui ne nécessite pas d'auth.

**Verdict** : il existe déjà un endpoint public ish (`GET /api/frontend/coupon` — index) qui retourne tous les coupons dans la fenêtre de dates active. **MAIS** il ne filtre PAS sur `status=ACTIVE`, ni `surfaces`, ni `branch_scope`. Donc le site web V2 verrait :
- Coupons inactifs (regression)
- Coupons scopés POS-only / kiosk-only (fuite scope cross-surface)

**Recommandation** : créer ou amender `GET /api/public/coupons/active?branch_id=X&surface=web` qui appelle `Coupon::active()->get()->filter(fn $c => $c->isUsableNow($branchId, 'web'))`. Endpoint dédié, scope explicite, public-friendly.

**Sévérité** : P2 — pré-requis V2 site web.

### 5.2 Split Payment — site web

Le site web V2 fera Stripe / TPE web → single-tender natif. **Multi-paiement non applicable** côté online. Mais le data model `order_payments` (post-P12) supportera nativement (1:N).

---

## SECTION 6 — App mobile futur (V2)

### 6.1 Codes Promo mobile

L'app mobile V2 affichera idéalement :
- Historique commandes (déjà couvert par `/api/frontend/order` auth:sanctum)
- Codes promo disponibles pour le user (filtrés par surfaces=['mobile'], branch_id user)

**Endpoint manquant** : `GET /api/customer/{id}/active-coupons?branch_id=X` (filtrage mobile). À ajouter post-V2.

**Sévérité** : P2 — backlog.

### 6.2 Split Payment mobile

Pas applicable (pas de paiement physique multi-tender côté app).

---

## SECTION 7 — Cohérence cross-surface

| Feature | POS | Kiosk | KDS | Web (V2) | Mobile (V2) |
|---|---|---|---|---|---|
| **Codes Promo lecture (entrée code)** | OUI bug (1.1) | NON (pas d'UI) | n/a | manquant endpoint public actif | manquant endpoint customer |
| **Codes Promo écriture (admin CRUD)** | OUI Dashboard ✓ | n/a | n/a | n/a (Dashboard admin only) | n/a |
| **Codes Promo broadcast** | reçu via `private-branch.{id}` ✓ | reçu (idem) | reçu mais ignoré | reçu si subscribed | reçu si subscribed |
| **Codes Promo validation runtime (`isUsableNow`)** | **NON appelé** (1.1) | n/a | n/a | n/a | n/a |
| **Split Payment tranches (UI)** | OUI ✓ frontend | NON (single-tender TPE) | n/a | n/a | n/a |
| **Split Payment persistance backend** | NON V1 (PLAN_P12) | n/a | n/a | n/a | n/a |
| **Receipt breakdown affiché** | OUI live (preview) ✓ | non concerné (formatPaymentsBreakdown unchanged) | n/a | n/a (V2) | n/a |
| **OrderCreated Outbox** | ✓ | ✓ | ✓ consommateur | ✓ (V2) | ✓ (V2) |
| **Frozen-zone OrderService/PaymentService** | intacte ✓ | intacte ✓ | intacte ✓ | intacte ✓ | intacte ✓ |

---

## SECTION 8 — Risques d'intégration identifiés

### 8.1 P0 — Coupon scoping non-effectif au runtime (BLOQUANT MERGE)

**Voir §1.1**. `validateCouponForOrder()` n'appelle pas `isUsableNow()`. Tests `CouponValidityTest` (11 PASS) testent l'invariant **directement sur le model**, jamais via le path HTTP réel.

**Impact** : feature publiquement annoncée mais inopérante. Violation CLAUDE.md §3.10 "Tests passing does not automatically mean the implementation is acceptable".

**Fix minimal** :
```php
// CouponService.php:validateCouponForOrder()
private function validateCouponForOrder(?Coupon $coupon, float $subtotal, int $userId, ?int $branchId = null, ?string $surface = null): Coupon
{
    // ... existing checks ...
    if (!$coupon->isUsableNow($branchId, $surface)) {
        throw new Exception(trans('all.message.coupon_not_usable_now'), 422);
    }
    return $coupon;
}
```

Et propager `branch_id` + `surface` depuis :
- `CouponCheckRequest::rules()` (ajouter `branch_id`, `surface`)
- `CouponService::resolveCouponByCode($code, $subtotal, $userId, $branchId, $surface)`
- `OrderService` lignes 469/803/1204 (passer `$order->branch_id` + surface POS/web/kiosk)
- `FrontendOrderService` lignes 406/417 (idem)
- `Pricing/DiscountCalculator.php:17` (idem)

**Sentinel test à ajouter** : appel HTTP réel `POST /api/frontend/coupon/coupon-checking` avec coupon `status=INACTIVE` → assert 422.

### 8.2 P1 — `usage_count` n'est jamais incrémenté

L'agent 1 reconnaît (§6.1 du rapport COUPONS) : *"`usage_count` increment is wired but not auto-incremented at order redemption. The frozen zones (`OrderService`, `FrontendOrderService`) prevent direct hooking."*

**Conséquence** : `max_uses_global` est inopérant (toujours `usage_count=0`). Même si §8.1 est corrigé, le quota global ne peut jamais expirer.

**Fix** : `OrderCouponObserver` sur l'event `created` du model `OrderCoupon`, atomic `Coupon::where('id', $orderCoupon->coupon_id)->increment('usage_count')`. Frozen-zone respectée (Observer = boundary clean).

### 8.3 P1 — Webpack-mix bundle stale

Agent 1 §6.2 : *"Vue assets not rebuilt in CI for this cycle. The webpack-mix bundle (`public/js/app.js`) was last built before this cycle's Vue edits."*

**Conséquence** : en production, l'utilisateur final NE VOIT PAS la section avancée (`data-section="advanced-promo-fields"`) du drawer Coupon. L'UI livrée reste invisible jusqu'à `npm run prod`.

**Fix** : `npm run prod` pré-merge.

### 8.4 P2 — A11y régression possible non vérifiée

Cycle 5 a établi 0 violations critiques. Les 2 nouvelles UIs (Coupon List/Create + Payment Multi + PosV5TrancheRow) **n'ont pas été re-couvertes par axe-core** post-cycle 6.

### 8.5 P2 — `GET /api/frontend/coupon` (index) ne filtre pas par scope

Le couponDateWise() actuel ne filtre que par fenêtre de dates. Un site web V2 (ou un client API public) verrait des coupons `INACTIVE`, `surfaces=['pos']`, `branch_scope=[3]`. Fuite de scope.

### 8.6 P2 — `OrderCreated` payload ne contient pas `payment_breakdown`

Pas de blocage V1 (KDS s'en fiche). Mais le Z-report fiscal NF525 par tranche ne pourra pas être généré sans soit (a) ajouter le champ au payload, soit (b) lire la table `order_payments` post-P12.

### 8.7 P3 — Kiosk ne propose pas de saisie code promo

Trou produit (pas régression). À planifier si business veut activer le canal kiosk pour les promos.

---

## SECTION 9 — Recommandations cycle 7 (post-merge)

### 9.1 (P0) Fix scoping runtime — CouponService

**Owner** : Cursor/Codex (mini-plan ~80 lignes).

1. Étendre `CouponCheckRequest::rules()` avec `branch_id` (nullable, integer, exists:branches,id) et `surface` (nullable, string, in:pos,kiosk,web,mobile).
2. Étendre les signatures `couponChecking`, `resolveCouponByCode`, `resolveCouponById`, `validateCouponForOrder` avec `?int $branchId, ?string $surface`.
3. Appeler `$coupon->isUsableNow($branchId, $surface)` dans `validateCouponForOrder()` après les checks legacy.
4. Mettre à jour `OrderService` (3 call-sites lignes 469, 803, 1204), `FrontendOrderService` (lignes 406, 417), `Pricing/DiscountCalculator.php:17` pour passer `$order->branch_id` + surface dérivée de `$order->source_surface` ou contexte controller.
5. **Sentinels** : 1 test E2E par dimension (`status=INACTIVE` → 422 ; `branch_scope` mismatch → 422 ; `surfaces=['kiosk']` en POS → 422 ; `valid_days_of_week` jour off → 422 ; `valid_hours` plage off → 422).

**Note frozen-zone** : étendre une signature de méthode publique reste boundary-safe (additif, params optionnels). Ne pas réécrire `validateCouponForOrder`, juste le compléter.

### 9.2 (P1) `OrderCouponObserver` pour `usage_count++`

```php
// app/Observers/OrderCouponObserver.php (nouveau)
public function created(OrderCoupon $oc): void {
    Coupon::where('id', $oc->coupon_id)->increment('usage_count');
}
```

Wired dans `EventServiceProvider::$observers` ou `boot()`. Frozen-zone OK.

### 9.3 (P1) Rebuild webpack-mix

`npm run prod` ; vérifier `public/js/app.js` contient `data-section="advanced-promo-fields"` post-build.

### 9.4 (P2) Re-run axe-core sur les 2 nouvelles UIs

- `CouponListComponent.vue` + `CouponCreateComponent.vue`
- `PaymentComponent.vue` (mode multi)
- `PosV5TrancheRow.vue`

Threshold cycle 5 = 0 violations critiques. Maintenir.

### 9.5 (P2) Endpoint public actif filtré

`GET /api/public/coupons/active?branch_id=X&surface=web` qui appelle `Coupon::active()->get()->filter(fn $c => $c->isUsableNow($branchId, 'web'))`. Pré-requis site web V2.

### 9.6 (P2) Plan event `OrderPaymentTrancheConfirmed` post-P12

Audit fiscal NF525 par tranche. Une ligne d'event par tranche (ou un payload enrichi sur `OrderCreated`). À discuter avec architecture.

### 9.7 (P3) Test de charge

100 codes promo concurrents (lecture/validation) + 50 paiements split (post-P12) → assert 0 race condition sur `usage_count` (atomic increment), 0 deadlock sur `order_payments`.

---

## SECTION 10 — Verdict global cycle 6

Selon CLAUDE.md §8.

| Axe | Verdict | Justification |
|---|---|---|
| **Frontend cohérence (Coupon Dashboard)** | `continue` | UI propre, validation FormRequest correcte, store Vuex aligné, drawer additif respectant DS admin. |
| **Frontend cohérence (Split Payment)** | `continue` | Refonte additive, frozen-zone respectée, helpers cents-int math testés (38 vitest selon §4 du report), receiptBuilder additif sans régression possible. |
| **Backend integrity (Coupon Dashboard)** | **`heal`** | Régression silencieuse §1.1 — invariant model NON wired sur runtime. Tests passent mais scope non effectif. À fixer cycle 7 avant claim "feature complete". |
| **Backend integrity (Split Payment)** | `continue` | Frozen-zone 100% respectée, plan P12 prêt, backward compat préservée. Field inconnu silencieusement ignoré (Laravel default). |
| **Sync centrale / Outbox** | `continue` | EventContract + listener + idempotence + fan-out par branche : tout aligné contractuellement. |
| **A11y** | `heal` | Pas re-testé axe-core post-cycle 6. Cycle 7 doit re-courir. |
| **Fiscal NF525** | `heal` | OrderCreated payload ne contient pas `payment_breakdown` ; Z-report par tranche non possible sans amendement V2 ou post-P12. À planifier. |
| **Sécurité (branch isolation)** | `continue` | `private-branch.{id}` respecté, `branch_scope` denormalisé dans payload, dedup correct. |

### Décision cycle 6

**`heal`** — global. Le merge de la feature Coupon Dashboard nécessite **§9.1 (fix scope runtime)** avant claim "complete". La feature Split Payment frontend peut être mergée telle quelle (avec mention "backend P12 attendu"). Les autres recommandations sont cycle 7 standard.

**Healing rule (CLAUDE.md §8)** : 1er cycle de healing sur cette feature → encore 2 marges avant escalation.

### Human gate ?

Non requis ici. Le fix §9.1 est un correctif technique clean (additif, signatures étendues, sentinel facile à écrire). Pas de contradiction architecturale, pas de risque critique tant que le merge n'a pas eu lieu.

**Si** le merge est déjà fait et la feature publiée comme complète → escalation immédiate (le user entend "mes coupons sont scopés" mais ne le sont pas).

---

## ANNEXES

### A. Fichiers lus pour cet audit

- `app/Models/Coupon.php` (177 lignes, intégral)
- `app/Services/CouponService.php` (439 lignes, intégral)
- `app/Http/Controllers/Frontend/CouponController.php` (44 lignes, intégral)
- `app/Http/Requests/CouponCheckRequest.php` (33 lignes, intégral)
- `app/Http/Requests/PosOrderRequest.php` (extrait lignes 60-120)
- `app/Domain/Events/EventContract.php` (179 lignes, intégral)
- `app/Listeners/PersistCouponChangedToOutbox.php` (109 lignes, intégral)
- `app/Listeners/PersistOrderCreatedToOutbox.php` (89 lignes, intégral)
- `app/Http/Resources/OrderDetailsResource.php` (extrait lignes 90-150)
- `resources/js/components/admin/pos/PaymentComponent.vue` (extrait lignes 620-680)
- `resources/js/helpers/posReceiptBuilder.js` (extrait lignes 30-110)
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (extrait lignes 510-570)
- `routes/api.php` (extraits 986-1100)
- `docs/audit/COUPONS_DASHBOARD_IMPLEMENTATION_2026-05-06.md` (rapport agent 1, 151 lignes)
- `docs/audit/SPLIT_PAYMENT_IMPLEMENTATION_2026-05-06.md` (rapport agent 2, 187 lignes)

### B. Greps clés (résultats)

- `grep payment_breakdown app/` → 0 (backend ne valide ni ne consomme)
- `grep payment_breakdown resources/js/` → 3 (PaymentComponent, posSplitPayment, commentaires)
- `grep -rn "isUsableNow" app/` → 1 seul call-site : la définition (`Coupon.php:90`). **Aucun appelant runtime.**
- `grep -rn "validateCouponForOrder\|resolveCouponBy" app/` → 7 appels confirmés tous via le path legacy.
- `grep coupon resources/js/components/frontend/kiosk` → 1 occurrence (commentaire).
- `grep posReceiptBuilder resources/js/components/frontend/kiosk` → 0.

### C. Frozen-zone — état post-cycle 6

| Fichier | Touché ce cycle ? | Statut |
|---|---|---|
| `app/Services/OrderService.php` | NON | intact ✓ |
| `app/Services/PaymentService.php` | NON | intact ✓ |
| `app/Services/FrontendOrderService.php` | NON | intact ✓ |
| `app/Services/Pricing/*` | NON | intact ✓ |
| `app/Http/Requests/PosOrderRequest.php` | NON | intact ✓ |
| `pos-wizard.js / pos-wizard.css` | NON | intact ✓ |

✓ Frozen-zone 100% respectée (CLAUDE.md §3 invariants).

---

**Fin de l'audit**. Read-only confirmé : 0 fichier modifié, 0 commit créé, 0 test exécuté.
