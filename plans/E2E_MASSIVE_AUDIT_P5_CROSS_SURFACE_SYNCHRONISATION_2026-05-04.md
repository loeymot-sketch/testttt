# P5 — Chorégraphie globale (centrale ↔ POS ↔ Kiosk ↔ KDS ↔ OSS ↔ paiement / ticket) — E2E massif — 2026-05-04

| Champ | Valeur |
| --- | --- |
| **TAG plan** | `P5` |
| **Type** | Scénarios **end-to-end transverses** (le plus proche de la demande « modifier stock puis voir partout »). |
| **Prérequis** | P0–P4 **PASS** ou équivalent minimal ; `BROADCAST_DRIVER` non silencieux en environnement cible ; 4 navigateurs/contextes Playwright (admin, POS, Kiosk, KDS) — profiles séparés. |

## 1. Principes d’architecture d’exécution

| Règle | Détail |
| --- | --- |
| **Ordre temporel** | Toujours noter `T0` (toggle admin) et mesurer `T1` (première observation POS), `T2` (kiosk), `T3` (KDS), `T4` (OSS). |
| **Isolation branche** | Un seul `branch_id` actif par scénario (invariant I3). |
| **Preuve prix** | Jamais de « prix attendu calculé en JS » ; comparer ticket/API backend si besoin. |
| **Captures** | Préfixe `P5__` ; inclure **sous-dossier** `P5/scenario_<name>/`. |

## 2. Scénarios (matrice)

### Scénario X1 — Rupture **ingrédient** (sauce / viande)

| # | Acteur | Action | Observation attendue | Capture |
| --- | --- | --- | --- | --- |
| 1 | Admin | Toggle ingrédient indisponible | Toast OK | `P5__X1_01__admin__ingredient_off.png` |
| 2 | POS | Ouvrir wizard article affecté | Option grisée / erreur à la validation | `P5__X1_02__pos__wizard_block.png` |
| 3 | Kiosk | Même produit | même blocage | `P5__X1_03__kiosk__wizard_block.png` |
| 4 | KDS | Ligne existante grisée / badge | Cohérent | `P5__X1_04__kds__line_rupture.png` |
| 5 | Admin | Rétablir ingrédient | tout re-devient disponible | `P5__X1_05__admin__ingredient_on.png` |

### Scénario X2 — Rupture **stock variation** (taille L)

| # | Acteur | Action | Observation | Capture |
| --- | --- | --- | --- | --- |
| 1 | Admin | `L` stock 0 | persisté | `P5__X2_01__admin__var_L_zero.png` |
| 2 | POS | Choisir L | impossible ou erreur claire | `P5__X2_02__pos__L_unavailable.png` |
| 3 | Kiosk | Choisir L | idem | `P5__X2_03__kiosk__L_unavailable.png` |

### Scénario X3 — Rupture **article entier**

| # | Acteur | Action | Observation | Capture |
| --- | --- | --- | --- | --- |
| 1 | Admin | Article off | liste | `P5__X3_01__admin__item_off.png` |
| 2 | POS | Tuile | grisée | `P5__X3_02__pos__tile_off.png` |
| 3 | Kiosk | Carte | absente ou grisée | `P5__X3_03__kiosk__tile_off.png` |

### Scénario X4 — Commande croisée + cuisine + paiement

| # | Acteur | Action | Observation | Capture |
| --- | --- | --- | --- | --- |
| 1 | POS | Commande cash complète | `order` créée | `P5__X4_01__pos__order_done.png` |
| 2 | KDS | Ligne nouvelle | visible | `P5__X4_02__kds__new_line.png` |
| 3 | KDS | Passe statut READY | — | `P5__X4_03__kds__ready.png` |
| 4 | OSS | Rafraîchir | statut READY | `P5__X4_04__oss__ready.png` |
| 5 | (opt) | Ticket PDF/HTML | contenu minimal | `P5__X4_05__pos__ticket.png` |

### Scénario X5 — Commande **Kiosk** puis réaction **POS** (file comptoir)

| # | Acteur | Action | Observation | Capture |
| --- | --- | --- | --- | --- |
| 1 | Kiosk | Commande comptoir | numéro file | `P5__X5_01__kiosk__queue.png` |
| 2 | POS | Liste kiosk cash | nouvelle entrée / notification | `P5__X5_02__pos__kiosk_list.png` |

### Scénario X6 — Reconnexion WebSocket (perte réseau)

| # | Acteur | Action | Observation | Capture |
| --- | --- | --- | --- | --- |
| 1 | Infra | Couper WS / bloquer host pusher | bannière / polling | `P5__X6_01__pos__ws_down.png` |
| 2 | Infra | Rétablir | menu refresh (heal récent) | `P5__X6_02__pos__ws_up_menu.png` |

## 3. Rapport `RAPPORT_P5.md` (consolidation finale)

1. **Synthèse exécutive** : GO / NO-GO prod pour la chaîne sync stock.
2. **Tableau anomalies** : toutes P0–P4 agrégées depuis P0–P4 + nouvelles transverses.
3. **Annexes** : zip ou chemin vers `reports/e2e-massive/<RUN_ID>/`.

## 4. Suite Playwright agrégée (future)

- Nouveau fichier recommandé : `tests/Playwright/e2e-massive/cross-surface-x1-x6.spec.js` (un describe par scénario).
- Réutiliser helpers de `pos-receives-kiosk-realtime.spec.js`.

---

*Plan P5 — création documentaire uniquement.*
