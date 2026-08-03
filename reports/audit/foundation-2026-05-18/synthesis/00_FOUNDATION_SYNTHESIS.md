# Couche 0 Foundation — Synthesis (audit ultra-profond 9 systèmes + 1 hunter cross-cutting)

**Date** : 2026-05-18 (post GOAL Complement)
**Mode** : 10 master sub-agents en parallèle max + ~32 specialists internes
**Verdict global** : ✅ **0 V1 ship blocker côté code** — quelques P0 ciblés à fix (single-line / défense en profondeur), 2 items opérationnels (deploy docs) prison-risk à vérifier owner.

---

## Top findings priorisés (à présenter au owner)

### 🔴 PRIORITÉ ABSOLUE — Légal / Production-blocker côté docs

| # | Finding | Système | Impact | Effort |
|---|---|---|---|---|
| L1 | **NF525 TRUNCATE GRANT runbook** : production DB user doit AVOIR `REVOKE TRUNCATE` sur audit_logs, z_reports, cash_movements, cash_drawer_sessions, order_payments, delivery_boy_cash_*. Sinon les triggers DELETE/UPDATE sont bypassables en 1 SQL → chaîne fiscale wipée → prison NF525. Code OK, runbook à vérifier dans docs/DEPLOY.md ou docs/FISCAL_SECRETS.md AVANT prod. | F-4 Fiscal | LÉGAL — prison risk | 30 min review + 1 SQL deploy step |
| L2 | **Prod DB backup mysqldump scheduled + offsite + 6 ans rétention** confirmé avant V1 cutover (storage/backups/ contient app-level snapshots seulement, pas dumps prod) | F-1 Data | LÉGAL — perte chaîne fiscale | 1h ops doc |

### 🟠 P0 code (fix avant tag V1, single-line ou très scope-mini)

| # | Finding | Système | Fichier:ligne | Fix |
|---|---|---|---|---|
| P0-1 | **Wrong import path** in DecrementStockOnOrderCreated.php — runtime FATAL Class not found wraps real StockUnavailableException → Sentry mislabel + comment "let it bubble" est faux | F-6 Stock | `app/Listeners/DecrementStockOnOrderCreated.php:6` | 1-line import path fix |
| P0-2 | **stock_movements manque trigger BEFORE DELETE/UPDATE** (audit_logs en a, NF525-grade inconsistent) — bypass possible via raw DB::table comme CleanupTestFixturesCommand fait déjà | F-6 Stock | new migration trigger | 1 migration mirror audit_logs pattern |
| P0-3 | **PushNotificationService cross-branch leak** : role_id=0 / user_id=0 fan-out ignore le branch_id stocké sur le record → fuite tenant isolation | F-8 Notif | `app/Services/PushNotificationService.php:71-90` | branch_id filter on fan-out query |
| P0-4 | **Idempotency middleware default-OFF** : config/idempotency.php:21 `enabled=false`. Les 10 new C-P0-H heal routes (cash-drawer, refund-with-counter, change-status) silently bypass si env var pas set en prod → duplicates dans paiements | F-3 Sync | `config/idempotency.php:21` + .env | flip default OR env var verification |
| P0-5 | **Two FCM clients fire same event** : FirebaseService (HTTP v1 OK) + FcmNotificationService (legacy server-key, Google-deprecated) — duplicate push ~700 LOC duplication | F-8 Notif | `app/Services/Firebase*` + `app/Services/FcmNotification*` | retire FcmNotificationService, garde FirebaseService |
| P0-6 | **CORS mismatch /api/broadcasting/auth** : localhost vs 127.0.0.1 host alias silently breaks channel-auth en prod (polling masque) | F-3 Sync | APP_URL config + kiosk browser host | 1 line config check |

### 🟡 P1 quick wins (safe, single-file)

| # | Finding | Système | Effort |
|---|---|---|---|
| P1-1 | **Password reset min:6 vs min:12 staff downgrade attack** — staff peut trigger forgot-password puis reset à 6 chars → contourne policy Wave 7 PR-D T5 | F-2 Auth | 2 lignes ForgotPasswordController.php:122 |
| P1-2 | **PII leak Auth::user()->name plaintext dans ActionLog.details** | F-9 Obs | OrderService.php:540-545 |
| P1-3 | **CouponCheckResource trusts client `$request->total`** pour percentage coupon preview (preview-only, pas d'impact fiscal mais leak coupon caps/thresholds) | F-5 Pricing | CouponCheckResource.php:39 server-side recompute |
| P1-4 | **OTP brute-force forensic gap** : 0 Log::warning dans Auth controllers → throttle absorbe mais aucun signal | F-2 Auth | structured logging |
| P1-5 | **/health/ready 503 payload non-IP-gated leak ops info** (broadcast driver, queue stale_count) à attackers anonymes | F-9 Obs | HealthController.php IP-gate |
| P1-6 | **320 occurrences `Log::info($exception->getMessage())`** — severity misleveling massif (INFO au lieu d'ERROR, no stack, no context) | F-9 Obs | bucketed heal ~25 fichiers V1.0.x |

### 🟢 Dead code / Duplication safe-to-remove (cleanup tranquille)

| # | Item | Quantité | Action |
|---|---|---|---|
| D1 | Dead i18n keys high-confidence (after dynamic-key pattern filter) | 240 keys | Removal via tools/i18n/audit_locale_keys.mjs |
| D2 | Bangladesh-legacy i18n keys (bkash/easypaisa/clickatell/etc) | 55 keys | Removal — projet FR not BD |
| D3 | Empty-string i18n keys causing trailing dots | 3 (fr.json:287/656/1459) | Removal |
| D4 | Duplicate FR values (top: "Annuler"×10, "Menu"×7, "Réessayer"×7) | 253 redundant | Consolidation |
| D5 | Empty PHP i18n namespace files | 10/16 shells | Delete or merge |
| D6 | Dead event-listener pair `SendEmailVerificationNotification` + `SendEmailVerification` (shadowed by Illuminate import) | 1 pair | Delete listener + event |
| D7 | Dead-file candidates NEEDS-OWNER-DECISION (low confidence — need user confirm) | 4 files | Owner choice |
| D8 | Dead method `SyncMetricsRecorder::recordWebSocketAuthFailure` uncalled server-side | 1 method | Delete or wire-in |
| D9 | `FrontendDiningTable` + `DiningTable` sharing same table | 2 models | V1.0.2 consolidation |

**D7 détaillé (NEEDS-OWNER-DECISION)** :
- `app/Http/Controllers/Frontend/CheckoutController.php` — dead route + dead controller (cdsRoutes.js cleanup historique)
- `app/Services/Catalog/ReceiptDataService.php` — ZERO usage trouvé (à confirmer cherche dynamique)
- `app/Http/Middleware/SetLocale.php` — middleware orphelin (locale set via session ailleurs)
- `app/Console/Commands/FixIdentityCommand.php` — artefact incident 2026-05-09 recovery (peut être archivé)

### 🔵 Structurel V1.0.X backlog (déjà documenté, pas V1 blocker)

- F-2 Auth : FormRequest authz unification (80/88 scattered) — base class + sentinel CI test
- F-2 Auth : OTP rate-limit macro + dedicated security log channel
- F-2 Auth : CSP enforce migration (report-only → enforce)
- F-5 Pricing : C.5 legacy fallback removal (595 LOC, gated by use_ssot_service=true) — config-shape SSOT → code-shape SSOT
- F-5 Pricing : OrderItem boot() updating listener blocking composition_snapshot mutation
- F-3 Sync : Stripe replay tolerance parameter, OSS production cadence audit, SenangPay secret caching, webhook DLQ retention gap (24h vs 180d)
- F-1 Data : 6 V1.0.2 items (HasFactory traits, NFC cross-branch lookup audit, FK_CHECKS=0 cleanup, ZReport Eloquent deleting() guard, withoutGlobalScope linter, CLAUDE.md §9 inventory drift)
- F-8 Notif : ShouldQueue across all notification listeners, idempotency keys
- F-9 Obs : observability channel forward Slack/Sentry, BypassAuditLogger mis-routed channel, KDS overflow flag server-emit
- F-4 Fiscal : 12 V1.1 backlog (composition_snapshot UPDATE trigger, z_reports signed-column lock, orders DELETE block, kiosk_auto_allocate alert, SIEM CRITICAL wiring, 6 P2/P3 defensive)

### ⚠️ BRAIN figures corrections (drift detected, must update)

| Topic | BRAIN claim | Audit actual |
|---|---|---|
| `$t()` UNDEFINED keys | 19 | **49-50** |
| Empty-string keys fr.json | lines 260/629/1432 | lines **287/656/1459** (empty KEYS not empty values) |
| fr↔en/ar drift | "6 P0" | **75 FR\EN, 194 EN\FR, 223 FR\AR, 192 AR\FR** |
| "Pusher channel-auth observably broken via Sanctum wildcard" | OPEN (BRAIN §2) | **CLOSED** (GOAL-CMS S-R3-P0-G healed `routes/channels.php:36-58` token-name discriminator) |

### Attestations production-grade (zones à PROTÉGER, ne pas toucher)

- 22 BranchScope models verified (NFC = 21 standard + 1 WizardProfileBranchScope nullable-aware)
- 166 migrations / 78 models / 79 FKs / 31 UNIQUEs / 5 NF525-immutable tables avec triggers
- 13 frozen-zone files (CLAUDE.md §7) all read-only attested
- Kernel.php $middlewarePriority (Sprint H1 Z6-06) verified
- TrustHosts Wave 3c anchored regex verified `(b1c50311d)`
- IdempotencyKeyMiddleware FROZEN verified
- routes/channels.php token-name discriminator (immune Sanctum wildcard)
- LoginController transparent bcrypt rehash + token rotation
- RefreshTokenController preserve abilities source + fallback []
- hashing rounds=12 (Wave 5G)
- Idempotency middleware (FROZEN) — config-flag default off is the actual concern, not the middleware

---

## Synthèse owner-friendly (sans jargon)

### Bonne nouvelle
**La fondation du projet est solide.** Les 9 systèmes de base (base de données, sécurité, sync, fiscal, prix, stock, langues, notifications, supervision) sont en bon état pour V1. **Aucune chose ne casse l'application** comme elle marche aujourd'hui.

### Ce qui est urgent (légal / prison-risk fiscal)
**2 vérifications de documentation** avant de mettre en production :
1. **Permissions de la base de données en prod** — il faut s'assurer que l'utilisateur de la base de données en production n'a PAS le droit de "tronquer" les tables fiscales. Si quelqu'un (admin compromis, hacker) arrive à exécuter une commande TRUNCATE, la chaîne fiscale serait wipée et c'est de la prison NF525. Le code est OK ; juste vérifier le runbook deploy.
2. **Sauvegardes de la base prod** — il faut un export quotidien (mysqldump) sauvegardé ailleurs (off-site) avec rétention 6 ans. Actuellement le dossier `storage/backups/` ne contient que des snapshots applicatifs, pas la base prod.

### Ce qui est important mais petit (6 fixes "single-line", pas de risque)
Six trucs ont été détectés qui peuvent être corrigés avec quelques lignes de code chacun, sans rien casser :
1. Un fichier importait une classe avec un mauvais chemin (le code planterait juste au lieu de bien logger l'erreur)
2. Une table de mouvements de stock n'a pas la même protection anti-suppression que les tables fiscales (cohérence)
3. Le service de notifications push pourrait envoyer à des utilisateurs d'autres restaurants (fuite multi-tenant)
4. Le middleware "anti-doublon paiement" est désactivé par défaut dans la config — il faut s'assurer qu'il est activé en prod
5. Deux services Firebase coexistent et envoient la même notification deux fois — on garde le nouveau, on enlève l'ancien
6. Un check de sécurité réseau /api/broadcasting/auth peut casser en prod selon le nom de domaine utilisé

### Ce qui est cosmétique / nettoyage (peut attendre)
- **240 traductions inutilisées** (fr/en/ar) — vestiges de versions précédentes
- **55 clés de paiement Bangladesh** (bkash, easypaisa, clickatell) jamais utilisées en France
- **3 clés vides** qui font apparaître "." à l'écran (ligne 287, 656, 1459 du fichier fr.json)
- **4 fichiers backend possiblement morts** (à confirmer)
- 1 paire event-listener morte (email verification shadowed par Laravel)

### Ce qui peut s'améliorer dans une version future (V1.0.X)
- Unifier l'authz des 80 endpoints encore éparpillés
- Forcer le SSOT pricing par code (au lieu de config-flag)
- Mettre toutes les notifications en queue (au lieu de bloquant)
- Logger les exceptions en ERROR (pas en INFO comme aujourd'hui sur 320 endroits)
- Et 12+ items NF525 défense en profondeur

---

## Questions pour le owner (user-friendly)

1. **Les 2 trucs prison-risk** (NF525 TRUNCATE + backup prod) — tu confirmes que tu veux qu'on prépare la doc deploy pour les corriger AVANT de tag V1 ?

2. **Les 6 fixes single-line P0** — tu valides qu'on les fait dans un sprint heal court (1-2h), ou tu préfères les tester un par un manuellement ?

3. **Les 240 clés i18n mortes + 55 Bangladesh** — on flag pour suppression V1.0.X ou tu veux qu'on les supprime maintenant (super safe, juste du nettoyage) ?

4. **Les 4 fichiers backend possiblement morts** (CheckoutController, ReceiptDataService, SetLocale middleware, FixIdentityCommand) — tu te souviens si certains servent ? Sinon je peux les flagger pour archive.

5. **L'idempotency middleware default-OFF** — tu veux qu'on flip le default à `true` dans le code OU on s'assure juste que l'env var `IDEMPOTENCY_ENABLED=true` est dans le template de production ?

6. **Les BRAIN figures stale** (49 $t() undefined au lieu de 19, etc.) — je corrige le BRAIN après cette synthesis ?

---

## Décisions à prendre ENSUITE (post-foundation)

Une fois ces points tranchés, je passe à **Couche 1 — POS Caisse** (11 sous-systèmes, audit ultra-profond identique). Le mandate dit qu'on suit l'ordre :
1. Couche 0 Foundation ✅ (ici)
2. **POS Caisse next** (11 sous-systèmes)
3. Intersection POS × KDS
4. Intersection POS × OSS
5. Intersection POS × Stock
6. Intersection POS × Fiscal
7. Intersection POS × Loyalty
8. Kiosk Borne
9-15. reste...

---

**Deliverables this audit (durables sur disque)** :
- 10 STATUS.md (1 par foundation system + F-X hunter)
- ~32 specialist JSONs (Architect/Security/UX-A11y/DBA/SRE/RED)
- Cette synthesis : `reports/audit/foundation-2026-05-18/synthesis/00_FOUNDATION_SYNTHESIS.md`

Wall-clock total : ~40 min (10 master sub-agents en parallèle, dominé par F-X cross-cutting hunter ~40 min).
Peak concurrent agents : ~42.
