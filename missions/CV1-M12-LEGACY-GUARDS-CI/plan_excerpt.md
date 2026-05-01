# Plan Excerpt — CV1-M12-LEGACY-GUARDS-CI

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § M-12

M-12 — `CAISSE_V1_LEGACY_GUARDS_CI_2026-04-25` (NO-GATE)

But: CI bloquante sur chemins legacy (`kiosk_implementation/`, `borne (Remix)/`, `pos-wizard.js`, etc.).

Allowlist (réf. `input.json`) : `scripts/lint-fk-legacy-imports.sh`, `scripts/lint-fk-legacy-routes.sh`, `scripts/scan-bundle-legacy.sh`, `scripts/lint-fk-archive-banner.sh`, `.github/workflows/legacy-guards.yml`, `docs/orchestration/LEGACY_QUARANTINE_2026-04-25.md`, bannières `kiosk_implementation/ARCHIVE_BANNER.md`, `borne (Remix)/ARCHIVE_BANNER.md`.

Critère: le pipeline casse sur réintroduction bundle legacy non autorisé.
