# VERIFY-18 — Risques cachés (code mort, double-source, dette tech, frozen zones)

**Date :** 2026-04-20  
**Origine :** `tasks/verify-2026-04-20/18_VERIFY_HIDDEN_RISKS.md` (réf. audit `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`)  
**Mode :** AUDIT-ONLY (read-only, 0 fichier applicatif modifié)  
**Orchestrateur :** Claude Planner-Orchestrator  
**Sous-agents :** 2 × `explore` parallèles (Pass A : dead-code / TODO / symétrie / Vue ; Pass B : bypasses / debug / frozen zones / secrets)  
**Livrable unique autorisé :** ce fichier.

---

## 1. Résumé exécutif (10 lignes)

1. **GLOBAL : WARN.** Aucune faille **cassable** prouvée, mais 3 angles morts process/dette à fermer avant mise en prod.
2. **0 TODO/FIXME/HACK/XXX** dans `app/Services`, `Controllers`, `Middleware`, `routes`, `resources/js/components/{pos,kiosk,kds}` → **V1 OK** (`< 10`).
3. **Double pricing path subsiste** : flag `pricing.use_ssot_service=false` garde un chemin **legacy** complet dans `OrderService.php:770` et `FrontendOrderService.php:412` → **dette SSOT P1**.
4. **Symétrie Order ↔ FrontendOrder** : `OrderCreated` / `queue_number` / dispatch **post-commit** alignés ; **asymétrie intentionnelle** assumée pour Kiosk paiement différé (`finalizePaidKioskOrder`) et `changeStatus` client (hors transaction) → **V2 DIFF DOCUMENTÉ**.
5. **Frozen zones (`OrderService`, `FrontendOrderService`)** : forte churn depuis 2026-04-15, mais `docs/gates/GATE_LOG.md` reste un **template vide** → impossible de prouver 1-pour-1 que chaque commit a un gate brief approuvé → **V3 PROCESS GAP P1** (pas cassable, pas prouvable non plus).
6. **Imports `.vue` implicites** (`LoadingComponent` notamment) répandus → fonctionnels sous Laravel **Mix**, **bombe à retardement** lors du switch Vite → **V4 WARN P2**.
7. **Front computes `form.total` / `form.subtotal`** dans POS et Checkout, mais serveur **recalcule et écrase** quand SSOT actif → **V5 OK conditionné au flag SSOT=true en prod** ; sinon **FAIL**.
8. **Pas de Telescope/Horizon/Ignition** exposés ; **pas de `dd()`/`dump()`** dans `app/` ; **aucun secret** crédible dans `reports/antigravity/`.
9. **Top 3 findings :** (a) `GATE_LOG.md` vide vs commits frozen, (b) chemin legacy pricing toujours présent, (c) `form.total` envoyé client → dépendance forte au flag SSOT serveur.
10. **Top 3 cycles P recommandés :** **P11_FROZEN_ZONE_GATE**, **P12_PRICING_FRONT_PURGE**, **P13_VUE_IMPORTS_EXPLICIT**.

---

## 2. Plan exécuté

1. Lecture task + `AGENTS.md` + `safety.mdc` + `scope.mdc` + audit `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`.
2. Pass A `explore` : grep `TODO|FIXME|HACK|XXX|legacy|deprecated`, symétrie `OrderService` ↔ `FrontendOrderService`, imports Vue, double-source pricing front, dead files (`*.bak`, `*.old`, `_legacy/`).
3. Pass B `explore` : `branch_id == 0`, `withoutGlobalScope*`, `demo_mode`, `dd/dump`, debug/dev routes, Telescope/Horizon, fuites secrets `reports/antigravity/`, `git log` frozen files vs `docs/gates/`, impersonate.
4. Synthèse risk register (sévérité × file:line × cycle P).
5. Rédaction rapport unique + verdict global.

---

## 3. Vérifications obligatoires

| ID | Vérification | Statut | Preuve |
|----|--------------|--------|--------|
| **V1** | TODO/FIXME P0 < 10 | **OK (0)** | Aucun match `TODO`/`FIXME`/`HACK`/`XXX` dans `app/Services/`, `Controllers/`, `Middleware/`, `routes/`, `resources/js/components/{pos,kiosk,kds}/`. Seuls des commentaires `legacy` (P1/P2) listés §4. |
| **V2** | Symétrie `OrderService` prouvée (ou diff documenté) | **DIFF DOCUMENTÉ** | Méthodes communes (`myOrderStore`, `changeStatus`, `show`) alignées sur dispatch post-commit + `queue_number`. Asymétries assumées : `finalizePaidKioskOrder` (Kiosk différé), `changeStatus` client non-transactionnel. Cf. §5.2. |
| **V3** | Aucun frozen-zone modifié sans gate récent | **PROCESS GAP / WARN** | Commits multiples sur `OrderService` / `FrontendOrderService` depuis 2026-04-15 (`b007c6344`, `b76506ae9`, `c3c0593e6`, `a7036f6ec`, `2d4d2c846`, …). `docs/gates/` contient des briefs (`GATE_V1_STATUS_MACHINE_001_2026-04-15.md`, `GATE_V1_PRICING_SSOT_001_2026-04-15.md`, etc.) **mais** `docs/gates/GATE_LOG.md` est vide → mapping commit → gate **non auditable**. |
| **V4** | Imports Vue tous explicites `.vue` | **WARN** | `import LoadingComponent from "../components/LoadingComponent"` (sans `.vue`) dans `MenuComponent.vue:65`, `PosComponent.vue`, `PaymentComponent.vue`, `KitchenDisplaySystemComponent.vue:466`, … Compatible Mix, casse potentielle Vite. |
| **V5** | Aucune double-source prix (front pure presentation) | **WARN conditionné** | Front calcule `form.total = subtotal + delivery - discount` (`PosComponent.vue:1445-1446`, `CheckoutComponent.vue:799`) et l'envoie au serveur. Serveur **ignore mathématiquement** quand `pricing.use_ssot_service=true` (rule n°1 `safety.mdc`). **FAIL** si le flag est désactivé en prod. |

---

## 4. Pass A — Dead code, double implémentations, marqueurs

### 4.1 Marqueurs `legacy` priorisés (TODO/FIXME/HACK/XXX = 0)

| Sév | File:line | Évidence |
|-----|-----------|----------|
| **P1** | `app/Services/OrderService.php:770` | Chemin **legacy non-SSOT** sous `pricing.use_ssot_service=false` — calcul prix parallèle à `PricingService`. |
| **P1** | `app/Services/FrontendOrderService.php:412` | Recalc coupon legacy quand SSOT off. |
| **P1** | `app/Services/Menu/MenuProjectionService.php:28` | Surfaces sur queries per-surface legacy jusqu'à V1.5 → dual data path. |
| P2 | `app/Services/OrderService.php:349` | Référence ancienne boucle `ItemVariation::find` (remplacée par bulk). |
| P2 | `app/Services/KioskMenuService.php:123,215` | Dev legacy + sort legacy. |
| P2 | `app/Services/FrontendOrderService.php:769` | Fallback `AllergenService::projectFlags`. |
| P2 | `app/Services/ItemService.php:110` | Visibilité gated par surface. |
| P2 | `app/Services/Fiscal/ZReportService.php:185` | Orders pré-migration. |
| P2 | `app/Services/DashboardService.php:312` | Lignes `branch_id = NULL`. |
| P2 | `app/Services/FcmNotificationService.php:26,77` | Endpoint FCM legacy server-key. |
| P2 | `app/Http/Controllers/Frontend/UpsellController.php:20,81,103` | Méthode `legacyFallback` Phase 1. |
| P2 | `app/Http/Controllers/Frontend/KioskEventController.php:28-29,55` | Types Phase 0 legacy. |
| P2 | `app/Http/Controllers/Frontend/ItemController.php:110` | Upsell legacy backward compat. |
| P2 | `app/Http/Middleware/localization.php:13,41` | Header `x-localization` legacy. |
| P2 | `routes/api.php:969` | Route upsell + fallback legacy. |
| P2 | `resources/js/components/admin/pos/PosComponent.vue:1393,1863` | Cart lines legacy + flow `address_id`. |

### 4.2 Symétrie `OrderService` ↔ `FrontendOrderService`

**Méthodes publiques :**

- **Communes :** `myOrderStore`, `changeStatus`, `show`.
- **Seulement OrderService :** `list`, `userOrder`, `deliveredOrder`, `deliveryBoyOrder*`, `posOrderStore`, `tableOrderStore`, `orderDetails`, `changePaymentStatus`, `tokenCreate`, `selectDeliveryBoy`, `destroy`, `salesReportOverview`.
- **Seulement FrontendOrderService :** `myOrder`, `finalizePaidKioskOrder`.

**Dispatch / transaction (échantillons) :**

| Flow | OrderService | FrontendOrderService | Verdict |
|------|--------------|----------------------|---------|
| `myOrderStore` + `OrderCreated` | `DB::transaction` puis `OrderCreated::dispatch` (lignes ~292, 532-541) | `DB::transaction` puis `dispatchNewOrderSignals` → `OrderCreated::dispatch` (~151, 583-593, 837-842) | **OK post-commit** |
| `queue_number` | `Cache::lock` + `MAX(SUBSTRING(...))` `A####` (~455-486) | Idem (~379-402) | **Aligné** |
| `changeStatus` staff | Inside `DB::transaction`, dispatch après commit (~1488-1577) | n/a | **OK** |
| `changeStatus` client (cancel) | Hors transaction, dispatch immédiat (~1447-1481) | Hors transaction, dispatch immédiat (~642-698) | **Asymétrie assumée** P1 audit |
| Kiosk paiement différé | Pas d'équivalent | `shouldDispatchNewOrderSignals=false` au create, `OrderCreated` retardé via `finalizePaidKioskOrder` (~779-832) | **Asymétrie intentionnelle** documentée |
| Refund loyalty | `refundPoints(..., 'pos')` (~1462, 1515) | `refundPoints(..., 'kiosk')` (~674) | **OK différencié par surface** |

### 4.3 Imports Vue sans `.vue` (extension implicite)

| Sév | File:line | Évidence |
|-----|-----------|----------|
| P2 | `resources/js/components/frontend/menu/MenuComponent.vue:65` | `from "../components/LoadingComponent"` |
| P2 | `resources/js/components/admin/pos/PosComponent.vue` | Idem |
| P2 | `resources/js/components/admin/pos/PaymentComponent.vue` | Idem |
| P2 | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:466` | Idem |

Build actuel = **Laravel Mix** (`webpack.mix.js` → `mix.js().vue()`) → résolution implicite OK. **Risque** activé sur migration Vite sans `resolve.extensions: ['.vue']`.

### 4.4 Front pricing leak (vs SSOT serveur)

| Sév | File:line | Évidence |
|-----|-----------|----------|
| **P1** | `resources/js/components/admin/pos/PosComponent.vue:1445-1446` | `form.subtotal` / `form.total = subtotal + delivery - discount` envoyés au serveur. |
| **P1** | `resources/js/components/admin/pos/PosComponent.vue:1355-1367` | Pourcentage discount appliqué côté client. |
| **P1** | `resources/js/components/frontend/checkout/CheckoutComponent.vue:799` | `form.total = subtotal + delivery_charge - discount`. |
| **P1** | `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue:204,249` | `reduce()` snapshot subtotal client. |
| P2 | `PaymentComponent.vue:142` | Calcul **monnaie rendue** (pas total fiscal). |
| P2 | `KioskCartComponent.vue:163` | Affichage UI uniquement. |

→ **Acceptable uniquement** si serveur **toujours** en SSOT `true`. Couplé au P1 §4.1 ligne `OrderService.php:770`, c'est la **dette critique** à fermer.

### 4.5 Dead files

Sweep `*.bak`, `*.old`, `*.orig`, `*-legacy.*`, `_legacy/`, `_old/` sur `app/`, `resources/js/`, `routes/` → **0 hit**. **OK**.

---

## 5. Pass B — Bypasses cachés, frozen zones, debug exposure

### 5.1 `branch_id == 0` / `withoutGlobalScope*`

Tous les hits sont **par design** (admin cross-tenant, fiscal, jobs, CLI) avec `Auth` ou contexte machine derrière. **Aucun bypass HTTP non-authentifié** détecté.

| Sév | File:line | Scope |
|-----|-----------|-------|
| P2 | `app/Models/Scopes/BranchScope.php:31-39` | `branch_id===0` ⇒ pas de filtre — **intentionnel** admin. |
| P2 | `app/Traits/DefaultAccessModelTrait.php:21-24` | Aligne le trait sur BranchScope. |
| P2 | `app/Http/Controllers/Auth/LoginController.php:64-68` | Pin admin sur branche default. |
| P2 | `app/Http/Controllers/Auth/GuestSignupController.php:132` | Idem guest. |
| P2 | `routes/channels.php:32-38` | Subscribe `branch.{any}` si admin (broadcast, gated par token). |
| P2 | `app/Services/KitchenDisplaySystemOrderService.php:56,192` | Admin voit toutes branches KDS. |
| P2 | `app/Services/Fiscal/ZReportService.php:207`, `Fiscal/FiscalSequenceService.php:88` | `withoutGlobalScope` fiscal — légitime. |
| P2 | `app/Console/Commands/EnsureAdminLoginCommand.php:56-98`, `FiscalArchiveCommand.php:199` | CLI uniquement. |
| P2 | `app/Jobs/CleanupStalePendingKioskOrders.php:19` | Job background. |

### 5.2 Demo / Debug

- **0 `demo_mode`** / **0 `dd()`** / **0 `dump()`** dans `app/`.
- `console.log` résiduels dans `KitchenDisplaySystemComponent.vue:580,591`, `KioskWaitingComponent.vue:229,240`, `PreparingAndReadyComponent.vue:152,162`, `services/appService.js:263`, `services/WebSocketService.js:56` → **P2 hygiène**.
- `app/Libraries/QueryExceptionLibrary.php:22` : `env('APP_DEBUG')` au lieu de `config(...)` → **P2 config:cache caveat** (analogue à la correction `CheckApiKey` rule n°4).

### 5.3 Routes debug / dev exposées

- **Aucun** Telescope, Horizon, `_ignition`, `/debug`, `/dev`, `/test`, `/sandbox` dans `routes/*.php`.
- `composer.json` ne contient ni `laravel/telescope` ni `laravel/horizon` → **non installés**.
- Surfaces non-authentifiées :

| Route | Middleware | Risque |
|-------|------------|--------|
| `GET /api/health{,/live,/ready}` | `api`, `throttle:api`, `JsonMiddleware`, `CorrelationIdMiddleware` | **P2** : expose statut DB/Redis. `full` gardée par `assertFullHealthIpAllowed()` (`config('app.health_ips_allowed')`). |
| `POST /api/table/dining-order` | `installed`, `apiKey`, `localization`, `throttle:20,1` | **P1** : commande table QR sans Sanctum (par design SaaS table-side). |
| `GET /api/frontend/loyalty/config` | + `apiKey` | **P2** documenté. |
| `POST /api/frontend/loyalty/opt-in` | + `throttle:5,1` | **P2** opt-in non-Sanctum. |
| `POST payment/senangpay-webhook/...` | `installed` (web group) | **P2** : webhook gateway, signature à vérifier en interne. |

### 5.4 Telescope / Horizon / Ignition

**Non installés.** Pas de `gate()` à auditer. **Negative finding (clean).**

### 5.5 Secrets dans `reports/antigravity/`

Ripgrep ciblé sur `sk-`, `pk_live`, `bearer `, `Authorization:`, `AKIA`, `PUSHER_APP_SECRET` :

- **Aucun** secret crédible.
- Match `sk-` = **faux positif** dans nom de fichier `kiosk-wizard.spec.js` (substring `…sk-w…`).

**OK.**

### 5.6 Frozen-zone violations (gate-mapping)

`git log --since="2026-04-15"` :

| Fichier frozen | Commits dans la fenêtre |
|----------------|------------------------|
| `app/Http/Middleware/CheckApiKey.php` | **0** ✅ |
| `app/Enums/OrderStatus.php` | **0** ✅ |
| `app/Services/OrderService.php` | **plusieurs** (`b007c6344`, `b76506ae9`, `c3c0593e6`, `a7036f6ec`, `2d4d2c846`, `1f145bdbe`, `f1e7a8546`, …) |
| `app/Services/FrontendOrderService.php` | **plusieurs** (idem set partagé) |

`docs/gates/` contient bien des briefs (`GATE_V1_STATUS_MACHINE_001_2026-04-15.md`, `GATE_V1_PRICING_SSOT_001_2026-04-15.md`, `GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md`, `GATE_V1_MENU_86_001_2026-04-15.md`, `GATE_PAYMENT_SAFETY_001_2026-04-14.md`, `GATE_SYNC_WIZARD_DEEP_001_2026-04-14.md`, `GATE_MULTISURF_001_2026-04-14.md`, `GATE_BATCH_V1_APPROVAL_CHECKLIST.md`).

Mais `docs/gates/GATE_LOG.md` est encore un **template vide** → la traçabilité commit↔gate **n'est pas reconstructible automatiquement**. **P1 process** (pas P0 cassable).

### 5.7 Impersonate / loginAs

- **0 `Auth::login(`**, **0 `impersonate`**, **0 `loginAs`** dans `app/`.
- `actingAs` / `Sanctum::actingAs` confinés à `tests/**` (attendu).
- `tests/e2e/helpers/login.js:16` : `loginAsKiosk` avec credentials par défaut → **P2** hygiène E2E (jamais en prod path).

---

## 6. Risk register synthétique

| ID | Sévérité | Domaine | File:line | Cycle P |
|----|----------|---------|-----------|---------|
| R1 | **P1** | Frozen-zone gate trail | `docs/gates/GATE_LOG.md` (vide) vs commits OrderService/FrontendOrderService | **P11_FROZEN_ZONE_GATE** |
| R2 | **P1** | Pricing SSOT dette | `OrderService.php:770`, `FrontendOrderService.php:412` (chemin legacy `use_ssot_service=false`) | **P12_PRICING_FRONT_PURGE** |
| R3 | **P1** | Pricing front leak | `PosComponent.vue:1445-1446`, `CheckoutComponent.vue:799`, `KioskConfirmationComponent.vue:204,249` | **P12_PRICING_FRONT_PURGE** |
| R4 | P2 | Vue imports implicites | `MenuComponent.vue:65`, `KitchenDisplaySystemComponent.vue:466`, `PaymentComponent.vue` | **P13_VUE_IMPORTS_EXPLICIT** |
| R5 | P2 | `env()` runtime hors `config()` | `app/Libraries/QueryExceptionLibrary.php:22` | P14_ENV_TO_CONFIG |
| R6 | P2 | `console.log` résiduels prod paths | `KitchenDisplaySystemComponent.vue:580,591` ; `KioskWaitingComponent.vue:229,240` | P15_LOG_HYGIENE |
| R7 | P2 | Surface non-auth API | `routes/api.php` `dining-order`, `loyalty/opt-in`, `health/full` | P16_SURFACE_REVIEW |
| R8 | P2 | Asymétrie `changeStatus` client (hors transaction) | `OrderService.php:1447-1481`, `FrontendOrderService.php:642-698` | P17_STATUS_TX_HARDEN |
| R9 | P2 | Menu projection dual-path | `MenuProjectionService.php:28` | P18_MENU_PROJECTION_V1.5 |
| R10 | P2 | Test E2E credentials par défaut | `tests/e2e/helpers/login.js:16` | P19_E2E_CRED_HYGIENE |
| R11 | P2 | Webhook gateway sans middleware auth dédié | `app/Http/PaymentGateways/Routes/senangpay.php` | P20_WEBHOOK_SIG_AUDIT |
| R12 | P2 | KDS admin omniscient (intentionnel) | `KitchenDisplaySystemOrderService.php:56,192` | (déjà acté audit hidden-risks ligne 5) |

---

## 7. Verdict

**GLOBAL : WARN.**

- **V1 OK** (0 TODO/FIXME/HACK/XXX).
- **V2 DIFF DOCUMENTÉ** (asymétries Kiosk différé + cancel client assumées).
- **V3 PROCESS GAP** : `GATE_LOG.md` vide ⇒ traçabilité commit↔gate non auditable sur frozen zones (`OrderService`, `FrontendOrderService`). **Pas cassable** (briefs existent), **pas prouvable** non plus → WARN.
- **V4 WARN** : imports `.vue` implicites — bombe à retardement Vite.
- **V5 WARN conditionné** : front envoie `form.total` ; serveur l'écrase **uniquement si** `pricing.use_ssot_service=true`. **Reclassé FAIL** si on découvre le flag à `false` dans `config/pricing.php` prod.

Aucun finding n'atteint le seuil **FAIL irréversible** sur ce passage. La conjonction R1+R2+R3 doit néanmoins être traitée avant tout cycle de mise en prod.

---

## 8. Suite recommandée (ordre d'urgence)

1. **P11_FROZEN_ZONE_GATE** — remplir `docs/gates/GATE_LOG.md` rétroactivement (mapping commit ↔ gate brief depuis 2026-04-14) et exiger entrée obligatoire pour tout commit futur sur `OrderService` / `FrontendOrderService` / `CheckApiKey` / `OrderStatus`.
2. **P12_PRICING_FRONT_PURGE** — supprimer le chemin `use_ssot_service=false` dans `OrderService.php:770` et `FrontendOrderService.php:412`, puis arrêter d'envoyer `form.total` / `form.subtotal` côté front (ne garder que présentation).
3. **P13_VUE_IMPORTS_EXPLICIT** — ajouter `.vue` à tous les imports de SFC (préparation Vite). Pas urgent mais cheap.
4. **P14_ENV_TO_CONFIG** — `QueryExceptionLibrary.php:22` : passer en `config('app.debug')`.
5. **P17_STATUS_TX_HARDEN** — envelopper les chemins `changeStatus` client dans `DB::transaction` pour homogénéité et rollback fiable.

---

## 9. Compliance scope

- ✅ 0 modification applicative (`app/`, `resources/`, `routes/`, `database/`).
- ✅ 1 seule écriture : ce fichier.
- ✅ 2 sous-agents `explore` parallèles (Pass A + Pass B) + synthèse Planner — conforme §4 task.
- ✅ Sources OBLIGATOIRES §2 task toutes lues (`OrderService`, `FrontendOrderService`, `LoadingComponent`, `tasks/orchestration/`, `.cursor/rules/`, audit hidden-risks).
- ✅ Aucune frozen zone touchée par cet audit (read-only).
- ✅ Pas d'auto-approbation gate (les P11-P12 sont des **recommandations** au humain).

**FIN VERIFY-18.**
