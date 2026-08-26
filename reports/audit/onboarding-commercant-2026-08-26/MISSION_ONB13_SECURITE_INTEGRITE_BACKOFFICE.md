# MISSION ONB-13 — SÉCURITÉ & INTÉGRITÉ DU BACK-OFFICE · Rapport de mission
- GOAL : `plans/GOAL_ONB13_SECURITE_INTEGRITE_BACKOFFICE_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`) — constats agrégés de Z1, Z2, Z3, Z7 + lecture de code
- Port : **8813** · Voie : TRANSVERSE (audit lecture seule vague A ; corrections sérialisées par voie en vague B)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-13 (sécurité & intégrité du back-office). Lis : CONSTITUTION.md §3, CLAUDE.md §3quater, §8, §9, PROJECT_BRAIN.md §2, SYSTEM_MAP.md §6,
PARALLEL_PROTOCOL.md, docs/AUTHZ_MATRIX.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB13_SECURITE_INTEGRITE_BACKOFFICE.md, plans/GOAL_ONB13_SECURITE_INTEGRITE_BACKOFFICE_2026-08-26.md, puis les §3 « CONSTATS » de recon/Z1, Z2, Z3, Z7 et
recon/Z0_carte_dashboard.md §6, config/idempotency.php, tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php. Pré-vol §0.1 : worktree
.claude/worktrees/onb13-securite depuis HEAD, APP_URL=http://127.0.0.1:8813, .env.testing, liens durs, serveur 8813, PLAYWRIGHT_BASE_URL, filet backup/pre-onb13.
⛔ Jamais un fichier gelé (IdempotencyKeyMiddleware, BranchScope, AuditLogService, PricingService) ; jamais une garde relâchée ; jamais une écriture dans audit_logs ;
les FormRequests créées sont livrées par fiche au GOAL propriétaire du contrôleur, jamais branchées ici hors vague B et accord. Puis « lance le GOAL » : W0 → W1 = audit
offensif lecture seule (matrice route × rôle × champs, 40 payloads, 5 uploads, IDOR 12 ressources, inventaire des return true ; rapport SECURITE_BACKOFFICE_AUDIT.md)
→ W2..W6. Invoque la compétence security-review. Chaque constat = requête + réponse + file:line. Sécurité en tête, ROUGE = second attaquant, Jalonneur, matrice §S,
deux cycles identiques. Jamais de push. Gates §G : proposer. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Un logiciel livré à des établissements tiers doit être sûr **par défaut** : chaque mutation validée, aucun message technique, aucun secret exposé, aucune double écriture, et un journal
lisible « qui a changé quoi ». Le socle est fort (37 tests de sécurité, idempotence double couche, allowlists, CSP, CORS) ; les trous mesurés sont aux bords (9 mutateurs sans FormRequest,
SQL dans les 422, clé d'API affichée, lecture des réglages par la caisse). Ce GOAL est la conscience sécurité des autres : il audite en parallèle, fournit les requêtes et les tests,
et les propriétaires branchent.

## 2. ÉTAT MESURÉ LE 2026-08-26 (agrégé)
| Sév. | Constat | Preuve | Correctif porté par |
|---|---|---|---|
| P1 | Page Licence affiche la clé d'API (`X-API-KEY`, 38 car.) | Z2 `03-captures.json license_page`, `02-api.json admin_get license` | ONB-05 (retrait) ; test ici |
| P1 | Messages SQL bruts dans des 422 : `kds_station` (« Data truncated »), `users.phone` (« cannot be null ») ×2 | Z1 `edges.kdsInconnu` ; Z3 `api_results_phase2.json` | ONB-02, ONB-06 ; sentinelle ici |
| P1 | Jetons de borne non révoqués à la déconnexion/désactivation/suppression | Z7 `[8]-[11]`, `api-5-results.json` | ONB-10 ; test transverse ici |
| P1 | 9 mutateurs sans FormRequest (`RawMaterialAdjust:117`, `PurchasingScan:51,208`, `Availability::setMaxDailyQty:118`, `StockRuptureDashboard::run:212`, `PromoFlyer`, `UberPhotoCapture`, `Wheel/*` ×5, `NotificationAlert`) | code (Z0 §6) | requêtes ici ; branchement ONB-08/09/05, CAISSE |
| P2 | `pos@` lit `company`, `site` (fuseau, debug, clé Maps), `order-setup`, `branch` (6), `otp`, `theme`, `interrupteurs` | Z2 `02-api.json pos_get *` | G-READ ; ONB-05/01 |
| P2 | `/api/health`, `/api/health/ready` : version, files, diffusion, planificateur, sauvegardes sans auth (commentaire `routes/api.php:150-151` réserve ce rôle à `/healthz`) | Z7 | ONB-10 ; test ici |
| P2 | Passerelle de paiement : `paypal_client_secret`, `stripe_secret` en `type=text` (page SaaS-era) | Z2 `03-captures.json payment_gateway_inputs` | ONB-05 (retrait) |
| P2 | 413 brut PHP (« POST Content-Length … exceeds the limit of 8388608 bytes ») | Z1 `photo_edges.big` | `Handler.php` ici ; ONB-02 |
| P2 | Oracle de scan TCP (messages distincts `tcp_open_failed:$errstr`), allowlist vide | Z7 ; 13/08 | ONB-10 ; test ici |
| P2 | `loyalty.accept_legacy_plaintext` (`config/loyalty.php:68`) | code | ONB-09 ; test ici |
| P3 (bien) | Mot de passe de borne jamais relu ; politique ≥ 12 ; révocation par appareil ; garde-fous admin ; anti-injection `.env` ; `pos@` 403 sur toutes les écritures ; CSP report-only observée (Z1 console) | Z2, Z3, Z7 | figer par tests |

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 37 tests `tests/Feature/Security/` (liste dans GOAL §0.6) dont `AdminApiEnforcementDirectCallTest`, `PrinterHostAllowlistSentinelTest`, `MailHostAllowlistSentinelTest`, `FileUploadHardenedSentinelTest`, `ExcelFormulaInjectionGuardTest`, `IdempotencyCrossUserLeakSentinelTest`, `KioskTokenAdminBlockSentinelTest`, `SettingsHardeningTest` ; `tests/Feature/Settings/PaymentGatewaySecretExposureTest.php` (SET-T02 de juin **corrigé**) ; `MailLicenseEnvInjectionGuardTest`.
- Cliquet FormRequest 77 → 64 → **62** (25/08) ; `config/idempotency.php` : historique d'oublis corrigés (`:51`, `:58`, `:94`, `:108`) + sentinelle `IdempotencyRequiredRoutesCoverageTest`.
- 2026-08-13 S1.3 : oracle de scan de port identifié, option (b) host+port recommandée ; 2026-08-14 : `User` non scopé documenté (V2).
- 2026-08-25 : `foodking:ensure-admin` garde de production ; `HealthzController` comportement documenté.

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Mutateurs sans FormRequest | `RawMaterialAdjustController.php:44-47,117` · `PurchasingScanController.php:51-55,208` · `AvailabilityController.php:118` · `StockRuptureDashboardController.php:212` · `PromoFlyerController` (`routes/api.php:1306-1328`) · `UberPhotoCaptureController` (`:453-463`) · `Wheel/{WheelAccess,WheelCounter,WheelPrize,WheelSettings,WheelUnlock}Controller` (`routes/web.php:161-231`, `api.php:945,961`) · `NotificationAlertController` (`:661-664`) | | 9 |
| Cliquet | `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:67` | 62 | |
| Idempotence | `config/idempotency.php:20-34,51,58,94,108` · `app/Http/Middleware/IdempotencyKeyMiddleware.php` (gelé) · tests `IdempotencyCrossUserLeakSentinelTest`, `IdempotencyPendingTtlSentinelTest`, `IdempotencyRequiredRoutesCoverageTest` (cité) | | ajout seul |
| Exposition | `LicenseController` (`api.php:571-574`) · `SiteController`, `CompanyController`, `OrderSetupController`, `BranchController`, `OtpController`, `ThemeController` (index) · `HealthController`, `HealthzController` (`api.php:144-152`) · `PaymentGatewayController` (`:586-589`) | | G-READ |
| Messages | `app/Exceptions/Handler.php` · `lang/fr/validation.php` · `ItemRequest.php:79` · `EmployeeRequest.php:60-65` + migration `2026_05_16_140100_make_user_phone_required.php` | | |
| Uploads | `ItemPhotoUploadRequest`, `ItemOptionPhotoRequest`, `ChangeImageRequest`, `ItemImportRequest`, `PurchasingScanController` (image), `UberPhotoCaptureController` (photos), `ThemeRequest.php:33-37`, `SliderRequest` | | 5+ points d'entrée |
| Journal | `Admin/Pilotage/InterrupteurController.php:49-55` (`Log::info`) · `SettingsUpdated` (`CompanyController.php:36`) · `app/Models/ActionLog.php` (existant) · `AuditLogService` (gelé, fiscal) | | G-JOURNAL |
| Rate limit | `routes/api.php:378` (`throttle:admin-mutation`), `:357-369` (`throttle:menu-availability`) · `RateLimitTest`, `ApiRateLimitPerDeviceTest`, `ThrottleKeysArePerDeviceTest` | | |
| Bornes | `KioskMachineService.php:108,147,176` · `KioskTokenAdminBlockSentinelTest` · `KioskMachineTokenProfileBlockTest` | | ONB-10 |
| Fidélité | `config/loyalty.php:31-45,68,98` (`LoyaltyQrSigner`, `accept_legacy_plaintext`, `min_secret_length` 32) · `LoyaltyCheckIdorTest`, `LoyaltyRegisterNoLeakTest` | | ONB-09 |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Security|Sentinels|Idempotency"` → figer W0 (37 + 102 fichiers) · cliquet 62 · requêtes 96 + 7 · `required_routes` : compter W0 · `composer audit` : 7 avis (25/08, 3 paquets — hors périmètre : GOAL CONSOLIDATION W6).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-READ | Lectures de réglages autorisées à la caisse | `order-setup`, `branch` (sans champs fiscaux), interrupteurs en lecture ; le reste réservé à `settings` | lecture ouverte reste |
| G-DATA / G-JOURNAL | Table `settings_audit` dédiée, append-only, 6 ans | oui | `Log::info` seulement |
| G-IDEMP | Routes sensibles ajoutées à `required_routes` (liste T-3.1.1) | oui | double clic = double écriture possible |
| G-CI-MYSQL | CI MySQL pour concurrence/triggers | oui (fiche ONB-14) | preuves sqlite seulement |
| G0 | Amendement constitutionnel | — | ne bloque pas |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- « Sans FormRequest » ≠ « sans validation » : lire le `validate()` inline (garde RCE de `PurchasingScanController.php:51-55`) avant de migrer ; test de caractérisation d'abord.
- Le journal des réglages n'est **pas** la chaîne NF525 : ne jamais écrire dans `audit_logs` (gelé, HMAC).
- sqlite ne prouve ni triggers ni verrous : annoter « MySQL requis ».
- Un 403 est un succès ; un 200 là où le menu cache est un P0 seulement avec requête + réponse.
- Les FormRequests de contrôleurs d'autres voies ne se branchent pas ici (fiches).
- `:8000` = autre worktree ; ta session = **:8813**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi (livraison des FormRequests + tests) : ONB-08 (`RawMaterialAdjust`, `PurchasingScan`, `setMaxDailyQty`, `run`) · ONB-09 / voie CAISSE (`PromoFlyer`, `Wheel/*`, `UberPhotoCapture`) · ONB-05 (`NotificationAlert`, page Journal, retrait Licence/passerelles, `type=password`) · ONB-10 (`/api/health`, oracle TCP, jetons de bornes) · ONB-06 (événement de changement de rôle, `users.phone`) · ONB-02 (`kds_station`, 413) · ONB-01 (G-READ sur `company`/`site`) · ONB-14 (CI MySQL) · État final : —
