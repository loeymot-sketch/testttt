# INDEX — Vérifications profondes post-alimentation (2026-04-20)

## Objectif global
Auditer **chaque implémentation et chaque rapport** récents (cycles P1→P10 + bundle audit POS 110 % + livraisons locales non commit) avec **double vérification multi-agents** pour atteindre un niveau **production-ready**.

Le but n'est **pas** d'introduire de nouveau code dans cette série, mais de :
1. **Valider** (preuve technique forte) ce qui est correct.
2. **Lister précisément** ce qui manque, est ambigu, ou diverge du plan / vision FoodKing.
3. **Décider** des cycles d'implémentation suivants (et leur priorité).

## Mode d'orchestration
- **PRIMARY_MODEL** par tâche : `claude-4.6-sonnet-low` ou `claude-4.6-opus-high` selon densité.
- **Multi-agent imposé** : chaque tâche déclenche au minimum **2 sous-agents `explore`** (lecture parallèle indépendante) + **1 `generalPurpose`** ou `foodking-planner-orchestrator` pour la synthèse contradictoire.
- **Aucun écrit code** dans ces tâches : seulement rapports `reports/review/VERIFY_*_2026-04-20.md`.
- Les **fixes** identifiés deviennent ensuite des cycles P séparés (PLAN → EXECUTE → VALIDATE → AUDIT) routés selon `.cursor/routing.md`.

## Lancement
À chaque fichier ci-dessous, copier-coller le bloc `### PROMPT À COLLER` dans un **nouveau chat Cursor** sur le même workspace. Ne pas en lancer plusieurs en parallèle (cohérence des subagents).

## Liste ordonnée

| # | Fichier | Sujet | Priorité |
|---|---------|-------|---------|
| 01 | `01_VERIFY_P1_AVAILABILITY.md` | Disponibilité branche, prune kiosk, AvailabilityService | P0 |
| 02 | `02_VERIFY_P2_MULTI_TENDER.md` | Ticket-restaurant, multi-tender futur | P1 |
| 03 | `03_VERIFY_P3_REFUND_RETURNED.md` | RETURNED, motif, cashback, audit NF525 | P0 |
| 04 | `04_VERIFY_P4_KDS_CONCURRENCY.md` | Lock, 409, OrderStateMachine sur ligne | P0 |
| 05 | `05_VERIFY_P5_P7_MIN_ZERO.md` | min:0 OrderRequest / TableOrderRequest / PosOrderRequest | P1 |
| 06 | `06_VERIFY_P8_P9_COUPONS.md` | CouponCheckRequest / CouponRequest admin | P1 |
| 07 | `07_VERIFY_P10_ORDER_SETUP.md` | OrderSetupRequest min:0 | P2 |
| 08 | `08_VERIFY_FISCAL_NF525_Z_OPEN.md` | Z.open hardening, X/Z, audit log, hash chain | P0 |
| 09 | `09_VERIFY_PAYMENTS_IDEMPOTENCY.md` | Double-paiement, double-order, Idempotency-Key | P0 |
| 10 | `10_VERIFY_BRANCH_ISOLATION.md` | Isolation + restore du rapport vide axes 5-7 | P0 |
| 11 | `11_VERIFY_KDS_OSS_DRAWER.md` | KDS / Order Status Screen / Cash drawer | P1 |
| 12 | `12_VERIFY_SECURITY.md` | XSS, CSRF, CORS, rate-limit, sanctum | P0 |
| 13 | `13_VERIFY_DATA_INTEGRITY.md` | Migrations, contraintes, soft-delete, schema | P1 |
| 14 | `14_VERIFY_SYNC_CROSS_SURFACE.md` | Pusher canaux, Outbox, EventContract | P0 |
| 15 | `15_VERIFY_OBSERVABILITY_PERF.md` | Logs, metrics, perf, requêtes N+1 | P1 |
| 16 | `16_VERIFY_TESTS_REGRESSIONS.md` | Couverture PHPUnit + Vitest + Playwright | P0 |
| 17 | `17_VERIFY_I18N_DEPLOY.md` | i18n complet, déploiement, env, secrets | P2 |
| 18 | `18_VERIFY_HIDDEN_RISKS.md` | Risques cachés, code mort, double-source | P1 |
| 19 | `19_VERIFY_AVAILABILITY_TOGGLE_ROUTE.md` | Nouvelle route `menu/availability/toggle` + throttle kiosk-menu | P0 |
| 20 | `20_VERIFY_BUSINESS_RULES_DOC_ALIGNMENT.md` | Doc BUSINESS_RULES vs code (P1 stock, RETURNED, coupons) | P1 |

## Ordre recommandé d'exécution
1. **Bloc fiscal & paiement** (P0) : 08, 09, 10, 03
2. **Bloc concurrence & sync** (P0) : 04, 14, 11
3. **Bloc validations & UX** : 05, 06, 07, 02, 19
4. **Bloc sécurité & data** : 12, 13, 18
5. **Bloc qualité & doc** : 16, 15, 17, 20
6. **Bloc disponibilité ferme** : 01

## Sortie attendue après les 20 tâches
- 20 rapports `reports/review/VERIFY_*_2026-04-20.md`.
- 1 méta-tracker `reports/review/VERIFY_TRACKER_2026-04-20.md` (à générer après les 20) listant chaque finding `OK / WARN / FAIL` + cycle P proposé.
- 1 plan d'implémentation `plans/PLAN_POST_VERIFY_2026-04-20.md` consolidant les FAIL/WARN en cycles P11+.
