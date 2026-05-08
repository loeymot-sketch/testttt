# Plan Design — V1x-1 — Migration Cart + Payment vers spacing tokens
**Date :** 2026-05-08 | **Wave :** Gamma G1 | **Type :** Design refactor — token migration | **Status :** ⏸️ Plan-only — owner gate explicit requis (Cart frozen)

> **OWNER GATE EXPLICIT REQUIS** — Cart frozen (1/8 wizard list). Aucune modif sans débloque explicit.

## §1 — Constat actuel

**Cart** (`KioskCartComponent.vue` <style scoped>) : valeurs px brutes `4, 6, 8, 10, 12, 14, 16, 20, 24, 28, 32, 38, 40, 44, 48, 64`. Aucune référence à `var(--kiosk-space-*)`.

Exemples relevés : `gap: 12px; padding: 16px 28px 0;` (line 526), `padding: 20px 28px 16px;` (575), `padding: 8px 16px;` (615), `padding: 14px;` (676), `padding: 16px 20px;` (808).

**Payment** : idem, `10, 12, 14, 16, 20, 24, 28, 32, 40` brutes. `padding: 24px 32px 20px;` (header 639), `28px 32px;` (methods 670), `24px 28px;` (method 707), `40px;` (processing 745).

**Tokens existants** (`tokens.css` 105-119) : `--kiosk-space-1=4` `-2=8` `-3=12` `-4=16` `-5=20` `-6=24` `-8=32` `-10=40` `-12=48` `-14=56` `-16=64` `-20=80`. **Pas de tokens pour 6, 14, 28, 38, 44**.

## §2 — Mapping cible

| px | Token | OK |
|---|---|---|
| 4 | --kiosk-space-1 | ✅ |
| 8 | --kiosk-space-2 | ✅ |
| 12 | --kiosk-space-3 | ✅ |
| 16 | --kiosk-space-4 | ✅ |
| 20 | --kiosk-space-5 | ✅ |
| 24 | --kiosk-space-6 | ✅ |
| 32 | --kiosk-space-8 | ✅ |
| 40 | --kiosk-space-10 | ✅ |
| 48 | --kiosk-space-12 | ✅ |
| 64 | --kiosk-space-16 | ✅ |

**Lacunes — decision owner-required** :
- 6 → introduire `--kiosk-space-1-5: 6px` OU rounding 4/8 OU keep px
- 10 → `--kiosk-space-2-5: 10px` OU rounding 8/12
- 14 → `--kiosk-space-3-5: 14px` OU rounding 12/16
- 28 → `--kiosk-space-7: 28px` (recommandé — scale base-4)
- 38 → rounding 40 (recommandé)
- 44 → `--kiosk-space-11: 44px` (touch target WCAG)

## §3 — Sub-tasks (post-gate)

1. **Lecture tokens.css** (15 min) — confirmer + ajouter new tokens si decision option A. Bloc `/* [V1x-1] Tokens introduits pour migration Cart+Payment */`
2. **Cart scoped CSS migration** (1h) — `KioskCartComponent.vue` lignes ~520-1050. Pattern strict search-replace TRBL. Préserver ordre déclarations. Ne pas toucher couleur/font-size/border-radius.
3. **Payment scoped CSS migration** (1h) — `KioskPaymentComponent.vue` lignes ~625-965. Coordination agent F-002/F-008/F-009.
4. **Visual regression check** (30 min) — Playwright manuel screenshots avant/après sur 1080×1920 + 1920×1080 + 3840×2160. Diff < 1px tolérance.

## §4 — Acceptance

- [ ] Tous *px de Cart scoped → var(--kiosk-space-*) ou justifié
- [ ] Idem Payment scoped
- [ ] 0 changement visuel rendu (tokens = px exact)
- [ ] Build Vite sans warning
- [ ] Vitest existant pass
- [ ] Screenshots before/after = 0px diff
- [ ] Si new tokens : commit séparé `feat(tokens): introduce --kiosk-space-7/11 for V1x-1`

## §5 — Effort

Sub-1: 15min · Sub-2: 1h · Sub-3: 1h · Sub-4: 30min · **Total : ~2h45 post owner gate**

## §6 — Frozen-zone

Cart owner-frozen (1/8 wizard). Payment agent F-002/F-008/F-009 — modifs scope-minimal additives autorisées. Tokens.css non-frozen.

**Gate explicit requis avant exécution** sur :
1. Mapping 6 px sans token exact (§2)
2. Autorisation Cart frozen-zone override
3. Coordination agent payment

## §7 — GSTACK

THINK ✅ · PLAN ✅ · BUILD ⏸️ gate · REVIEW ⏸️ anti-drift tokens-only · TEST ⏸️ Vitest+Playwright+visual diff · SHIP ⏸️ commit `design(kiosk-v1x-1)` · REFLECT ⏸️ post-merge log new tokens dans MEMORY.md

## §8 — Status

[ ] Pending owner gate · [ ] Gate opened — `docs/gates/GATE_V1X-1_2026-05-08.md` · [ ] Executed — cycle closed
