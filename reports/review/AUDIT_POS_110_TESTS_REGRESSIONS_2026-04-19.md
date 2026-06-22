# Axes 16–17 — Tests & risques de régression

## Inventaire tests « fiscaux » (non exhaustif)

Le dossier `tests/Feature/Fiscal/` contient **>20** fichiers (liste dans agent fiscal) : chaîne audit, immutabilité, Z/X, séquence, permissions, rate limit, archive, secrets prod, etc.

## Tests qui passent mais ne prouvent pas …

| Test / type | Ce que ça ne garantit **pas** |
|-------------|-------------------------------|
| **Validation P5–P10** | Cohérence **prix SSOT** — seulement bornes entrées. |
| **`phpunit` vert** | Absence de race **intra-branch** sur deux POST POS sans idempotence partagée. |
| **Fiscal unit** | Comportement **cache Redis** absent / reset en prod. |

## Régressions post phases gelées

- Toute évolution `OrderService.php` / `FrontendOrderService.php` = **haut risque** (`F-REG-001`).
- P4 KDS touche service + store — veiller aux effets OSS/POS indirects (broadcast).

**Liens tracker :** F-TEST-001, F-TEST-002, F-REG-001.
