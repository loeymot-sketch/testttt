# Plan Excerpt — CV1-M21A-QUICKWINS-LOT0

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § M-21a

M-21a — quickwins LOT-0 (NO-GATE) — rebrand `POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26`

- **FIND-01**: lier le champ `discountReason` (template `PosComponent.vue` — v-model manquant / incohérent avec le script).
- **FIND-09**: `KitchenDisplaySystemComponent` — `Swiper` `dir` selon locale (RTL), pas de hardcode `ltr` inadapté.
- Tâche accessoire: retirer import mort (focustrap) dans `PosComponent.vue` si toujours présent.

**PRIMARY**: `codex-extension`. Aucune logique de prix côté client. Voir `execute_brief.md` + `input.json` (allowlist Vitest).
