# P1 — Caisse vendeur (POS) — E2E massif — 2026-05-04

| Champ | Valeur |
| --- | --- |
| **TAG plan** | `P1` |
| **Surface** | `resources/js/components/admin/pos/` (POS caisse) |
| **Prérequis** | P0 terminé (catalogue + stocks connus) ; compte **caissier** ; `branch_id` = `BR_E2E_STOCK`. |
| **Test strategy** | `playwright-full-e2e` ; **ne pas** assert de prix calculé côté JS — comparer aux **totaux affichés** issus API ou ticket final si export. |

## 1. Inventaire des capacités POS à couvrir (décomposition « massive »)

### Bloc A — Auth & session

| Id | Capacité | STEP préfixe |
| --- | --- | --- |
| A1 | Login caissier → landing POS (pas dashboard client) | `S1A_` |
| A2 | F5 → `authcheck` → retour même surface | `S1A_` |
| A3 | Timeout session simulé (si testable) | `S1A_` |

### Bloc B — Catalogue & recherche

| Id | Capacité | STEP préfixe |
| --- | --- | --- |
| B1 | Liste items paginée / recherche texte | `S1B_` |
| B2 | Filtre catégorie | `S1B_` |
| B3 | Tuile article **rupture globale** (`E_GLOBAL_OFF`) grisée / non ajoutable | `S1B_` |
| B4 | Article avec variation **L** en stock 0 : variation non sélectionnable ou commande bloquée | `S1B_` |

### Bloc C — Panier & wizard

| Id | Capacité | STEP préfixe |
| --- | --- | --- |
| C1 | Ajout `A_SANDWICH` → wizard étapes | `S1C_` |
| C2 | Barre progression / badges étapes (cf. checklist `playwright.mdc`) | `S1C_` |
| C3 | Total **temps réel** visible (affichage seulement) | `S1C_` |
| C4 | Instruction ticket (VIANDES / SUPPLEMENTS / FORMULE si UI) | `S1C_` |
| C5 | Annulation dernière ligne / quantité | `S1C_` |

### Bloc D — Promotions & remises

| Id | Capacité | STEP préfixe |
| --- | --- | --- |
| D1 | Coupon (si branch promos) | `S1D_` |
| D2 | Remise manuelle avec **raison obligatoire** | `S1D_` |

### Bloc E — Paiement

| Id | Capacité | STEP préfixe |
| --- | --- | --- |
| E1 | Paiement **cash** + monnaie | `S1E_` |
| E2 | Paiement **carte** (4 derniers chiffres) | `S1E_` |
| E3 | Erreur paiement / retry UI | `S1E_` |

### Bloc F — Post-vente & flux liés

| Id | Capacité | STEP préfixe |
| --- | --- | --- |
| F1 | Ticket / reçu (HTML ou print preview) | `S1F_` |
| F2 | Liste **commandes kiosk cash** (si activité) | `S1F_` |
| F3 | Notifications realtime (badge) — dépend broadcast | `S1F_` |

## 2. Séquence d’étapes détaillées (exemple — à dupliquer par bloc)

Chaque ligne = **une capture** + **double vérification**.

| STEP_ID | Description | Capture `P1__...` | Assertion auto minimale | Manuel |
| --- | --- | --- | --- | --- |
| `S1A_01` | Login caissier | `P1__S1A_01__pos__login.png` | `expect(page).toHaveURL(/login/)` puis redirect | visuel logo |
| `S1A_02` | POS chargé | `P1__S1A_02__pos__shell.png` | `data-testid` ou selector panier | pas d’overlay erreur |
| `S1B_10` | Recherche `A_SANDWICH` | `P1__S1B_10__pos__search_result.png` | carte visible | prix lisible |
| `S1C_20` | Wizard étape 1 | `P1__S1C_20__pos__wizard_step1.png` | bouton suivant enabled | progression |
| `S1E_40` | Paiement cash | `P1__S1E_40__pos__payment_cash.png` | commande créée (toast / redirect) | ticket cohérent |
| `S1E_41` | Paiement carte | `P1__S1E_41__pos__payment_card.png` | note `pos_payment_note` si exposée | masque CB |

*(Le responsable d’exécution dupliquera les lignes pour **toutes** les lignes des tableaux Bloc A–F.)*

## 3. Non-régression OrderStatus

- Toute assertion de statut : utiliser **enum** / libellés contrôlés — pas de chaîne magique non documentée (invariant I2).

## 4. Rapport `RAPPORT_P1.md`

Structure identique P0 + section **« Temps par bloc »** (perf P3) + **« Flaky »** (réseau, WS).

## 5. Specs existantes à étendre

- Flows critiques listés dans `docs/PLAYWRIGHT_MCP_OPS.md` §2.
- Fichiers sous `tests/Playwright/critical-flow/` pour alignement naming.

---

*Plan P1 — création documentaire uniquement.*
