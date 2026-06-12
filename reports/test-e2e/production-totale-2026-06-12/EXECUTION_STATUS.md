# EXECUTION_STATUS — W-INT Intégration release/v1-integration-2026-06-12

## PIN (étape 1)
- **BASE_SHA = 120597bc7** (`120597bc732cf0e9bccad97d5b3bb46017e6af14`)
- `git merge --ff-only 120597bc7` → `Already up to date.` (branche déjà alignée)
- Timestamp : 2026-06-12 (session W-INT)
- Branches sources :
  - `heal/ultra-audit-w4-2026-06-11` HEAD = `da32af6b9`
  - `heal/clients-next-2026-06-10` HEAD = `c10320958`
  - `goal/cms-gestion-2026-06-10-spine` HEAD = `02042a687`

## DISQUE (checkpoints df)
| Étape | df (Data volume) | Verdict |
|---|---|---|
| Démarrage | 1,2 Gi libres (`/dev/disk3s5 460Gi 427Gi 1,2Gi 100%`) | > 1 Go → merges + tests ciblés OK ; rebuild full (≥1,5 Go) à re-vérifier |

## ÉTAPES
| # | Étape | Statut | Preuve |
|---|---|---|---|
| 1 | PIN BASE_SHA | DONE | ff-only Already up to date, HEAD=120597bc7 |
| 2 | T-INT.1b baseline chaîne | DONE | `APP_ENV=e2e php artisan fiscal:verify-chain --all` → "branch=1 CHAIN OK / SWEEP COMPLETE — CHAIN OK on every active branch (1 total)" ; `SELECT COUNT(*), MAX(id) FROM audit_logs` → **3718 / 3722** |
| 3 | T-INT.2 merge W4 | DONE | commit merge `e28cba39b`. Conflits résolus §0.4 : bundles+mix-manifest=--theirs(7), kioskFormatPrice=--ours(spine), PosOrderShowComponent=union manuelle (variationsText + fallbacks W4). Acceptance : 10 classes W4 **OK (32 tests, 105 assertions)** ; Pos/ **OK (90, 458)** ; Dashboard/ **OK (28, 100)** ; KDS/ **OK (43, 154)** |
| 4 | T-INT.3 merge clients-next | DONE | commit merge `9fbef73c9`. Conflits §0.4 : FrontendOrderService=union primauté spine C-RED-02 (dispatch F3-02 gardé, 2 lignes dupliquées rejetées) ; PosLoyaltyRedeemModal=union F1+L2 ; BRAIN=concat ; bundles=--theirs(3). Acceptance : `--filter Loyalty` **OK (97, 406)** ; `tests/Feature/Loyalty/` **OK (28, 113)** ; `--filter 'Loyalty\|Redeem\|EventContract'` **OK (118, 471)** ≥112 attendu ; Vitest routerRedirectIntegrity **1/1** ; 7 specs fidélité **48/48** |
| 5 | T-INT.3bis merge cms-spine | DONE | commit merge `168064249`. 17 commits uniques mergés ; LOCK-W6 pos-wizard.js = bit-identique spine (diff vide) → 0 exclusion, 0 frozen modifié. Langues = deep-merge union primauté spine ; KioskStepGenericChoices = union B6+BU-01. Acceptance : Composer/ **123 tests, 535 assertions, 2 skipped (flag-gated baseline), 0 fail** ; Catalog/ **58, 3 skipped, 0 fail** ; sous-catégories filter **3/3** |
| 6 | T-INT.4 tokens/comptes/seed/loyalty-rates | DONE | SELECT patterns (goal-0612%/curl-admin%/f1-lane%) = **0 ligne** (déjà purgés par spine c10320958) ; DELETE = 0 row. Comptes recréés tinker APP_ENV=e2e : ultraheal@foodking.test **id=61 role=Admin token 2771**, ultraheal-pos@ **id=62 role=POS Operator token 2772**. Seeder : « 8 upgrade extras ensured on 4 frites items (1, 2, 33, 34) ». Barème : commande OK + DB `loyalty_setup` = **points_per_euro=1 / for_1_euro=100 / min_redeem=100** |
| 7 | Rebuild Mix + sentinelles | **DEFERRED (df)** | df au checkpoint = **1,1 Gi libres < 1,5 Go requis** (`/dev/disk3s5 460Gi 427Gi 1,1Gi 100%`). Caches sûrs récupérables ~500 Mo max (insuffisant). Bundles courants = --theirs des merges (mixtes) → sentinelles fraîcheur NON attestées. À rejouer dès G-DISK owner (purge ~29 Go worktrees) : `npm run prod` puis `npx vitest run tests/js/sentinels/` |
| 8 | PHPUnit FULL | **DEFERRED (df)** | 1,1 Gi < 1,2 Go requis. Compensation ciblée exécutée (tout VERT) : W4 32/32 · Pos 90/90 · Dashboard 28/28 · KDS 43/43 · Loyalty-univers 118/118 · Composer 123 (2 skip baseline) · Catalog 58 (3 skip) · Fiscal **221 (776 assertions, 3 skip, 0 fail)** · FrontendOrder/KioskLoyaltyBilling/OrderQuote 19/19. FULL à rejouer post G-DISK |
| 9 | T-INT.5 frozen-diff 15 fichiers | DONE | `git diff --stat release/v1-2026-06-10..HEAD -- <15 chemins §7>` → **sortie VIDE = 0 ligne** (les hunks LOCK-W6 pos-wizard.js sont déjà DANS release/v1-2026-06-10, bit-identiques) |
| 10 | T-INT.5b chaîne POST | DONE | verify-chain → "branch=1 CHAIN OK / SWEEP COMPLETE" ; audit_logs **3718→3724** (croissant only, +6 = logins/créations comptes tinker), MAX(id) 3722→3728 |
| 11 | Commit final + rapport | DONE | `W-INT_RAPPORT.md` écrit ; commit docs final ci-dessous |

## DEFER MANIFEST (reprise)
- Pré-requis : gate **G-DISK** owner (§0.0 — purge worktrees morts ~29 Go). Greffier re-vérifie `df -g /System/Volumes/Data` ≥ 15 Go.
- Ordre de reprise dans CE worktree (branche `release/v1-integration-2026-06-12`) :
  1. `npm run prod` (full rebuild — bundles actuels = --theirs mixtes des 3 merges, JAMAIS mergés sémantiquement)
  2. `npx vitest run tests/js/sentinels/` (fraîcheur bundles)
  3. `./vendor/bin/phpunit` FULL (attendu 0 échec hors 1 risky TpeSimulation + ~29 skipped baseline)
  4. Vitest FULL
  5. Commit bundles rebuildés (chemins explicites public/js public/css mix-manifest.json)
