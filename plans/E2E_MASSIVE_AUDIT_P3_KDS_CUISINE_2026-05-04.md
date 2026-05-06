# P3 — Écran cuisine (KDS) — E2E massif — 2026-05-04

| Champ | Valeur |
| --- | --- |
| **TAG plan** | `P3` |
| **Surface** | `resources/js/components/admin/kitchenDisplaySystem/` |
| **Prérequis** | Compte **chef** / station ; commandes créées depuis P1 ou P2 (ou seed) ; `branch_id` aligné. |
| **Test strategy** | `playwright-full-e2e` ; attention **workers: 1** (login). |

## 1. Inventaire KDS

### Bloc D0 — Auth & station

| Id | Capacité | STEP |
| --- | --- | --- |
| D0a | Login KDS | `S3D0a_` |
| D0b | Choix station / filtre | `S3D0b_` |
| D0c | Multi-écran (si feature) | `S3D0c_` |

### Bloc D1 — Réception commandes

| Id | Capacité | STEP |
| --- | --- | --- |
| D1a | Nouvelle commande POS visible | `S3D1a_` |
| D1b | Nouvelle commande Kiosk visible | `S3D1b_` |
| D1c | Badge / son (si automatisables sinon manuel) | `S3D1c_` |

### Bloc D2 — Ligne article & rupture live

| Id | Capacité | STEP |
| --- | --- | --- |
| D2a | Item disponible : style normal | `S3D2a_` |
| D2b | Après toggle admin (P0) : ligne **grisée** / motif rupture | `S3D2b_` |
| D2c | Double broadcast → une seule mise à jour UI (test déjà évoqué en mémoire Graphiti) | `S3D2c_` |

### Bloc D3 — Workflow statut

| Id | Capacité | STEP |
| --- | --- | --- |
| D3a | PREPARING | `S3D3a_` |
| D3b | PREPARED / READY | `S3D3b_` |
| D3c | Annulation / bump recall si existant | `S3D3c_` |

### Bloc D4 — Résilience transport

| Id | Capacité | STEP |
| --- | --- | --- |
| D4a | WS down : polling fallback (intervalle documenté) | `S3D4a_` |
| D4b | Reconnect storm (ne pas casser auth session) | `S3D4b_` |

## 2. Preuve cross-P1/P2

Pour chaque commande testée sur KDS, référencer dans `manifest.md` :

- `order_id` ou `queue_number` (si visible)
- heure création commande côté POS/Kiosk
- capture KDS associée

## 3. Rapport `RAPPORT_P3.md`

- Section **Latence** (temps apparition ligne après création commande).
- Section **Sync** : comparaison horodatage (risque horloge — fact Graphiti).

## 4. Specs existantes

- `tests/Playwright/KdsMultiScreenPlaywrightTest.spec.js`
- `tests/js/kitchenDisplaySystemSync.spec.js` (Vitest — pas E2E mais référence comportement)

---

*Plan P3 — création documentaire uniquement.*
