# Plan Excerpt — CV1-M02-SENTINEL-BASELINE

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § M-02

M-02 — `CAISSE_V1_SENTINEL_BASELINE_2026-04-25` (NO-GATE)

But: **18 sentinels fail-first** (PHP + Vitest + Playwright) + lint statiques, baseline rouge documentée, mapping `sentinel ↔ FK-### ↔ M-XX`. Pas de vert artificiel: chaque test échoue pour la raison documentée jusqu’à la mission cible.

Allowlist: `tests/Feature/Sentinels/*`, `tests/js/sentinels/*`, `tests/Playwright/sentinels/*`, scripts `lint-fk-*.sh`, `reports/sentinels/CAISSE_V1_BASELINE_RUN_2026-04-25.log`, `reports/sentinels/CAISSE_V1_SENTINEL_INDEX.md`. Off-limits: code produit sauf hooks de test justifiés.

+ statiques: `OrderStatusEnumKioskHardcodeLintTest`, `LegacyImportGuardLintTest`, `BundleScanLegacyTest`, `PaymentComponentPropMutationVitestSentinel` (compteur M-21b).

PASS: tous **rouges pour la raison documentée**; index à jour.
