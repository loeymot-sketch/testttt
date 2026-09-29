# GOAL — ONB-13 SÉCURITÉ & INTÉGRITÉ DU BACK-OFFICE
## FoodKing — Onboarding commerçant · chaque mutation admin validée, aucun secret ni message technique exposé, mutations idempotentes, et un journal « qui a changé quoi » lisible par le commerçant et par un inspecteur

- **Slug** : `ONB13_SECURITE_INTEGRITE_BACKOFFICE_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **Voie SYSTEM_MAP** : **TRANSVERSE** — audit lecture seule en parallèle ; corrections **sérialisées par voie** : `app/Http/Requests/**` (nouvelles requêtes, déclarées au GOAL propriétaire du contrôleur), `app/Http/Middleware/**` hors gelés, `config/idempotency.php` (ajout seul), `app/Services/Audit/SettingsAudit*` (À CRÉER), `tests/Feature/Security/**`
- **HEAD** : `43b120c7d` · **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB13_SECURITE_INTEGRITE_BACKOFFICE.md`
- **Port de session** : **8813** · **Persona** : le comptable de Nadia, et l'inspecteur qui demande « qui a changé la TVA le 3 mars ».

> **En cinq lignes.** Le problème, mesuré le 26/08 : **9 mutateurs admin sans FormRequest** (`RawMaterialAdjust`, `PurchasingScan`, `UberPhotoCapture`, `PromoFlyer`, `StockRuptureDashboard::run`,
> `Wheel/*` ×5, `NotificationAlert`, `AvailabilityController::setMaxDailyQty`) ; des **messages SQL bruts** dans les 422 (`kds_station`, `users.phone`) ; la page Licence qui affiche **la clé
> d'API** ; la lecture de `company/site/order-setup/branch/otp/theme` ouverte au caissier ; `/api/health` détaillé sans authentification ; des secrets de passerelle en `type=text` ;
> un 413 brut PHP ; des jetons de borne jamais révoqués (→ 10) ; `accept_legacy_plaintext` en fidélité ; et **aucun journal** « qui a changé quel réglage ». Le socle est fort (37 tests
> `Security/`, cliquet FormRequest 62, idempotence double couche, allowlists, CSP, CORS, rate limit). FINI = 100 % des mutateurs validés, 0 message technique, 0 secret exposé, idempotence sur les
> mutations sensibles, journal lisible (C1..C7). Ce GOAL **n'édite jamais** un fichier gelé (`IdempotencyKeyMiddleware`, `BranchScope`, `AuditLogService`, `PricingService`).

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb13-securite`, branche `goal/onb13-securite-2026-08-26`, depuis **HEAD** ; audit en **vague A**, corrections en **vague B** (chaque FormRequest créée est déclarée au GOAL propriétaire du contrôleur et livrée après lui, ou par lui).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8813` ; `.env.testing` ; liens durs ; serveur 8813 ; `PLAYWRIGHT_BASE_URL`.
- Base partagée : audit lecture seule ; les tests d'IDOR/enforcement utilisent des usines (sqlite `:memory:`) ; jamais `migrate:fresh` ; `safe-test.sh --phpunit "Security|Sentinels|Idempotency"`.
- ⚠️ Un test de sécurité qui « réussit » sur sqlite ne prouve pas la concurrence ni les triggers (MySQL) — le préciser dans chaque rapport.
- Filet : `git branch backup/pre-onb13-2026-08-26`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Validation partout | nouvelles `app/Http/Requests/{Stock/RawMaterialAdjustRequest,Stock/PurchasingScanRequest,Stock/PurchaseApplyRequest,Stock/MaxDailyQtyRequest,Promo/PromoFlyerRequest,Wheel/*Request,NotificationAlertRequest,Uber/UberPhotoCaptureRequest}.php` (créées ici, **branchées** par le GOAL/voie propriétaire), `tests/Feature/Sentinels/AdminMutatorsHaveFormRequestSentinelTest.php` (À CRÉER), `FormRequestAuthzDriftSentinelTest.php:67` (cliquet) |
| S2 Secrets & exposition | `app/Http/Resources/**` des réglages (masquage), `app/Exceptions/Handler.php` (messages génériques), `tests/Feature/Security/SettingsHardeningTest.php`, `tests/Feature/Settings/{PaymentGatewaySecretExposureTest,MailLicenseEnvInjectionGuardTest}.php` (étendre), `routes/api.php:144-152` (santé), `app/Http/Controllers/{HealthController,HealthzController}.php` (avec ONB-10) |
| S3 Intégrité des mutations | `config/idempotency.php` (`required_routes :34`, ajout seul), `tests/Feature/Security/{IdempotencyCrossUserLeakSentinelTest,IdempotencyPendingTtlSentinelTest}.php`, tests IDOR par ressource (À CRÉER), gardes d'upload (`FileUploadHardenedSentinelTest`, `ExcelFormulaInjectionGuardTest` — étendre), `RateLimitTest`, `ApiRateLimitPerDeviceTest` |
| S4 Journal des changements | (À CRÉER) `app/Services/Audit/SettingsAuditService.php`, `app/Models/SettingsAudit.php`, migration `settings_audit` (ou réutilisation de `action_logs` — `app/Models/ActionLog.php` existe), `app/Listeners/RecordSettingsAudit.php` (écoute `SettingsUpdated`, `ReglageModifie` de ONB-05), page Vue (via ONB-05) |

| HORS | Porté par |
|---|---|
| `app/Http/Middleware/IdempotencyKeyMiddleware.php`, `app/Models/Scopes/BranchScope.php`, `app/Services/Fiscal/AuditLogService.php`, `PricingService.php` (gelés) | jamais |
| Branchement des FormRequests dans les contrôleurs (`RawMaterialAdjustController`, `PurchasingScanController`, `PromoFlyerController`, `Wheel/*`, `UberPhotoCaptureController`, `NotificationAlertController`, `AvailabilityController`) | ONB-08, ONB-09, voie CAISSE (Uber, promo), ONB-05 (alertes) — **fiches** |
| Jetons de bornes non révoqués ; `/api/health` (correctif) | ONB-10 |
| Matrice d'enforcement rôles × routes, repli permissif client | ONB-06 |
| Lecture des réglages par `pos@` (**politique** : quelles lectures restent ouvertes à la caisse — ex. `order-setup`, `branch`) | décision G-READ ici, exécution ONB-05/01 |
| Mécanisme de réglages typés (émet `ReglageModifie`) | ONB-05 |

Zones à coordonner : `config/idempotency.php` (ajout seul, sentinelle `IdempotencyRequiredRoutesCoverageTest` citée dans le fichier `:94`), `routes/api.php` (middleware sur routes existantes = coordination), `app/Exceptions/Handler.php` (partagé).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (4 fichiers ci-dessus + `IdempotencyKeyMiddleware`) · SCOPE-2 3 boucles · SCOPE-3 migration prévue (`settings_audit`) : G-DATA · SCOPE-4 NF525 : le journal des réglages est **distinct** de `audit_logs` (chaîne HMAC fiscale) — ne jamais y écrire ; `idempotency.enabled` jamais exposé · SCOPE-5 : brancher une FormRequest dans un contrôleur d'une autre voie = fiche, pas d'édition.

## §0.4 — Pipeline
`ultra-audit-profond` · `security-review` (skill) · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques** · un constat de sécurité exige **reproduction** (requête + réponse) et `file:line`.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Mutateurs validés | sentinelle : tout POST/PUT/PATCH/DELETE du groupe admin a une FormRequest (ou une exception documentée) | **100 %** |
| C2 | Zéro message technique | balayage de 40 payloads invalides sur 20 routes → aucun `SQLSTATE`, aucune trace, aucun HTML PHP (413), messages FR | **0** |
| C3 | Zéro secret exposé | index de réglages, licence, passerelles, `/api/health*`, exports : aucun secret/clé dans une réponse lisible par un rôle non `settings` | **0** |
| C4 | Idempotence des mutations sensibles | liste des mutations admin sensibles (stock, promos, fidélité, réglages) → `required_routes` (ajout seul) ; rejouer une requête → une seule écriture | **100 %** de la liste |
| C5 | IDOR | pour 12 ressources (`employee/{id}`, `item/{id}`, `branch/{id}`, `coupon/{id}`, `subscriber/{id}`, `printer/{id}`, `kiosk-machine/{id}`, `payment-terminal/{id}`, `message/{id}`, `push-notification/{id}`, `purchase document`, `stock level`) : accès croisé filiale/utilisateur → 403/404 | **12/12** |
| C6 | Journal | chaque changement de réglage/rôle/taxe : qui, quand, avant, après, où ; lisible ; non modifiable ; export | **VRAI** |
| C7 | Cliquet | `RETURN_TRUE_BASELINE` 62 → ≤ 55 sans `return true` non documenté | **≤ 55** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `tests/Feature/Security/` = **37** (`AdminApiEnforcementDirectCallTest`, `AdminRoutePermissionFloorTest`, `ApiKeyRotationTest`, `ApiRateLimitPerDeviceTest`, `CashierAttributionAndLoginAuditSentinelTest`, `ContentSecurityPolicyHeaderTest`, `CorsTest`, `CustomerTokenHmacHardenedSentinelTest`, `DemoModeProdGuardTest`, `ExcelFormulaInjectionGuardTest`, `FileUploadHardenedSentinelTest`, `FirebaseKeyStorageSecurityTest`, `IdempotencyCrossUserLeakSentinelTest`, `IdempotencyPendingTtlSentinelTest`, `InnodbLockWaitTimeoutSentinelTest`, `InstallerAlreadyInstalledGuardTest`, `KioskDedicatedOwnerTest`, `KioskMachineAndTerminalIndexGatedTest`, `KioskMachineTokenProfileBlockTest`, `KioskTokenAdminBlockSentinelTest`, `LanguageServicePathContainmentSentinelTest`, `LoginPasswordValidationParityTest`, `LoyaltyCheckIdorTest`, `LoyaltyRegisterNoLeakTest`, `MailHostAllowlistSentinelTest`, `MessageIdorTest`, `OrderDetailsDeliveryBoyPiiTest`, `OtpBruteForceLockoutTest`, `PosCustomerDisplayAuthzTest`, `PosRefundAuthzParityTest`, `PrinterHostAllowlistSentinelTest`, `PublicCouponListLeakTest`, `RateLimitTest`, `SettingsHardeningTest`, `SignupGuestHijackGuardTest`, `ThrottleKeysArePerDeviceTest`, `UserSuperAdminDisableHardenedSentinelTest`) · `tests/Feature/Sentinels/` = 102 (`FormRequestAuthzDriftSentinelTest.php:67` = **62**) · `tests/Feature/Settings/PaymentGatewaySecretExposureTest.php` (le SET-T02 de juin **est** corrigé) · `config/idempotency.php` 143 lignes · CLAUDE.md §9 (idempotence double couche, `webhook_events` UNIQUE).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-INLINE** — « sans FormRequest » ≠ « sans validation » : `PurchasingScanController.php:51-55` valide inline avec une garde RCE. Tranché : la sentinelle exige une FormRequest **ou** une exception documentée ; la migration ne relâche jamais une garde (test de caractérisation avant).
- **C-JOURNAL** — `AuditLogService` (gelé, chaîne HMAC NF525) journalise le fiscal ; un journal de réglages n'y a pas sa place. Tranché : journal **distinct** (`settings_audit` ou `action_logs` existant — `ActionLog` est dans la liste d'exemptions BranchScope V1.0.2), append-only applicatif, sans HMAC.
- **C-READ** — la lecture de réglages par la caisse est parfois nécessaire (`order-setup`, `branch`, interrupteurs) ; d'autres non (`site`, `theme`, `otp`, `company`). Tranché : politique explicite G-READ (liste), exécution par ONB-05/01.
- **C-SQLITE** — les tests tournent sur sqlite ; les triggers et verrous sont MySQL. Tranché : chaque test de concurrence/trigger porte l'annotation « MySQL requis » et une exécution CI MySQL est demandée (fiche ONB-14).

## §0.8 — Le commerçant-type et ses questions
Le comptable : 1. « Qui a changé la TVA le 3 mars, et de combien ? » 2. « Un caissier peut-il lire la configuration ? » Nadia : 3. « Pourquoi ce message parle de SQL ? » 4. « Ma clé de licence est affichée à l'écran : c'est normal ? » 5. « Si je clique deux fois, ça enregistre deux fois ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Validation | **9 MUTATEURS SANS FormRequest** | `RawMaterialAdjustController.php:117`, `PurchasingScanController.php:51-55,208`, `AvailabilityController.php:118`, `StockRuptureDashboardController.php:212`, `PromoFlyerController` (`routes/api.php:1306-1328`), `UberPhotoCaptureController` (`:453-463`), `Wheel/{WheelAccess,WheelCounter,WheelPrize,WheelSettings,WheelUnlock}Controller`, `NotificationAlertController` (`:661-664`, pas de `NotificationAlertRequest`) · `app/Http/Requests/` = 96 + `Admin/` 7 · `FormRequestAuthzDriftSentinelTest.php:67` | 37 + 102 |
| S2 Exposition | **4 CONSTATS MESURÉS** | Licence = clé d'API (Z2 `license_page equalsApiKey:true`) · `pos@` lit `company/site/order-setup/branch/otp/theme/interrupteurs` (Z2 `02-api.json`) · `/api/health`, `/api/health/ready` détaillés sans auth (`routes/api.php:144-152`, commentaire `:150-151`) · passerelle : `paypal_client_secret`, `stripe_secret` en `type=text` (Z2) · messages SQL (`ItemRequest kds_station`, `users.phone`) · 413 HTML PHP brut (Z1) | `SettingsHardeningTest`, `PaymentGatewaySecretExposureTest`, `MailLicenseEnvInjectionGuardTest` |
| S3 Intégrité | **DOUBLE COUCHE, LISTE OPT-IN** | `config/idempotency.php:20-34` (`required_routes` opt-in), `:51,58,94,108` (historique d'oublis de routes → sentinelle `IdempotencyRequiredRoutesCoverageTest`) · `IdempotencyKeyMiddleware` (gelé) · uploads (`FileUploadHardenedSentinelTest`, `ExcelFormulaInjectionGuardTest`) · rate limit (`RateLimitTest`, `ApiRateLimitPerDeviceTest`, `ThrottleKeysArePerDeviceTest`) · CSP/CORS | idem |
| S4 Journal | **INEXISTANT (réglages)** | `InterrupteurController.php:49-55` (`Log::info` seulement) · `SettingsUpdated` (`CompanyController.php:36`) · `app/Models/ActionLog.php` (existant, exempt BranchScope) · `AuditLogService` (gelé, fiscal) | (À CRÉER) |

**Sortie d'ancrage brute** : `ls tests/Feature/Security | wc -l` → 37 · `grep -n "validate(" RawMaterialAdjustController.php PurchasingScanController.php` → `:117`, `:51`, `:208` · `ls app/Models | grep -i "ActionLog\|AuditLog\|DomainEvent"` → `ActionLog.php`, `AuditLog.php`, `DiningTableAuditLog.php`, `DomainEvent.php` · `grep -c "" config/idempotency.php` → 143 · `ls app/Http/Requests | wc -l` → 96 (+7 `Admin/`) · Z2 : `pos_get company 200`, `admin_get license keyEqualsApiKey:true`, `payment_gateway_inputs type:text` ; Z1 : `kdsInconnu SQLSTATE`, `big 413 <br /><b>Warning</b>` ; Z3 : `phone cannot be null` ×2.

# §2 — ÉTAT MESURÉ LE 2026-08-26 (agrégé de Z1, Z2, Z3, Z7)
| Sév. | Constat | Zone | Correctif porté par |
|---|---|---|---|
| P1 | Licence affiche la clé d'API (38 car.) | Z2 | ONB-05 (retrait) — ce GOAL : test de non-exposition |
| P1 | Messages SQL bruts dans les 422 (`kds_station`, `users.phone` ×2) | Z1, Z3 | ONB-02, ONB-06 — ce GOAL : sentinelle « aucun SQLSTATE » |
| P1 | Jetons de borne non révoqués (logout/désactivation/suppression) | Z7 | ONB-10 — ce GOAL : test de sécurité transverse |
| P1 | 9 mutateurs sans FormRequest | code | ce GOAL (requêtes) + propriétaires (branchement) |
| P2 | Lecture des réglages ouverte au caissier | Z2 | G-READ ici ; exécution ONB-05/01 |
| P2 | `/api/health`, `/ready` détaillés sans auth | Z7 | ONB-10 ; test ici |
| P2 | Secrets passerelle en `type=text` ; page sans objet | Z2 | ONB-05 (retrait) ; test ici |
| P2 | 413 brut PHP (limite 8 Mo) | Z1 | `Handler.php` ici + ONB-02 (message client) |
| P2 | `TcpPrinterTransport` messages distincts = oracle de scan (13/08) ; `config('security.safe_remote_host_allowlist') = []` | Z7 | ONB-10 ; test ici |
| P2 | `loyalty.accept_legacy_plaintext` (`config/loyalty.php:68`) | code | ONB-09 ; test ici |
| P3 | Colonne `kiosk_machines.password` jamais relue (bien) ; mot de passe ≥ 12 (bien) ; révocation OK (bien) | Z3, Z7 | figer par tests |

# §3 — SOUS-SYSTÈME 1 : VALIDATION PARTOUT

**Tâches**
- **T-1.1.1** — Sentinelle `AdminMutatorsHaveFormRequestSentinelTest` : parcourt `Route::getRoutes()` du groupe admin (méthodes mutatrices), résout l'action, vérifie qu'un paramètre typé `FormRequest` existe ; liste blanche **documentée** (clé, motif, date) pour les exceptions ; cliquet initial = 9.
  • test : (À CRÉER à `tests/Feature/Sentinels/AdminMutatorsHaveFormRequestSentinelTest.php`) · C1 progressif
- **T-1.1.2** — Écrire les 9 FormRequests (règles reprises des `validate()` inline **sans relâchement**, `authorize()` réel, messages FR) + tests unitaires par requête ; les livrer par fiche aux propriétaires (ONB-08 stock, ONB-09/CAISSE promo & Uber, ONB-05 alertes, ONB-09 roue) qui branchent et rejouent leurs tests.
  • test : (À CRÉER à `tests/Feature/Security/NewFormRequestsRulesTest.php`)
- **T-1.1.3** — Cliquet `RETURN_TRUE_BASELINE` (62) : inventaire des `return true`, correction de ceux de la vague (≥ 7), documentation des restants.
  • test : `FormRequestAuthzDriftSentinelTest.php` (existant, resserré) · C7
- **T-1.1.4** — `app/Exceptions/Handler.php` : aucune exception SQL/PHP ne remonte en clair (422/500 génériques FR, détail dans les logs) ; 413 intercepté (JSON FR).
  • test : (À CRÉER à `tests/Feature/Security/NoTechnicalMessageLeakSentinelTest.php`) · C2
**Acceptation** : C1, C2, C7 · 3 tests VERTS · 9 requêtes livrées.

# §4 — SOUS-SYSTÈME 2 : SECRETS & EXPOSITION

**Tâches**
- **T-2.1.1** — Inventaire des réponses lisibles par un rôle non `settings` : index de réglages, `/api/health*`, exports, ressources ; recherche de champs sensibles (clés, secrets, `.env`, chemins) ; matrice route × rôle × champs.
  • test : (À CRÉER à `tests/Feature/Security/SettingsIndexNoSecretForNonSettingsRoleTest.php`) · C3
- **T-2.1.2** — Politique **G-READ** : lectures autorisées à la caisse (`order-setup`, `branch` (sans fiscal ?), interrupteurs en lecture) vs réservées (`company`, `site`, `theme`, `otp`, `mail`, passerelles, licence) ; exécution ONB-05/01 ; tests ici.
- **T-2.1.3** — Licence / passerelles / SMS / OTP : tests « jamais de secret en réponse » quel que soit le rôle (masquage `****`), `type=password` (fiche ONB-05), retrait des pages (G-CACHE) ; `ApiKeyRotationTest` relu.
- **T-2.1.4** — `/api/health`, `/api/health/ready` : détail réservé (`settings` ou jeton de supervision), `live/ready` minimaux ; oracle TCP imprimantes : messages uniformes (fiche ONB-10) + test.
  • test : (À CRÉER à `tests/Feature/Security/HealthEndpointsMinimalWithoutAuthTest.php`) + `PrinterHostAllowlistSentinelTest.php` (existant, étendre au port)
**Acceptation** : C3 · 3 tests VERTS · G-READ tranché.

# §5 — SOUS-SYSTÈME 3 : INTÉGRITÉ DES MUTATIONS

**Tâches**
- **T-3.1.1** — Liste des mutations admin **sensibles** (stock : ajustement, application d'achat ; promos : coupon/offre ; fidélité : règles, crédit manuel ; réglages typés ; rôles ; taxes ; imprimantes/bornes) → ajout à `config/idempotency.php` `required_routes` (**ajout seul**, `IdempotencyRequiredRoutesCoverageTest` vert) ; test de rejeu (même clé → une écriture ; clé différente → deux).
  • test : (À CRÉER à `tests/Feature/Security/AdminSensitiveMutationsIdempotentTest.php`) · C4
- **T-3.1.2** — IDOR par ressource : 12 ressources × (utilisateur d'une autre filiale, rôle sans permission, id inexistant) → 403/404 cohérents ; `MessageIdorTest`, `LoyaltyCheckIdorTest` comme modèles.
  • test : (À CRÉER à `tests/Feature/Security/AdminResourcesIdorMatrixTest.php`) · C5
- **T-3.1.3** — Uploads : type par contenu, taille, extension, image piégée (polyglotte), Excel (`ExcelFormulaInjectionGuardTest`), PDF (extraction IA ONB-04), photo Uber — un test par point d'entrée d'upload admin.
  • test : `FileUploadHardenedSentinelTest.php` (existant, étendre) · au-delà : 0 octet, 27 Mo, `.php` renommé `.jpg`, SVG avec script.
- **T-3.1.4** — Rate limit et anti-abus : `throttle:admin-mutation` (`routes/api.php:378`) — bornes documentées, test de dépassement, message FR.
**Acceptation** : C4, C5 · 3 tests VERTS.

# §6 — SOUS-SYSTÈME 4 : JOURNAL DES CHANGEMENTS

**Tâches**
- **T-4.1.1** — Décision G-JOURNAL : table `settings_audit` (À CRÉER : `user_id`, `at`, `domaine`, `cle`, `avant`, `apres`, `ip`, `source`) ou réutilisation de `action_logs` (`ActionLog`) — recommandation : **`settings_audit` dédiée**, append-only applicatif (aucune route de suppression), rétention 6 ans (aligné NF525 sans y toucher).
- **T-4.1.2** — `RecordSettingsAudit` écoute `SettingsUpdated` (existant) et `ReglageModifie` (ONB-05), les changements de rôle/permission (ONB-06 : événement à émettre — fiche), de taxe (ONB-02 — fiche) ; `SettingsAuditService::record()`.
  • test : (À CRÉER à `tests/Feature/Audit/SettingsAuditRecordsChangesTest.php`) · C6
- **T-4.1.3** — Page « Journal des modifications » (via ONB-05 pour le menu) : filtres (qui, quand, domaine), export CSV, lecture réservée à `settings` ; une ligne = une phrase FR (« Nadia a changé la tolérance d'écart de caisse de 2,00 € à 5,00 € le 26/08 à 21:31 »).
  • test : (À CRÉER à `tests/js/settingsAuditPage.spec.js`) · visuel : `/admin/settings/journal`
- **T-4.1.4** — Non-régression NF525 : aucune écriture dans `audit_logs` par ce journal (test) ; `fiscal:verify-chain --all` inchangé.
  • test : (À CRÉER à `tests/Feature/Audit/SettingsAuditNeverTouchesFiscalChainTest.php`)
**Acceptation** : C6 · 3 tests VERTS · question 1 du comptable = OUI.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Surface \ scénario | payload invalide | rôle inférieur / autre filiale | jeton borne / expiré / révoqué | rejeu (idempotence) | upload piégé | volume / rafale | message d'erreur | secret en réponse | journal |
|---|---|---|---|---|---|---|---|---|---|
| 9 mutateurs sans FormRequest | 422 FR (après branchement) | 403 (`AdminApiEnforcementMatrixTest` ONB-06) | 403 (`KioskTokenAdminBlockSentinelTest`) | `AdminSensitiveMutationsIdempotentTest` | `FileUploadHardenedSentinelTest` | `throttle:admin-mutation` | jamais `SQLSTATE` | — | enregistré |
| Index de réglages | — | `SettingsIndexNoSecretForNonSettingsRoleTest` | 401 | — | — | — | — | `****` | — |
| Licence / passerelles | — | 403 | — | — | — | — | — | jamais la clé | — |
| `/api/health*` | — | détail réservé | — | — | — | rate limit | — | version/files masquées | — |
| Journal | — | lecture `settings` | — | — | — | 10 000 lignes (pagination) | — | valeurs sensibles masquées (`****`) | append-only |
| Uploads (5 points d'entrée) | 422 FR | 403 | — | — | 0 o / 27 Mo / polyglotte / SVG script / `.php.jpg` | — | 413 JSON FR | — | — |

# §A — ARMÉE D'AGENTS
**Sécurité** (rôle central : ROUGE offensif — tente l'IDOR, le rejeu, l'upload piégé, la fuite) · Architecte (journal distinct du fiscal, requêtes sans relâchement) · DBA (`settings_audit`, index, rétention) · SRE (rate limit, logs) · UX (messages FR, page journal) · **Psychologie commerçant** (le journal = confiance ; l'erreur technique = peur) · Implémenteur unique (sérialisé par voie en vague B) · ROUGE (second attaquant indépendant) · **Jalonneur**.
Disque `reports/test-e2e/ONB13_SECURITE_INTEGRITE_BACKOFFICE/<round>/wave-<W>-<rôle>.json` ; contrat de constat (requête + réponse + file:line obligatoires).

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases (37/102 tests verts, cliquet 62) | séquentiel | — |
| **W1** | **Audit offensif lecture seule** (vague A) : matrice route × rôle × champs, 40 payloads, 5 uploads, IDOR 12 ressources, inventaire des `return true` ; rapport `reports/audit/onboarding-commercant-2026-08-26/SECURITE_BACKOFFICE_AUDIT.md` | fan-out lecture seule | — |
| **W2** | S1 requêtes + sentinelles + Handler (T-1.*) | séquentiel | — |
| **W3** | S2 exposition (T-2.*) | séquentiel | G-READ |
| **W4** | S3 intégrité (T-3.*) | séquentiel | G-IDEMP (routes ajoutées) |
| **W5** | S4 journal (T-4.*) | séquentiel | **G-DATA**, G-JOURNAL |
| **W6** | Corrections sérialisées par voie (branchement des requêtes chez les propriétaires, fiches acceptées) + convergence : deux cycles, `safe-test.sh --phpunit "Security|Sentinels|Audit"`, `fiscal:verify-chain --all`, BRAIN | séquentiel | vague B |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-READ** | Politique de lecture des réglages par rôle (ce que la caisse peut lire) | Propriétaire | liste | MISSION §6 | EN ATTENTE — bloque T-2.1.2 |
| **G-DATA** | Table `settings_audit` | Propriétaire | accord | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W5 |
| **G-JOURNAL** | Journal dédié (recommandé) vs `action_logs` | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-4.1.1 |
| **G-IDEMP** | Ajout de routes à `config/idempotency.php` `required_routes` (liste T-3.1.1) | Propriétaire | accord | `GATE_LOG.md` | EN ATTENTE — bloque T-3.1.1 |
| **G-CI-MYSQL** | Exécution CI MySQL des tests de concurrence/triggers (fiche ONB-14) | Propriétaire | fenêtre | `PROJECT_BRAIN.md §4` | EN ATTENTE |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `security-review` · `verify-before-report` · `CLAUDE.md §3quater (git/secrets), §8, §9 (idempotence, Sanctum, cliquet 62)` · `docs/AUTHZ_MATRIX.md` · `SYSTEM_MAP.md §6` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-13) · `recon/Z1..Z7` (§3 constats sécurité) · `recon/Z0_carte_dashboard.md §6` ·
`plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md` (S1.3 oracle TCP) · `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (SET-T02/T05/T12/N04/N08) · `config/idempotency.php` · `tests/Feature/Security/*`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C7 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 12 tests créés VERTS ; 4. diff gelé 0 (5 fichiers gelés cités intacts) ; 5. NF525 : `audit_logs` jamais touché par le journal (test), chaîne OK ; 6. gates tranchés ; 7. `SECURITE_BACKOFFICE_AUDIT.md` + BRAIN vrais ; 8. deux cycles identiques ; 9. 9 FormRequests **branchées** par leurs propriétaires (ou fiches acceptées avec date).
**Interdit** : éditer un fichier gelé · relâcher une garde existante · écrire dans `audit_logs` · exposer `idempotency.enabled` · brancher une requête dans un contrôleur d'une autre voie · approuver un gate.
> Le sens : l'inspecteur lit « Nadia a changé la TVA de 10 % à 5,5 % le 3 mars à 9 h 12 », le caissier ne lit pas la configuration, et un double clic n'enregistre qu'une fois.
