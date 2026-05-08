# Kiosk Design Execution — Master Plan
**Date :** 2026-05-08
**Auteur :** Claude orchestrateur (mode design execution senior)
**Owner validation :** ✅ Validation intégrale §5.1 + §5.2 + §5.3 + §5.4. Photos owner-handled.
**Source :** `plans/KIOSK_DESIGN_AUDIT_CART_PAYMENT_2026-05-08.md`
**Méthode :** GSTACK 7 phases — 21 items répartis en 3 waves parallèles + V2 plans

---

## §0 — Périmètre validé owner

| Catégorie | Items | Statut |
|---|---|---|
| **Quick wins (< 2h chacun)** | QW-1, QW-2, QW-3, QW-4, QW-5, QW-6 | ✅ Validé — exécution Wave Alpha |
| **Medium (< 1j chacun)** | M-1, M-2, M-3, M-4 | ✅ Validé — exécution Wave Alpha + Beta |
| **V1.x post-go-live** | V1x-1, V1x-2, V1x-3, V1x-4, V1x-5, V1x-6 | ✅ Validé — Wave Beta + Gamma + frozen-zone gates |
| **V2 différenciation** | V2-1 photos | 🔵 **Owner handled** — pas dans mon scope |
| **V2 différenciation** | V2-2, V2-3, V2-4, V2-5 | ✅ Validé — Wave Gamma plans détaillés (greenfield ou gated) |

**Total exécutable par moi :** 20 items (21 − photos owner-handled).

---

## §1 — Frozen-zones rappel

| Zone | Status | Action |
|---|---|---|
| `KioskCartComponent.vue` | 🔒 Frozen owner | Plans-only (V1x-1, V1x-3, V1x-6) — owner gate explicit pour exécuter |
| `KioskWizardComponent.vue` + 7 wizard kiosk | 🔒 Frozen owner | Plans-only (V2-2 drag-drop, V2-3 AI upsell) — owner gate |
| `KioskPaymentComponent.vue` | 🔒 Agent F-002+F-008+F-009 | Modifs scope-minimal additives autorisées (M-4 microcopy, V1x-2 modal) |
| `KioskAppComponent.vue` | 🔒 Agent F-016 | Modifs scope-minimal pour V1x-5 high contrast toggle (additif) |
| Tout F-001..F-017 backend | 🔒 Agent | Aucune modif |
| `KioskCashInstructionComponent.vue` | ✅ Modifiable | Wave Alpha |
| `KioskConfirmationComponent.vue` | ✅ Modifiable | Wave Alpha |
| `KioskAdminComponent.vue` | ✅ Modifiable | V1x-5 high contrast toggle |
| `tokens.css` (kiosk DS) | ✅ Modifiable greenfield additif | Wave Alpha |

---

## §2 — Décomposition 3 waves parallèles + plans V2

### Wave Alpha (10 items, ~10-12h cumulés, parallélisable 5 sub-agents)

| ID | Item | Sub-agent | Files modifiés |
|---|---|---|---|
| **QW-1** | `--kiosk-opacity-disabled: 0.5` token global | A1 | `resources/js/components/frontend/kiosk/tokens.css` ou `kiosk/ds/tokens-base.css` |
| **QW-2** | `:focus-visible { outline: 3px solid var(--kiosk-focus-ring) }` global | A1 | Idem (CSS global kiosk) + extend si nécessaire dans Cash + Confirmation scoped |
| **QW-3** | `aria-hidden="true"` sur emojis décoratifs Cash + Confirmation | A2 | `KioskCashInstructionComponent.vue` + `KioskConfirmationComponent.vue` |
| **QW-4** | Cash espacement `# 1 2 3 4` + tip `💡 Ticket imprimé après paiement` | A2 | `KioskCashInstructionComponent.vue` |
| **QW-5** | Confirmation : timer color muted + ETA cuisine + mode payment dans card | A3 | `KioskConfirmationComponent.vue` |
| **QW-6** | Confirmation : total points fidélité cumulés (gagnés + balance) | A3 | `KioskConfirmationComponent.vue` |
| **M-1** | Skeleton loaders pendant fetch menu | A4 | Nouveau `KioskSkeletonLoader.vue` (greenfield, utilisable où non-frozen) |
| **M-2** | Cash timer pause si interaction (mouse/touch détecté) | A2 | `KioskCashInstructionComponent.vue` |
| **M-3** | Confirmation 5-star CSAT inline (post-commande) | A3 | `KioskConfirmationComponent.vue` + endpoint backend rating |
| **M-4** | Payment microcopy `🔒 Transaction sécurisée TLS 1.3` + logos cartes acceptées | A5 | `KioskPaymentComponent.vue` (additive scope-minimal — agent territory check) |

### Wave Beta (3 items, ~6-8h, parallélisable 2 sub-agents)

| ID | Item | Sub-agent | Files |
|---|---|---|---|
| **V1x-2** | Modal confirmation paiement refusé | B1 | `KioskPaymentComponent.vue` (additive modal section + scoped CSS) |
| **V1x-4** | KsButton atomic component DS refactor | B2 | `resources/js/components/frontend/kiosk/ds/KsButton.vue` (existe déjà ?) — étendre + appliquer dans Cash + Confirmation |
| **V1x-5** | High contrast mode toggle PMR | B2 | `KioskAdminComponent.vue` (toggle UI) + tokens `tokens-aaa.css` (déjà préparé) + persistance localStorage |

### Wave Gamma (V2 plans + frozen gates, ~3 sub-agents)

| ID | Item | Sub-agent | Type |
|---|---|---|---|
| **V2-2** | Drag-drop ingrédients wizard | G1 | Plan détaillé — owner gate explicit (touche frozen wizard) |
| **V2-3** | Recommandation IA upsell | G2 | Plan détaillé + scaffolding service backend (greenfield app/Services/Recommendation) — frontend gated |
| **V2-4** | Voice ordering kiosk + drive-thru | G2 | Plan détaillé + scaffolding `app/Services/Voice` + Web Speech API stub composant Vue greenfield |
| **V2-5** | Skinning saisonnier framework | G3 | Plan détaillé + scaffolding `kiosk-themes` (greenfield), pas exécuté sur composants frozen |
| **V1x-1, V1x-3, V1x-6** | Cart : spacing tokens + image responsive + aria-label variations | G3 | Sub-plans gated owner (frozen wizard) |

### Wave Delta (Validation + commits + Graphiti)

- Run cumulative tests
- Commits propres par wave
- Final report
- Graphiti push

---

## §3 — Stratégie GSTACK

```
THINK   ✅ Audit déjà livré (KIOSK_DESIGN_AUDIT_CART_PAYMENT_2026-05-08.md)
PLAN    ✅ Ce master plan
BUILD   ⏳ Wave Alpha 5 sub-agents → Wave Beta 2 → Wave Gamma 3 → 10 sub-agents totaux
REVIEW  ⏳ Anti-drift checklist par sub-agent + frozen-zones grep guard
TEST    ⏳ Vitest + phpunit cumulative après chaque wave
SHIP    ⏳ 1 commit par wave (3 commits + Graphiti)
REFLECT ⏳ Final report KIOSK_DESIGN_EXECUTION_FINAL_REPORT
```

---

## §4 — Sub-agent allocation détaillée

| Sub-agent | Items | Effort cible |
|---|---|---|
| **A1** Tokens + focus-visible global | QW-1, QW-2 | 1.5h |
| **A2** Cash instruction polish (4 items) | QW-3 cash, QW-4, M-2 | 2h |
| **A3** Confirmation polish (4 items) | QW-3 confirmation, QW-5, QW-6, M-3 | 3h |
| **A4** Skeleton loaders | M-1 | 1.5h |
| **A5** Payment microcopy + logos | M-4 | 1.5h |
| **B1** Modal payment error | V1x-2 | 2h |
| **B2** KsButton DS atomic + High contrast toggle | V1x-4, V1x-5 | 4h |
| **G1** V2-2 drag-drop plan + V1x-cart gates plans | V2-2, V1x-1, V1x-3, V1x-6 | 1.5h plans-only |
| **G2** V2-3 AI upsell + V2-4 voice ordering plans + scaffolding | V2-3, V2-4 | 3h |
| **G3** V2-5 skinning framework plan + scaffolding | V2-5 | 2h |

**Total estimation parallèle wall-clock : 4-6h** (waves séquentielles, items intra-wave parallèles)

---

## §5 — Acceptance criteria globaux

- [ ] Tous les 20 items livrés (10 Wave Alpha + 3 Wave Beta + 4 plans V2 + 3 gates Cart)
- [ ] 0 régression tests existants
- [ ] 0 touche frozen-zones non-gated (Cart, wizards, agent files)
- [ ] Build production OK
- [ ] Vitest + PHPUnit cumulative verts
- [ ] Plans V2 + gates documentés actionables
- [ ] Commits propres par wave (`design(wave-X): <résumé>`)
- [ ] Graphiti push final

---

## §6 — Discipline non-négociable

- TDD strict pour items avec logique (CSAT endpoint, skeleton trigger conditions, high contrast toggle persistence)
- Visual changes : verifier rendu via build production + spot-check des Vue templates
- Frozen-zones grep guard avant chaque commit
- Format commit `design(<wave>): <résumé>` distinct de `harden(...)` et `parallel(...)`
- Reporting léger : 1 résumé concis par sub-agent suffit (vs full plan détail)

---

## §7 — Engagement final

À l'issue Wave Delta : la majorité des recommandations design owner-validées seront implémentées (10 Wave Alpha + 3 Wave Beta = 13 items live). Les 4 plans V2 (drag-drop, AI upsell, voice, skinning) + 3 gates Cart V1.x seront documentés exhaustivement, prêts à exécution post-merge owner-gate ou V2 sprint dédié.

Photos articles HD restent owner-handled per validation explicite.

— *Le design audit a livré la vision. L'exécution livre la réalité. Le plan tient les deux ensemble.*
