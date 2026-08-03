# Passation Cursor — Fichier 1/3 : contexte maximum & alimentations

**Rôle :** injecter le maximum de données factuelles (chemins, rapports, règles, commits) pour reconstruire un contexte proche de cette session.  
**Projet :** FoodKing (Laravel + Vue), workspace typique :  
`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`

---

## 1. Sources « système » (règles, skills, agents)

| Type | Chemin |
|------|--------|
| Règles Cursor (workspace) | `.cursor/rules/*.mdc` — lire `global.mdc`, `scope.mdc`, `safety.mdc`, `playwright.mdc`, `architecture.mdc`, `project-continuity.mdc`, `project-invariants.mdc`, `human-gates.mdc` si pertinents |
| Skill passation projet | `.cursor/skills/project-handoff/SKILL.md` |
| Orchestration agents (FoodKing) | `AGENTS.md` (racine projet) |
| Guide Claude projet | `CLAUDE.md` (racine) |
| Continuité | `PROJECT_CONTINUITY.md` si présent à la racine (sinon voir `plans/`) |
| Transcripts Cursor (hors workspace) | `~/.cursor/projects/Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/agent-transcripts/*.jsonl` — historique brut des chats parent |

---

## 2. Documentation métier & plans (alimentation raisonnement)

| Document | Chemin |
|----------|--------|
| Règles business (prix SSOT, états, coupons) | `docs/BUSINESS_RULES.md` — **note :** section stock peut être obsolète vs dispo branche (P1) |
| Plans P1–P3 handoff | `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md`, `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md`, `plans/PLAN_P3_REFUND_HANDOFF.md` |
| Audits / reviews antérieurs | `reports/review/*.md` (nombreux, dont `AUDIT_POS_GLOBAL_2026-04-18.md`, `VERIFY_P9_*`, etc.) |

---

## 3. Rapports produits dans cette conversation (audit POS 110 % + P global)

**Rapport synthèse des cycles P (P1→P10)**  
- `reports/review/REPORT_GLOBAL_P_IMPLÉMENTATIONS_2026-04-19.md`

**Dossier audit POS 110 % (read-only, 2026-04-19)**  
- `reports/review/AUDIT_POS_110_EXECUTIVE_2026-04-19.md` — synthèse exécutive  
- `reports/review/AUDIT_POS_110_FINDINGS_TRACKER.md` — tableau findings ID × axe  
- `reports/review/AUDIT_POS_110_ARCHITECTURE_STATE_2026-04-19.md` — axes 1–2  
- `reports/review/AUDIT_POS_110_FISCAL_NF525_2026-04-19.md` — axe 3  
- `reports/review/AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md` — axes 4, 10  
- `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` — axes 5–7  
- `reports/review/AUDIT_POS_110_KDS_OSS_DRAWER_2026-04-19.md` — axes 8–9  
- `reports/review/AUDIT_POS_110_SECURITY_2026-04-19.md` — axe 11  
- `reports/review/AUDIT_POS_110_DATA_2026-04-19.md` — axe 12  
- `reports/review/AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md` — axe 13  
- `reports/review/AUDIT_POS_110_OBSERVABILITY_PERF_2026-04-19.md` — axes 14–15  
- `reports/review/AUDIT_POS_110_TESTS_REGRESSIONS_2026-04-19.md` — axes 16–17  
- `reports/review/AUDIT_POS_110_I18N_DEPLOY_2026-04-19.md` — axes 18–19  
- `reports/review/AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`  
- `reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md`

---

## 4. Fichiers code touchés par les cycles P4–P10 (référence rapide)

| Cycle | Fichiers principaux |
|-------|---------------------|
| P4 KDS | `app/Services/KitchenDisplaySystemOrderService.php`, `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`, `resources/js/store/modules/kitchenDisplaySystemOrder.js`, `tests/Feature/KdsChangeStatusConcurrencyTest.php` |
| P5 | `app/Http/Requests/OrderRequest.php`, `tests/Feature/OrderRequestNegativeTotalTest.php` |
| P6 | `app/Http/Requests/TableOrderRequest.php`, `tests/Feature/TableOrderNegativeTotalTest.php` |
| P7 | `OrderRequest.php`, `TableOrderRequest.php`, `PosOrderRequest.php`, tests associés, `PosOrderRequestNullableTotalTest.php` |
| P8 | `app/Http/Requests/CouponCheckRequest.php`, `tests/Feature/CouponCheckNegativeTotalTest.php` |
| P9 | `app/Http/Requests/CouponRequest.php`, `tests/Feature/CouponRequestNegativeAmountsTest.php` |
| P10 | `app/Http/Requests/OrderSetupRequest.php`, `tests/Feature/OrderSetupRequestNegativeValuesTest.php` |

**Fiscal / NF525 (contexte audit, pas modifié par P5–P10)**  
- `app/Services/Fiscal/FiscalSequenceService.php`, `ZReportService.php`, `XReportService.php`, `AuditLogService.php`  
- `tests/Feature/Fiscal/*` (nombreux)

---

## 5. Commits récents sur `feat/ton-sujet` (état au moment de la passation)

```
c00a8cd61 feat(settings): P10 min:0 on OrderSetupRequest numeric fields
649d18d06 feat(coupon): P9 min:0 on admin CouponRequest money fields
4113423fb feat(coupon): P8 reject negative total on coupon-checking
19476d56b feat(order): P7 min:0 on subtotal, discount, delivery charge, cash received
952b840b1 feat(order): P6 reject negative subtotal/total on table dining orders
87491043c feat(order): P5 reject negative total on kiosk OrderRequest
e18344af4 feat(kds): lock change-status transaction and return 409 on status drift
b007c6344 feat(P3): retour DELIVERED→RETURNED audit NF525 + motif obligatoire
a43c5b9e2 feat(P2): POS titre-restaurant + handoff multi-tender
b76506ae9 feat(P1): garde checkout rupture branche + prune panier kiosk
```

---

## 6. Modifications locales récentes (hors historique git — à vérifier dans le working tree)

L’utilisateur peut avoir des **diffs non commit** incluant notamment :

- `resources/js/components/admin/pos/PosComponent.vue` — panneau commandes kiosk cash : expansion détails (variations, extras, instructions, allergènes), imports `.vue` explicites, état `expandedKioskCashOrders`, méthodes `toggleKioskCashOrderDetails` / `isKioskCashOrderExpanded`.
- `tests/Feature/OrderCancellationLoyaltyTest.php` — imports / style (`LoyaltyService` instanciation).
- `app/Http/Requests/KioskMachineRequest.php` — formatage Pint-like, docblocks réduits.

**Action nouvelle session :** `git status` et relire ces fichiers avant de continuer.

---

## 7. Tests à relancer après reprise

```bash
./vendor/bin/phpunit tests/Feature/KdsChangeStatusConcurrencyTest.php
./vendor/bin/phpunit tests/Feature/OrderRequestNegativeTotalTest.php
./vendor/bin/phpunit tests/Feature/TableOrderNegativeTotalTest.php
./vendor/bin/phpunit tests/Feature/PosOrderRequestNullableTotalTest.php
./vendor/bin/phpunit tests/Feature/CouponCheckNegativeTotalTest.php
./vendor/bin/phpunit tests/Feature/CouponRequestNegativeAmountsTest.php
./vendor/bin/phpunit tests/Feature/OrderSetupRequestNegativeValuesTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/
```

---

## 8. Fichiers 2 et 3 de cette passation

- **Historique & vision conversation :** `docs/cursor-handoff/02_HISTORIQUE_CONVERSATION_ET_VISION.md`  
- **Démarrage nouvelle session :** `docs/cursor-handoff/03_DEMARRAGE_NOUVELLE_SESSION.md` (**à ouvrir en premier dans le nouveau chat** après une lecture rapide des deux autres)

---

*Généré pour continuité multi-compte Cursor — même dossier projet.*
