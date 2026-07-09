# RAPPORT V4-DEPLOY — CERTIFICATION PRÊT-AU-DÉPLOIEMENT — 2026-07-02
**Mission** : certifier le set à committer + boot-guards + deploy.sh · re-attaquer TOUTES les frontières
d'auth (leçon P0 `->send()` sans halt) · synchro sous concurrence · trancher les items déférés · **verdict GO**.
HEAD `61e9ea7b7` + working-tree. Verdict final en §7.

## 1. AXE 1 — SET À COMMITTER CERTIFIÉ (mes fixes V1→V4, séparés des reliquats)

**Méthode** : chaque fichier du working-tree attribué par marqueur `2026-07-02` / provenance documentée
(mes rapports V1-V4) vs reliquats d'autres sessions (delivery, web-wireup, bundles, session parallèle XSS).

### Set CERTIFIÉ (mien, documenté, testé) — COMMITTÉ `47f3ad545` (13 source + 19 tests = 32 fichiers)
**Source (13)** : `app/Services/OrderService.php` (cash-gate V2 + off-book V4), `app/Http/Controllers/Frontend/LoyaltyController.php` (check/register V2-V3), `app/Http/Controllers/Frontend/MessageController.php` (IDOR V4-DEPLOY), `app/Http/Controllers/Installer/InstallerController.php` (halt V3), `app/Http/Controllers/Table/OrderController.php` (dine-in IDOR V2), `app/Rules/ValidJsonOrder.php` (cap V2), `app/Http/Controllers/Admin/Fiscal/XReportController.php` (422 V1), `app/Http/Controllers/Admin/ItemController.php` + `Admin/OrderHistoryController.php` (V1), `app/Events/OutboxBroadcastSwallowedEvent.php` (docblock V1), `app/Support/Excel/FormulaGuardValueBinder.php` (V3, NEW), `config/excel.php` (V3, NEW), `config/idempotency.php` (V1).
**Tests (19)** : Security/{ExcelFormulaInjectionGuard, InstallerAlreadyInstalledGuard, LoyaltyCheckIdor, LoyaltyRegisterNoLeak, **MessageIdor**}, Cash/PosDeferredNoDoubleCashMovement, Payment/PosOffBookPaidGuard, TableDiningOrderIdor, Unit/Rules/ValidJsonOrderItemCap, Fiscal/XReportTest, LoyaltyApiTest, **KioskQuoteIntegrity + KioskQuoteTokenRequiredOnCommit**, Sentinels/{TpeSimulationDepth, WithoutGlobalScopesAudit, F001, F006, F009, F013}.
**Plans/rapports** (non committés avec le code) : `plans/PLAN_AUDIT_F00{1,6,9}` + `F013`, `reports/ultra-review/2026-07-02/*`.

### EXCLUS du commit (reliquats — PAS ma provenance)
- **Session parallèle** : `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (marqueur XSS `2026-07-02` mais ABSENT de mes rapports V1-V4 = autre agent Fable). *Leçon : un marqueur de date ne prouve pas MA provenance — croiser avec mes rapports.*
- **Autres sessions** : `OnlineOrderController`, `TableOrderController`, `OrderRequest.php` (guard KIOSK reserves-session), `AppLibrary`, `DeliveryFeeService`, `config/menu_images`, `BranchTableSeeder`, bundles `public/js/*.js`, `deliveryCharge.js`, tests Delivery/Stock/e2e-abuse.
- **Couplage KioskQuote RÉFUTÉ** (hypothèse initiale infirmée par la preuve) : `KioskQuoteIntegrityTest` + `KioskQuoteTokenRequiredOnCommitTest` PASSENT sans le guard foreign `OrderRequest.php` (mon fix V1 teste le quote token/signature/expiry, indépendant) → **INCLUS dans le commit** (voir §consistency).

### Preuve de cohérence (reliquats retirés → set seul → suite verte) ✅
`git stash` des **19 fichiers foreign** (mon set reste appliqué) → run de TOUT mon slice de tests →
**0 échec**. Mon set est **auto-cohérent** (aucune dépendance à un reliquat). **Couplage KioskQuote
RÉFUTÉ** : `KioskQuoteTokenRequiredOnCommitTest` 4/4 + `KioskQuoteIntegrityTest` 2/2 PASSENT sans le
guard foreign `OrderRequest.php` — mon fix V1 teste le quote (token/signature/expiry), indépendant du
guard KIOSK-machine → **les 2 tests KioskQuote SONT dans le set certifié**. `git stash pop` → reliquats
restaurés proprement (stash vide, 0 conflit, 0 perte).

### COMMIT LOCAL effectué (checkpoint, NON poussé)
`47f3ad545` sur `pos/category-first-caisse-2026-06-23` (au-dessus de `61e9ea7b7`) : **32 fichiers**
(13 source + 19 tests), 0 foreign, 0 secret, 0 `.env`. Reliquats laissés intacts + non committés.
**Réversible** : `git reset --soft HEAD~1`.

## 2. AXE 1 — BOOT-GUARDS PROD (vérifiés)
`AppServiceProvider::boot` (l.178+) **REFUSE de booter en production** (RuntimeException) si :
`POS_SIMULATION_HARDWARE != false` (NF525 cash-trail), `PAYMENT_BYPASS`/`PRINTING_BYPASS`, `APP_DEBUG=true`
(leak stack/SQL/creds, incl. écriture admin-UI), `IDEMPOTENCY_MIDDLEWARE_ENABLED != true`. **Défense solide,
doublée d'exceptions concrètes (pas d'override silencieux).** ✅

## 3. AXE 1 — deploy-vps.sh (findings)
Script SPÉCIFIQUE (ticket 2026-06-30) : `git reset --hard origin/$BRANCH` + `npm ci` + `npm run production`
+ `config:clear`/`cache:clear` + auto-vérif bundle (rollback si échec). **Adapté à mes heals** (0 migration,
0 schema, 0 env nouveau). **Gaps pour un deploy GÉNÉRAL (à documenter owner)** : (a) pas de `migrate --force`
(mes heals n'en ont pas besoin ; futurs oui) ; (b) **pas de restart worker `--queue=high,default`** (le worker
synchro doit être relancé post-deploy — externe au script, via supervisor) ; (c) `config:clear` sans `config:cache`.

## 4. AXE 2 — RE-ATTAQUE FRONTIÈRES AUTH (chercher le pattern P0 ailleurs) ✅
- **Pattern `->send()`/redirect-sans-halt** (le P0 installateur) : **grep `->send()`/`->sendContent()` sur
  TOUT `app/` = 0 instance** (hors Notification/Mail/Http). L'installateur était le SEUL cas — déjà healé.
  Le seul `abort()` en constructeur (`XReportController`, mon code V1) **throw/halte** correctement. Aucun
  middleware ne redirige sans `return $next`. → **AUCUN clone du P0.**
- **Routes publiques mutantes** : SAFE. **Legacy `/payment/{order}`** (IDOR/success forgé) : SAFE. **Exports
  Excel (~20) authz PII** : SAFE.
- **BREACH → healé** : **P2 IDOR `/api/frontend/message/*`** — `show`/`destroy` route-model-bound SANS
  owner-check (+ `index` filtrait par `$request->user_id` client) → tout token authentifié (même guest
  kiosk:order) lisait/supprimait le message d'autrui (Message non branch-scopé = modèle exempté). **Fix** :
  owner-guard sur show/destroy (404 sinon) + `index` forcé à `auth()->id()`. `MessageIdorTest` 2/2.
  *(2 agents du workflow sont morts sur erreur API — surface send-halt + 1 verify — REFAITS à la main ci-dessus.)*

## 5. AXE 3 — SYNCHRO SOUS CONCURRENCE ✅
**Double-encaissement** : `confirmCounterPayment` = `lockForUpdate` (PaymentService:222) + check déjà-PAID
(:278) → `PaymentAlreadyCollectedException` → **409** ; garantit « single fiscal_sequence_no, single
cash_movement » (:240). Route `counter-collect/{order}/confirm` gatée `pos` + middleware `idempotency`.
Prouvé par **`PosCounterCollectRaceProtectionSentinelTest` 4/4** : exception typée si caissier B course,
replay même-caissier = no-op, route 409 sur course, **jamais 200/payload** sur course. → **1 seul
encaissement effectif, zéro double fiscal/tiroir.**

## 6. AXE 4 — ITEMS DÉFÉRÉS (tranchés)
- **Uber go-live (6 items)** : OAuth+HMAC fail-closed PRÊTS ; bloqueurs go-live = **(1)** peupler `config/uber_menu_map.php`
  (données owner) **(2)** index UNIQUE sur `orders.transaction_id` (anti double sous course) **(3)** gérer events
  d'annulation Uber **(4)** câbler `uber.fiscalize`/`deny_on_out_of_stock` **(5)** item non résolu → **dead-letter**
  (PAS rollback = commande perdue) **(6)** chemin fiscal Uber (source_surface=uber_eats). **TRIGGER : Production
  Access accordé.** Workstream dédié (migration UNIQUE + mapper + cancel-handler + 6 tests).
- **Z-report enrichment** (`persistForClosedReport` non câblé) : **DÉFÉRÉ LOCK-gate** — frozen `ZReportService` ;
  reconstructible à la demande + chaîne intacte → backlog pré-audit (câbler sous LOCK owner).
- **Delivery-boy variance-gate (P3)** : documenté, faible impact mono-poste — backlog.
- **Cron-Z TOCTOU (P3)** : 0 impact fiscal (lock re-vérifie) → fausse alerte pager ; backlog observabilité.

## 7. VERDICT GO-DÉPLOIEMENT

### **GO** — le code est prêt à partir en prod. Bloqueurs restants = ENVIRONNEMENT/OPS, pas la qualité.

**Certifié cette mission** :
- ✅ **Set à committer isolé + prouvé auto-cohérent** + **committé local `47f3ad545`** (32 fichiers, réversible, non poussé) ; reliquats d'autres sessions séparés + laissés intacts.
- ✅ **Boot-guards prod solides** (refus de boot si simulation/APP_DEBUG/idempotency).
- ✅ **Axe 2 auth** : le pattern P0 `->send()`-sans-halt n'existe NULLE PART ailleurs ; 1 nouveau P2 IDOR (message) healé+testé ; toutes les autres surfaces SAFE.
- ✅ **Axe 3 concurrence** : double-encaissement verrouillé (race-protection 4/4).
- ✅ **Suite backend** : **3051 tests / 0 failed / 0 error** · **NF525 CHAIN OK** · **frozen-diff 0**.

**Conditions de déploiement (OPS — à garantir par l'owner/cowork sur le VPS)** :
1. **`.env` prod** : `POS_SIMULATION_HARDWARE=false`, `APP_DEBUG=false`, `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`,
   `BROADCAST_DRIVER` réel (pas `log`), `MIX_PUSHER_*` au build (sinon Echo undefined → fallback polling).
2. **Worker synchro** : `php artisan queue:work --queue=high,default` (supervisor) — **absent de `deploy-vps.sh`**,
   à ajouter/garantir (sinon KDS/OSS temps-réel tombent en polling 5s).
3. **Deploy** : `deploy-vps.sh` OK pour ce set (0 migration) ; pour un deploy général ajouter `migrate --force`
   + `config:cache` + restart worker.
4. **Purge cache/service-workers** au déploiement (cause n°1 des « tickets doublés/anciens » — le code de rendu est bon).
5. **Push** : `47f3ad545` reste LOCAL — l'owner décide du push (discipline §10).

**Items déférés (workstreams, pas des bloqueurs V1)** : Uber go-live (6, Production Access en attente),
Z-report enrichment (LOCK-gate), delivery variance (P3), cron-Z TOCTOU (P3), + **nettoyage des 19 commandes
PAID orphelines** existantes (décision owner : test-DB / ré-encaisser / annuler).

### Barre atteinte : audit A→Z V1→V4 + deploy-cert convergés — 1×P0, 1×P1, 6×P2, P3 healés/testés,
### set certifié+committé, auth re-attaquée (0 clone P0), concurrence prouvée, boot-guards OK, NF525 CHAIN OK.

## 8. GO DEEP — passe adversariale supplémentaire (owner « go deep »)
Trois fils tirés à fond, **0 nouveau P0/P1 non traité** :
- **Provenance des 19 PAID orphelines** : **0 créée aujourd'hui** (sous test intensif) → aucun vecteur ACTIF.
  Résidu historique (mai-juin) : 8 delivery même-timestamp (seed/batch), 10 takeaway, 1 dining-table. La voie
  fiscale est SAINE (takeaway 991/1001, delivery 41/49 obtiennent une séquence) → cleanup owner, pas une fuite.
- **Off-book `UNPAID→PAID` via change-payment-status** : RÉEL mais **AMBIGU** — les tests légitimes
  (`ChangePaymentStatusTransactional/Validation`, `PosOrderTax`…) scellent des commandes SANS fiscal en PAID
  et attendent 200 → généraliser mon garde au-delà de `PENDING_COUNTER` casserait ~5-10 tests = chemin
  mécanique LARGEMENT utilisé (web/admin). **Confirme que mon périmètre V4 (PENDING_COUNTER, non-ambigu) était
  le bon** ; la politique `UNPAID→PAID` = **décision d'architecture owner** (preuve concrète fournie), pas un heal unilatéral.
- **Balayage IDOR systématique** (toutes les routes frontend route-model-bound) : **le message controller était
  le SEUL trou** (healé). Tous les autres sont correctement scopés — `FrontendAddressController` (owner-check
  `user_id!==auth()->id()→403`), `{customer}` (permission `customers*`), `MyOrderDetails` (permission), livreur
  (`delivery_boy_id!==auth→403` + garde branche). → **classe IDOR fermée, 0 nouveau finding.**
**Conclusion go-deep : la certification TIENT sous une attaque plus profonde** — aucun nouveau P0/P1, le P2 IDOR
message (trouvé+healé cette session) était le dernier de sa classe, l'off-book résiduel est compris et cadré.

## 9. MAX AMÉLIORATION — surfaces admin/business peu couvertes (owner « continue max amélioration »)
Workflow refute-by-default 5 surfaces (stock/promo/settings/RBAC/reports, 13 agents) → 8 findings, 6 CONFIRMED,
**0 P0/P1** ; heals des propres + verrous préventifs :
- **HEALÉ P3 `NotificationController::index` non gaté** → un rôle non-admin lisait les credentials FCM
  (api key + service-account JSON). Fix : `->only('index','update')` (miroir Mail SET-02 / PaymentGateway SET-01). `SettingsHardeningTest`.
- **HEALÉ P3 injection de ligne `.env`** via `company_name` (écrit `APP_NAME=` par CompanyService/EnvEditor) :
  ajout `regex:/^[^\r\n"]+$/` (rejette sauts de ligne + guillemets). `SettingsHardeningTest`.
- **HEALÉ P3 defense-in-depth** : `CompanyRequest` + `SiteRequest` `authorize()` `return true` → `$this->user()?->can('settings')`
  → **ratchet du sentinel FormRequestAuthzDrift 66 → 64** (plafond authz resserré, verrouille le progrès).
- **VERROU préventif** : `AuthBoundaryRegressionSentinelTest` — interdit à JAMAIS le pattern P0 `->send()`-sans-halt
  dans tout contrôleur + exige que le garde installateur reste un middleware (pas un throw en constructeur).
- **DOCUMENTÉ (non healé, rationale)** : **P2 coupons scopés morts au checkout** (6 call-sites d'order-creation
  droppent branch_id+surface → tout coupon web-only/kiosk-only rejeté ; fail-closed, aucune perte). Fix Option A =
  plomber branch+surface aux 6 sites, MAIS le **mapping source_surface→surface coupon est ambigu** (source_surface ∈
  {pos,kiosk,mobile,delivery,web} vs surfaces coupon {kiosk,web,pos?}) → **décision produit owner** avant de câbler
  (fiscal-adjacent, ne pas deviner). P3 `AdministratorService::update` sans garde de rôle-cible = multi-admin-tier
  (V1 mono-owner → faible). P3 `OrderService::list/salesReport` BranchScope-only = by-design.
- **RÉFUTÉ** (verify-before-report, agent-verify mort) : P2 stock `toggleStockable` « incommandable définitif » —
  FAUX, `$newReason = $available ? null : $reason` **CLEARE** le flag au ré-activation.
