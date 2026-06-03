# PARALLEL_PROTOCOL — Running N agents/sessions without collision

Prerequisite docs: **CONSTITUTION.md** (vision + rules) · **SYSTEM_MAP.md** (lane ownership) · **SYNC_CONTRACT.md** (if the lane touches sync). Read those FIRST.

## Rules (follow to the letter ⇒ two agents can never edit the same file in parallel)
1. **Declare your lane.** Every mission starts by naming exactly ONE of the 5 systems (BORNE / CAISSE / KDS+OSS / WEB+APP / CENTRAL). That is your file lane (SYSTEM_MAP §1–§5).
2. **Shared zones are serialized.** Any write to a SHARED ZONE (SYSTEM_MAP §6: pricing, NF525, sync bus, auth, OrderService/FrontendOrderService/OrderStateMachine, i18n, build) requires a **LOCK doc + owner gate** and runs **alone** — NEVER in parallel with any other mission.
3. **Read before act.** CONSTITUTION + SYSTEM_MAP (+ SYNC_CONTRACT if your lane produces/consumes events) before any edit. Then PROJECT_BRAIN §2 for current state.
4. **Golden rule: disjoint lanes only.** Two agents NEVER modify the same file in parallel. If your task needs a file outside your lane, STOP → it's either another lane's (coordinate sequentially) or a shared zone (rule 2).
5. **Report touched files.** At mission end, list every file written, so the supervisor verifies zero cross-lane overlap before the next parallel wave.

## Frozen-zone reminder (CONSTITUTION §3.1)
Frozen files need LOCK + gate even within your own lane: POS `pos-wizard.js`/css/`admin-pos-v4.blade.php` (STRICT), `PaymentComponent.vue`, `PosV5TrancheRow.vue`, kiosk wizard trio (auditable-w/-care), Fiscal services, `PricingService`, `BranchScope`, `IdempotencyKeyMiddleware`, `OrderStateMachine`.

## Conflict matrix (quick reference)
- Two lanes editing only their OWNED §1–§5 paths → ✅ safe in parallel.
- Any lane editing §6 SHARED → 🔒 serialize + LOCK + gate.
- `OrderService.php` / `FrontendOrderService.php` / `resources/js/app.js` → multi-lane; coordinate even though not all frozen.
- **Registry/aggregator files** (`routes/api.php`, `resources/js/router/index.js`, `resources/js/store/index.js`, `webpack.mix.js`) → **append-coordination**: a lane owns its route/module *content*, but the registration line lands in these shared aggregators. Declare any registry edit in your mission report; if two missions add routes/modules in the SAME wave, serialize the append (second rebases). No LOCK needed, but NOT a free parallel edit — this is the one structural collision point.
- Dine-in `components/table/**` + `layouts/table/**` = DORMANT V1 (`pos.dine_in_enabled=false`) → no lane touches it.

---

## ASSIGNMENT TEMPLATES (5 pre-filled — owner fills only « TÂCHE DU JOUR »)

### ① BORNE
> **VOIE = BORNE (kiosk).** Lis d'abord CONSTITUTION + SYSTEM_MAP §1 + SYNC_CONTRACT (tu publies `OrderCreated`→KDS). Ta voie de fichiers = `resources/js/components/frontend/kiosk/**`, `router/modules/kioskRoutes.js`, kioskCart/kioskOfflineQueue JS, bundles `kiosk-*`, `app/Services/Kiosk/**`, `KioskMachineLoginController`, routes kiosk-login/kiosk-event, modèle `KioskMachine`. INTERDIT de toucher : zones partagées (PricingService/NF525/bus sync/BranchScope/OrderService/FrontendOrderService), frozen (wizard kiosk = sous soin/gate), et les voies CAISSE/KDS/WEB/CENTRAL. Respecte : TPE simulé, locale FR, NF525, no-cloud. Discipline : visual test-e2e + adversarial + verify-before-report file:line. **TÂCHE DU JOUR : <…>.** Converge P0+P1=0, gate, frozen-diff 0, no push, rapporte les fichiers touchés.

### ② CAISSE
> **VOIE = CAISSE (POS).** Lis CONSTITUTION + SYSTEM_MAP §2 + SYNC_CONTRACT (tu publies →KDS/OSS). Ta voie = `resources/js/components/admin/{pos,posOrders,cash,cashOverview,cashSessionReport,encaissement}/**`, `pos-app.js`, bundle `pos-shell.js`, `app/Http/Controllers/Admin/Pos/**` + `PosController`/`PosOrderController`, `app/Services/{PaymentService,SplitPaymentService,CashDrawerService}`, routes `pos.`/`pos-order.`, `config/pos.php`. INTERDIT : **`pos-wizard.js`/css/`admin-pos-v4.blade.php` (STRICT frozen)**, `PaymentComponent.vue`/`PosV5TrancheRow.vue` (gate), zones partagées (Pricing/Fiscal/bus/OrderStateMachine/OrderService), voies des autres systèmes. Respecte : `simulation_hardware`=matériel-seulement, FR, NF525, no-cloud. Discipline : visual + adversarial + verify file:line. **TÂCHE DU JOUR : <…>.** Converge P0+P1=0, gate, frozen-diff 0, NF525 CHAIN OK, no push, rapporte les fichiers.

### ③ KDS + OSS
> **VOIE = KDS+OSS.** Lis CONSTITUTION + SYSTEM_MAP §3 + SYNC_CONTRACT (tu t'abonnes à `branch.{id}`, publies `OrderStatusChanged` au bump/recall). Ta voie = `resources/js/components/admin/{kitchenDisplaySystem,orderStatusScreen}/**`, `helpers/kdsCustomization.js`, `services/OssSyncService.js`, bundles `admin-kds`/`admin-oss`, `KitchenDisplaySystemOrderService`, `OrderStatusScreenController`, `Resources/{KDSOrderItems,KDSOrderDetails,CDSOrderDetails}Resource`, `Events/KdsOrderRecalled`, `Requests/Kds/**`, routes `kds-order.`/`oss-order.`/`frontend/oss-order`. INTERDIT : changer la forme du payload KdsOrder sans coordination (casse OSS+tracker), `OrderStateMachine` (frozen), zones partagées, autres voies. Respecte : FR, NF525, no-cloud. Discipline : visual + adversarial + verify file:line. **TÂCHE DU JOUR : <…>.** Converge P0+P1=0, gate, no push, rapporte les fichiers.

### ④ WEB + APP
> **VOIE = WEB+APP (client standalone).** Lis CONSTITUTION + SYSTEM_MAP §4 (+ SYNC_CONTRACT si tracker). Ta voie = standalone `/Users/1millnonstop/Downloads/web/**` + `mobile/**` (NO API wireup V1), et storefront backend `resources/js/components/frontend/**` **SAUF `frontend/kiosk/**`** (= voie BORNE) + `components/layouts/frontend/**` + `router/modules/{frontendRoutes,customerRoutes}.js`. INTERDIT : `resources/js/components/frontend/kiosk/**` (voie BORNE), `resources/js/app.js` (entry partagé §6), zones partagées, autres voies. Respecte : **palette mobile NOIR/ORANGE/JAUNE/BLANC** (PAS `#F4501E`), FR, données menu = miroir canonical (jamais inventer de produits), no-cloud, no API-wireup sauf ordre owner. Discipline : visual + adversarial + verify file:line. **TÂCHE DU JOUR : <…>.** Converge, gate, no push, rapporte les fichiers.

### ⑤ CENTRAL
> **VOIE = CENTRAL (gestion).** Lis CONSTITUTION + SYSTEM_MAP §5. Ta voie = `resources/js/components/admin/**` SAUF dirs POS (`pos,posOrders,cash,cashOverview,cashSessionReport,encaissement`) et KDS/OSS (`kitchenDisplaySystem,orderStatusScreen`) ; `app.js` (entry partagé — coordonne), `BackendMenuComponent.vue`, bundles `admin-shell`/`admin-reports` ; `app/Http/Controllers/Admin/**` SAUF POS+KDS/OSS ; `DashboardService`, `OrderHistoryController`, cluster Settings, catalogue, rapports, utilisateurs. INTERDIT : voies POS/KDS/OSS, zones partagées (Pricing/Fiscal/bus/BranchScope), frozen. Respecte : FR, NF525 (lecture Z only), no-cloud. Discipline : visual + adversarial + verify file:line. **TÂCHE DU JOUR : <…>.** Converge P0+P1=0, gate, frozen-diff 0, no push, rapporte les fichiers.

---
**Acceptance:** following rules 1–5 makes it structurally impossible for two parallel agents to edit the same file; any shared-zone need forces serialization via LOCK + gate.
