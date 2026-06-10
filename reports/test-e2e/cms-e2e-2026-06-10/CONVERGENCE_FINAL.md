# CONVERGENCE FINALE — test-e2e CMS Gestion (2026-06-10)

**Verdict : CONVERGED au round 3.** P0+P1 = 0 sur 2 cycles propres consécutifs (rounds 2 et 3), set résiduel P2/P3 **identique** entre les deux cycles (set-equality, 0 flake). Harness : :8767 / `foodking_e2e`, 17 états × quartet (PNG+DOM+console+network), 3 rounds complets, cleanup soft-delete prouvé à chaque round.

## Cycle 1 → verdict RED (0 P0, 5 P1) — tous FIXÉS puis re-prouvés sur captures fraîches
| ID | P1 | Fix appliqué | Preuve round-2/3 |
|---|---|---|---|
| A-001 | Verrou anti-depth-3 inopérant à l'exécution (snapshot `allCategories` stale du mounted()) | Union snapshot+store + refresh post-save + `Number()` coercion | `A4-parent-options.json` = `["Aucune"]` (était 10 options) |
| A-002 | Boutons d'action sans glyphe (classes `lab-*` inexistantes dans la fonte : delete-line, cog, copy, view-line, add-line, toggle-on, refresh, category, magic-star, arrow-left…) | Sweep complet → swap vers les 172 glyphes réels de `lab.css` | Zooms PNG : tous les pictos nets |
| B-001 | 4 clés i18n brutes dans le fieldset presets (`label.composer.choice_logic`, `preset_*`) | Clés ajoutées ×5 langues | DOM iframe 100% FR, 0 clé brute |
| B-002 | Panneau « Options et prix » mensonger (flatMap mélangeait viandes+sauces sur un step « taille ») | Affichage limité à une source EXPLICITE | `prices:false` quand source = Toutes les options |
| B-003 | CSP `frame-ancestors 'none'` violée par l'auto-iframe du builder (P0 latent si enforce) | `frame-ancestors 'self'` (`config/security.php:36`) — sentinel CSP 3/3 vert | Console B5 propre |

## Fronts propres (3 rounds)
Consoles 17/17 (allowlist ws:6001 uniquement) · networks 17/17 zéro ≥400 · 0 clé i18n brute sur 18 DOM · intégrité numérique (compteurs 12→13→12 / 45→46→45, prix 5,00 € FR partout) · sync-proof outbox+disponibilité cohérent aux 3 rounds · wizard Tacos jamais muté · DB laissée propre.

## Résiduels divulgués (non bloquants, stables 2 cycles) → repris dans `plans/GOAL_CMS_POLISH_FINITION_2026-06-10.md`
**P2 (4)** : R2-NEW-01 sources du picker incomplètes (pas de groupe Taille/Viande 2 — `buildAvailableSources` ne dérive que du 1er item représentatif) · A-003 liste settings n'interleave pas les sous-catégories sous leur parent (le Studio + rail stock le font) · A-004 dérive palette CTAs Studio (`rose/green-700` vs brand `#F4501E`) · A-005 boutons icône header/close sans nom accessible.
**P3 (7)** : pluriels « 1 articles » · file input natif anglais · titre modal générique create/edit · « INDISPONIBLES » tronqué · verrou A4 sans hint explicatif · glyphe ⊕ sémantiquement faible pour Dupliquer · selects tronqués (« Toutes les op… »).

Artefacts : `round-{1,2,3}/wave-{A,B}/` + findings JSON par round. Agents : capture a7d394d8, adversarial ad7f4738/a09a3f66 (R1), af7f9206 (R2), a791c10e (R3).
