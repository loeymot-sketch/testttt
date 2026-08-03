# P4 — OSS / écran état commande (client) — E2E massif — 2026-05-04

| Champ | Valeur |
| --- | --- |
| **TAG plan** | `P4` |
| **Surface** | `resources/js/components/admin/orderStatusScreen/` (+ routes OSS documentées) |
| **Prérequis** | URL OSS accessible dans l’environnement ; commande avec **token public** ou identifiant affiché sur ticket kiosk. |
| **Test strategy** | `playwright-full-e2e` **ou** `SKIP` documenté si route non exposée en local. |

## 1. Périmètre

| Bloc | Capacité | STEP préfixe |
| --- | --- | --- |
| O1 | Accès OSS depuis URL ticket / QR | `S4O1_` |
| O2 | Affichage statut aligné KDS (PREPARING / READY) | `S4O2_` |
| O3 | Rafraîchissement auto (polling / WS) | `S4O3_` |
| O4 | Cas erreur : commande inconnue | `S4O4_` |

## 2. Dépendances

- **Amont** : P1 ou P2 doit avoir produit une commande **traçable** (numéro / token).
- **Parallèle** : P3 change les statuts → OSS doit refléter (latence max notée).

## 3. Captures

Préfixe `P4__` ; chaque transition de statut depuis P3 déclenche une capture OSS correspondante.

## 4. Rapport `RAPPORT_P4.md`

- Si SKIP : raison (route absente, feature flag, auth).
- Sinon : tableau **statut KDS → capture OSS → délai ms**.

---

*Plan P4 — création documentaire uniquement.*
