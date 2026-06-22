# KDS — corrections « intelligence & exploitation » (post-audit)

**Date :** 2026-04-22  
**Périmètre :** alignement sur l’audit KDS/POS (bump local, compte central, plafond 50, divergence items board, conflits 409, lisibilité des notes cuisine).  
**Preuves automatisées :** `npx vitest run tests/js/kdsLineSemantics.spec.js` (5/5) ; `bash scripts/check-invariants.sh` (6/6).  
**Relecture terminal Claude :** `bash scripts/foodking-claude-orchestrate.sh audit "…post-corrections KDS…"` (exit 0) — extraits intégrés ci-dessous.

---

## 1. Problèmes repérés (avant / incohérences)

| Thème | Ce qui n’allait pas (ou manquait) | Impact |
|--------|-----------------------------------|--------|
| Compte **branch_id = 0** (central) | Pas d’abonnement Echo : rafraîchissement lent, risque de croire que l’écran est « figé » | Erreur d’interprétation en cuisine |
| **Plafond 50** (`KitchenDisplaySystemOrderService` → `limit(50)`) | Avertissement seulement « proche » (45+) ; à **50** l’écran est plein sans message explicite sur d’éventuelles commandes **non listées** | Perte de tickets en pic de service |
| **Bump / Prêt** en `localStorage` | Non synchronisé multi-postes (choix d’architecture connu) | Deux KDS voient des états « prêt » différents |
| **Items board** vs file principale | Périmètre métier différent (ACCEPT + PREPARING vs statuts plus larges) | « Bug perçu » si non expliqué |
| Conflit **HTTP 409** sur changement de statut | Message d’erreur générique, pas d’alignement UX avec « ordre modifié ailleurs » | Friction + double action |
| **Notes libres** (sans / allergènes / hold) | Tout au même style visuel | Moins de priorisation opérationnelle |
| **Ticket d’impression** | Instructions en gris uniforme | Même priorisation que l’écran manquante |

Aucun changement de logique de **prix** / **OrderService** côté front (invariants POS inchangés).

---

## 2. Correctifs appliqués (fichiers)

### 2.1 `resources/js/helpers/kdsLineSemantics.js`

- Classification **à trois niveaux** : `kds-instruction--allergen` (mots type allergie / intolérance) **avant** `kds-instruction--exclusion` (sans / hold / no / without…), sinon `kds-instruction--note`.
- `isLikelyExclusionOrHoldInstruction` reste l’**union** allergène ∪ exclusion (tests et usage « flag » inchangé sémantiquement).

### 2.2 `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`

- Bannières : compte **central** ; **45–49** = avertissement approche plafond ; **exactement 50** = bannière **rouge** (liste pleine côté serveur, d’autres actives possibles).
- Bump : rappel **périmètre navigateur** + bouton « Ne plus afficher » (`kds.hide_bump_info`).
- Sous-titre pédagogique **items board** (périmètre des articles affichés).
- Toutes les lignes d’**instruction** (board gauche + colonnes commandes) : classes sémantiques.
- **409** sur `orderStatus` : `message.kds_status_conflict` + `_debouncedRefresh()`.
- **Styles** : `.kds-instruction--allergen` / `--exclusion` / `--note` ; bannières `.kds-hint-banner--*`.
- **Impression** : styles inline différenciés (allergène / exclusion / neutre) alignés sur `kdsInstructionVisualClass`.

### 2.3 i18n

- `fr.json`, `en.json`, `ar.json` : clés KDS existantes + **`label.kds_order_list_full_warning`**.
- `message.kds_status_conflict` déjà présent (FR/EN/AR).

### 2.4 Tests

- `tests/js/kdsLineSemantics.spec.js` : attentes mises à jour (allergen / exclusion / note).

---

## 3. Synthèse de l’audit terminal Claude (points retenus)

- **I18n KDS** : cohérent sur les clés vues ; interpolation `{n}` sur les bandeaux de charge.
- **Sémantique** : suggestion de **différencier** allergènes et exclusions — **prise en compte** (classes et styles distincts + ticket).
- **Plafond 50** : signal explicite quand la liste est **pleine** — **prise en compte** (bannière dédiée à `orders.length === 50`).
- **409** : flux refresh + message dédié validé ; limite : pas d’indication « qui » a modifié (acceptable v1).
- **Bump multi-postes** : reste un **sujet produit / architecture** (serveur, verrou d’exploitation, ou bannière non masquable) — **non résolu par du seul front** ; documenté ici comme **gate humaine** si la politique d’exploitation l’exige.
- **Tests E2E** complet bump → PREPARED : piste d’amélioration future (hors scope de ce lot).

---

## 4. Risques résiduels (explicites)

1. **Heuristique texte** : faux positifs / négatifs possibles sur des formulations rares ; ce n’est **pas** une étiquette allergène légale — l’API structurée reste la référence si exposée ailleurs.
2. **50 commandes** : on ne connaît pas le **total** réel sans évolution API (`has_more`, `total`, etc.) ; l’UX dit « d’autres actives possibles » quand la liste est **pleine**.
3. **Bump** : `localStorage` par navigateur — documenté en UI ; synchronisation serveur = évolution produit.

---

## 5. Fermeture

- **Technique** : tests unitaires ciblés + garde invariants OK.  
- **Design d’exploitation** : transparence (central, plafond, bump local, périmètre items board, conflit 409), emphase visuelle (allergie vs exclusion).

Pour aller plus loin (backlog) : API `total` / `has_more` au-delà de 50 ; bump **ou** règle d’exploitation multi-KDS ; scénario E2E bump complet.
