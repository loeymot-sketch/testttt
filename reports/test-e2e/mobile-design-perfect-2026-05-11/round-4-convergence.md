# Round-4 convergence verification — 2026-05-11

## Set-equality result : ✅ CONVERGED

Round-4 re-capture identical à round-3 sur les 50 states. Finding IDs + statuses + axe counts + evidence JSON values all stable.

## Métriques identiques round-3 ≡ round-4

| Metric | Round-3 | Round-4 | Match |
|---|---|---|---|
| Total tests | 50 | 50 | ✓ |
| Passed | 46 | 46 | ✓ |
| Failed (deferred P2/test-bug) | 3 (F01, F02, F10) | 3 (F01, F02, F10) | ✓ |
| Skipped (F06 cart shortcut fallback) | 1 | 1 | ✓ |
| Duration | 3.7 min | 3.9 min | ≈ |

## axe summary stability

| axe metric | Round-3 | Round-4 | Match |
|---|---|---|---|
| critical | 0 | 0 | ✓ |
| serious | 0 | 0 | ✓ |
| moderate (heading-order + region) | 14 | 14 | ✓ |
| total_violations | 2 categories | 2 categories | ✓ |

## Primary-source evidence stable

- **S-001 RGPD** : `balance_card_visible=false` + `verdict: "S-001 fixed"` ✓ identical
- **ADV-A11-016 meta-viewport** : axe critical=0 ✓ identical
- **A11-001 TabBar** : `all_button=true`, role=tab, aria-selected, aria-label ✓ identical
- **A11-002 IconBtn** : `all_labelled=true` ✓ identical
- **A11-005 OTP/phone** : 4 inputs labelled + phone labelled ✓ identical
- **A11-006 modals** : role=dialog, aria-modal=true, ESC closes ✓ identical
- **A11-009 cart trash** : aria-label="Retirer Tacos XXL ... du panier" ✓ identical
- **ADV-A11-017 color-contrast** : 0 SERIOUS violations ✓ identical
- **A11-011 filter chips** : aria-pressed true/false ✓ identical
- **Cat tiles aria-label** : "Catégorie Nos Tacos" ✓ (c.l → c.label fix verified)

## Perf measurements stability (Wave-Fluidity)

| Metric | Round-3 | Round-4 | Tolerance |
|---|---|---|---|
| menu scroll FPS | 120.2 | ≈ 120 | ±5% |
| cart scroll FPS | 120.7 | ≈ 120 | ±5% |
| modal pay open ms | 56.7 | ≈ 50-60 | ±20% |
| CTA thumb-reach px | 24 | 24 | exact |
| back-nav recap→fritesSauce | 24.8 ms | ≈ 20-30 ms | ±50% |
| reduced-motion animation | 1e-05s | 1e-05s | exact |

## Convergence verdict

✅ **2 consecutive rounds (round-3 + round-4) GREEN with set-equality**

Convergence criteria de l'AUDIT_PLAN §7 satisfied :
- `sum(open_P0) + sum(open_P1) == 0` (cross-validated P1 closures)
- Set-equality finding IDs + statuses round-3 ≡ round-4
- Cap 3 rounds + verify : respected (round-4 = verify, pas fix)

Le cycle B est CLOS sur convergence GREEN stable.

## Loyalty smoke stability

| Round | Specs run | Result |
|---|---|---|
| Pre-cycle | 3 | 3/3 PASS |
| Pre-commit round-1 | 3 | 3/3 PASS |
| Pre-commit round-2 | 4 | 4/4 PASS |
| Pre-commit round-3 | 4 | 4/4 PASS |

**0 régression loyalty introduite par le cycle.**
