# Recommandations design POS v4 — Sortie W0+ — 2026-04-26

> **Verdict orchestral** : l'orchestration design jusqu'ici est **solide** — 6 livrables W0/W0+ produits (audit initial Codex, hyper-review Claude, plan exec final, ADR couleur draft, baseline perf, lint guards, namespace CSS concrétisé). Aucun pivot critique requis avant W1.
> Ce qui reste à faire est de l'**implémentation** (W1-W4), pas de l'audit ou du re-planning.

---

## 1. État de l'orchestration design — checkpoint

| Livrable | Statut | Référence |
|---|---|---|
| Audit design export Anthropic | ✅ | `AUDIT_POS_V4_EXPORT_DESIGN_2026-04-24.md` |
| Second opinion GPT (Codex) | ✅ | `missions/POS_V4_DESIGN_AUDIT_001/output_codex.json` |
| Rapport gaps systémique | ✅ | `RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25.md` |
| Plan exec final | ✅ | `plans/PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md` |
| Hyper-review Claude (12 lacunes, 10 red-team, 5 STOP) | ✅ | `HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL_2026-04-26.md` |
| Cross-check antagoniste | ✅ | `AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md` |
| Binding map skeleton 9 SFC | ✅ | `docs/design/BINDING_MAP_POS_V4.md` |
| ADR couleur draft + recommandation | ✅ | `docs/design/ADR_POS_V4_COULEUR.md` |
| Stub `pos-v4.css` + variables CSS concrétisées | ✅ | `resources/css/pos-v4.css` |
| Lint guards (pricing + status) câblés CI | ✅ | `tools/lint/*.mjs`, `.github/workflows/vitest.yml` |
| Baseline bundle mesuré + plan code splitting W1-A | ✅ | `reports/baseline/POS_V4_PERF_BASELINE_W0.md §4` |
| Backlog discovery W0+ documenté | ✅ | `BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md` |
| Audit consolidé W0+ Claude terminal | ✅ PASS-WITH-FIX 7.8/10 | `AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26.md` |

**Conclusion** : tout l'amont design (audit + planification + invariants + gardes) est en place. La phase suivante est l'**exécution visuelle** SFC par SFC.

---

## 2. Décision orchestrale — Claude design vs Claude Terminal Studio

| Type de fix | Outil recommandé | Pourquoi |
|---|---|---|
| Validation contraste / accessibility AA de la palette ADR | **Claude design** (court prompt) | Ne nécessite pas accès code, output 1 page, ~5% budget |
| Génération de design tokens map JSON depuis l'export | Claude design (si budget restant) | Ne touche pas au code FoodKing |
| Adaptation visuelle SFC (template + style scoped, dans namespace) | **Claude Terminal Studio** (= claude terminal `audit-brief` puis génération dirigée) | Accès code + invariants + ADR |
| Refactor structurel SFC (script + props + emits) | **codex-terminal `gpt-5.5-pro`** sub-agent | Implémentation complexe, déjà câblé via `npm run codex:complex` |

---

## 3. Prompt Claude design (à coller TEL QUEL — économe ~10% budget)

**Objectif** : valider l'accessibilité de la palette ADR et proposer des ajustements minimaux si nécessaire.

```
Audit accessibilité palette POS v4 — 200 mots max, format markdown.

CONTEXTE
- Application POS web (Vue 3) admin pour restaurants. Public: opérateurs caisse, lumière variable.
- Namespace CSS strict `.fk-pos-v4` (aucune contamination globale).
- Design export Anthropic POS v4 utilise primaire #FF006B. ADR FoodKing propose défaut #0084FF (cohérence
  admin existante) avec accent secondaire #FF006B. Theming par variable CSS.

PALETTE ADR ACTUELLE (cf resources/css/pos-v4.css)
- --fk-pos-primary:        #0084FF   (hover #0066CC, active #004D99)
- --fk-pos-accent:         #FF006B
- --fk-pos-text:           #1A1F36
- --fk-pos-text-muted:     #5A6075
- --fk-pos-bg:             #FFFFFF
- --fk-pos-bg-soft:        #F7F7FC
- --fk-pos-border:         #E5E7EB

DEMANDE
1. Calcule contrast ratios WCAG 2.2 AA pour ces paires :
   - text on bg, text on bg-soft, text-muted on bg, primary on bg, primary on bg-soft, accent on bg.
2. Indique PASS/FAIL niveau AA (text 4.5:1 normal, 3:1 large) et AAA (7:1 / 4.5:1).
3. Si une paire fail, propose UNE alternative HEX corrective (ne réinvente pas la palette).
4. Verdict global : ADOPT-AS-IS / ADOPT-WITH-FIX / REVIEW-NEEDED.

CONTRAINTE: pas de markdown image, pas de code, juste tableaux et verdict.
```

---

## 4. Ce qu'on NE demande PAS à Claude design (réservé Claude Terminal Studio / codex-terminal)

- L'adaptation visuelle Vue SFC (W1-W4 — codex-terminal `gpt-5.5-pro` + Claude terminal audit après chaque SFC mergé).
- Le code splitting `pos-shell.js` (W1-A — codex-terminal complex).
- La génération de Storybook entries pour les 9 SFC (W2 — codex-terminal routine).
- Tout ce qui touche aux invariants `pricing_ssot`, `OrderStatus`, `branch_id` (toujours via cycle bounded + Claude terminal audit).

---

## 5. Mini-checklist humain pour finaliser le design avant prod

- [ ] Tech Lead signe `docs/design/ADR_POS_V4_COULEUR.md §5` (option C ou autre)
- [ ] Tech Lead + Backend owner signent `reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md §6`
- [ ] Tech Lead + Backend owner signent `BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md §1` (PosComponent:1779) avant 2026-05-10 (sinon CI fail)
- [ ] QA approuve scope du gate brief PaymentComponent prop mutation (cycle dédié post-W4)
- [ ] (Recommandé) Coller le prompt §3 ci-dessus dans Claude design pour audit AA palette
