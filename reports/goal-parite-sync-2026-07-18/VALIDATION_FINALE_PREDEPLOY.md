# Validation finale pré-deploy — 2026-07-18

**Périmètre** : 17 commits `57df489ce..HEAD` (HEAD `2688688fb`) healant 2 audits
(intelligence totale + parité/sync borne↔web).
**Mode** : read/test seulement — aucun code applicatif modifié.
**Env** : APP_ENV=local, DB opérationnelle `foodking_e2e`, serveur dev :8000 UP.
Tests DB via `safe-test.sh` → DB isolée `foodking_test` (opérationnelle intouchée).

---

## VERDICT : **GO**

Tous les gates au niveau CODE sont verts. Aucun blocage réel introduit par les 17
commits. Le préflight « FAILED » est un dry-check en env LOCAL : ses findings sont
100 % des items d'environnement/deploy-config (pré-existants), pas des régressions —
détail §5.

---

## 1. Suites PHPUnit (par lot, DB isolée `foodking_test`)

| Lot | Filtre | Résultat | Détail |
|-----|--------|----------|--------|
| (a) fiscal+order | `Fiscal\|OrdersNoDelete\|SchedulerHasContinuity\|AdvanceOrderEnum` | **PASS** | 343 tests, 1202 assert, 8 skipped, 2 incomplete, **0 fail** (56 s) |
| (b) web/pos/loyalty | `OnlineOrder\|WebOrder\|LoyaltyScan\|LoyaltyRegister\|PosDeliveryCharge\|CouponSurface\|WebNonCod\|WebAcceptIsAtomic` | **PASS** | 42 tests, 118 assert, 1 skipped, **0 fail** (7 s) |
| (c) menu/stock/kiosk | `KioskUpsert\|KioskUpsell\|DailyQuota\|StockRupture\|MaxDailyQty\|KioskConfig\|MgmtReadAuthz` | **PASS** | 40 tests, 180 assert, **0 fail** (5 s) |
| (d) sentinelles | `Sentinel` (filtre large complet) | **PASS** | 612 tests, 4858 assert, 2 skipped, **0 fail** (1 min 54) |

**Total PHPUnit : 1037 tests, 0 échec.** Skips/incompletes = comportement attendu
(MysqlOnly/env, pas des régressions).

## 2. Specs e2e Playwright de la journée (`PLAYWRIGHT_NO_WEB_SERVER=1`, live :8000)

| Spec | Résultat | Preuves clés |
|------|----------|--------------|
| `_teste2e-heal-audit-2026-07-18.spec.js` | **5/5 PASS** (30.9 s) | V2 authz overview POS→403 / Admin→200 ; V3 web COD → PAID + `fiscal_seq=2677` ; V4 loyalty/scan invité→NEUTRE (0 PII) / kiosk→résout ; V5 refund sans `pos-refund`→403 |
| `_teste2e-parite-sync-2026-07-18.spec.js` | **5/5 PASS** (32.4 s) | W2 parité fiscale web `2677` / borne `2678` ; W3 web+borne atteignent KDS board + OSS à l'identique ; W4 non-COD accept≠board / COD accept→board+encaissable ; W5 matrice coupons surface OK (kiosk-only rejeté sur web) ; cleanup 8 orders test purgés, remaining 0 |

## 3. Vitest (`tests/js/kds tests/js/kiosk tests/js/sentinels`)

**PASS** — 183 fichiers, **1442 tests, 0 échec** (11.6 s).

## 4. Gates production

- **Chaîne fiscale** `fiscal:verify-chain --all` : **CHAIN OK ×4** (branches 1, 7, 8, 9).
  « SWEEP COMPLETE — CHAIN OK on every active branch (4 total) ». ✅ attendu.
- **Frozen diff** `57df489ce..HEAD` sur les 9 fichiers frozen (FiscalSequenceService,
  ZReportService, AuditLogService, BranchScope, PricingService, OrderStateMachine,
  pos-wizard.js, KioskWizardComponent.vue, PaymentComponent.vue) : **VIDE**. ✅
  Zones gelées intactes.

## 5. Preflight production (`app:preflight-production --strict`) — dry-check LOCAL

Résumé : **Critical 1, Warning 4 → « Preflight FAILED »**. INTERPRÉTATION : env LOCAL,
donc c'est un dry-check. **Aucun finding n'est une régression des 17 commits** ; tous
sont des items env/deploy-runbook pré-existants, dont les critiques sont **hard-enforced
au boot prod** par les boot guards `AppServiceProvider`.

| Finding | Sévérité | Interprétation |
|---------|----------|----------------|
| `POS_SIMULATION_HARDWARE=true` | CRITICAL | **Attendu en dev** (CLAUDE.md §3bis : acceptable dev, interdit prod). Le boot guard REFUSE de booter en prod si ≠false → à mettre `false` sur le VPS. Item runbook, pas régression. |
| `APP_ENV=local` | WARNING | On EST en local. Sur VPS → `production`. |
| `SCHEDULER_CRON` absent | WARNING | Machine dev sans cron. Runbook : installer `* * * * * php artisan schedule:run` sur VPS. |
| `LOG_CHANNEL=daily` | WARNING | Reco durcissement (→`production_json` SIEM). Non-bloquant. |
| `POS_MANUAL_DISCOUNT` on / F1 TVA | WARNING | Backlog pré-existant (F1 split TVA), reco toggle config. Hors périmètre des 17 commits. |

Findings verts notables : APP_DEBUG=false, APP_KEY set, CACHE/QUEUE=redis,
BROADCAST=pusher, FISCAL secrets OK, `FISCAL_CHAIN audit_logs intact ×5 branches`,
MENU_VAT 55 items non-zéro, DB reachable, cache round-trip OK.

## 6. Isolation des « non-verts »

Aucune VRAIE régression. Les seuls non-PASS sont :
- **Skips/incompletes PHPUnit** (10 skipped + 2 incomplete cumulés) : artefacts
  d'environnement (MysqlOnly, DB partagée), comportement nominal.
- **Preflight FAILED** : env local + deploy-config (§5), enforced au boot prod.

## 7. Actions deploy-runbook VPS (pas des blocages code)

1. `APP_ENV=production`
2. `POS_SIMULATION_HARDWARE=false` (sinon boot guard bloque — c'est voulu)
3. Installer cron `* * * * * php artisan schedule:run`
4. (Reco) `LOG_CHANNEL=production_json`
5. (Reco) garder `POS_MANUAL_DISCOUNT` OFF tant que F1 non fixé sous lock-plan

---

**Décision** : **GO** — code testé (PHPUnit 1037 / Vitest 1442 / e2e 10, 0 échec),
chaîne NF525 OK ×4, zones gelées intactes. Les prérequis prod restants sont des
réglages d'environnement standard sur le VPS, verrouillés par les boot guards.
