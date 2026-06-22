# Plan — rendre l’export POS (4) v4 **exploitable** après adaptation FoodKing

**TASK_ID** : `POS_V4_EXPORT_READINESS_2026-04-25`  
**Date** : 2026-04-25  
**PRIMARY_MODEL (cycle d’exécution future)** : à déclarer par cycle — **routine** `cursor-composer` / **complexe** `codex-terminal` selon sous-lot ; ce document est **SSOT** pour la **roadmap** avant premier merge.

## Sources d’audit fusionnées (2026-04-25)

| Source | Rôle | Fichier / commande |
|--------|------|-------------------|
| Rapport design v1 | Base factuelle + checklist P0 | `reports/audit/AUDIT_POS_V4_EXPORT_DESIGN_2026-04-24.md` |
| Second avis API (P0 / merge checklist) | Garde-fous invariants + opérations caisse | `missions/POS_V4_DESIGN_AUDIT_001/output_codex.json` (`notes`, `risks`) |
| Audit du rapport (terminal Claude) | Gaps, ADR, bindings, perfs, tests | Voir **§3** ci-dessous (reprise synthétique) |
| Mission `POS_V4_READINESS_PLAN_001` | *Note* : `npm run codex:complex` **n’a pas** produit de roadmap (runner limité à l’**implémentation** — `output_codex.json` tracé) | `missions/POS_V4_READINESS_PLAN_001/output_codex.json` |

**Graphiti** : non bloquant. Après tranchage couleur : épisode `12_decisions_log.jsonl` + option `add_memory` si ADR consommé.

---

## SUBSYSTEMS_TOUCHED (par phase — pas tout en un cycle)

| Phase | Zone | R/W | `branch_id` | Dispatch / events |
|-------|------|-----|------------|-------------------|
| 0 | `docs/decisions/`, `docs/design/` (à créer) | Write | N/A | Non |
| 1 | Pack ZIP + `EXPORT_v4_README`, `preview.html` (hors arbre) | R/W côté design | N/A | Non |
| 2 | `resources/js/components/admin/pos/*.vue` (uniquement template + `pos-v4.css` / classes) | Write | N/A côté UI* | Non si script gelé |
| 3 | Tests PHPUnit + Vitest listés + build | R/W | N/A** | N/A** |

`*` Toute requête API ou filtre de données reste identique (pas de logique `branch_id` nouvelle côté UI).  
`**` Vérifier les tests d’isolation s’ils existent pour les écrans touchés.

## SUBSYSTEMS_OFF_LIMITS

- `OrderService` / `FrontendOrderService` **sauf** revue de symétrie explicite si le design impliquerait un changement de flux (défaut : **hors scope** = UI only).
- Fichiers **frozen** sans gate — consulter `docs/gates/` + `human-gates.mdc` avant toute retouche Payment critique.
- Refonte globale admin (sidebar, thème) hors lot POS si non couverte par l’ADR dark.

## INVARIANTS_AT_RISK (merge UI)

- **Pricing SSOT** : aucun total / taxe / remise calculé côté Vue — affichage des champs serveur uniquement.
- **OrderStatus** : pas de libellé ou transition inventée par le design.
- **branch_id** : pas de requête élargie ; composants inchangés côté API.
- **Dispatch after commit** : N/A si pas de back ; sinon inchangé.

## GATE_CONDITIONS

- **Produit** : choix **primaire** unique `#FF006B` vs `#0084FF` (et accents) **avant** merge large.
- **Docs** : ADR signé / daté (owner produit) pour la charte.
- **Vérification** : inventaire bindings **complet** ou équivalent prouvable (table + revue) avant remplacement de template sur un SFC critique (Payment, Pos).
- **Tests** : noms de tests alignés sur le dépôt réel (`rg` / liste CI) — pas de checklist fantôme.
- **Changement d’écran critique** (flux paiement) : considérer **QA manuelle** ou gate `human-verification` si le plan d’E2E ne couvre pas.

## Definition of “exploitable”

L’export est **exploitable** quand toutes les conditions suivantes sont vraies :

1. **Charte** : une seule source de vérité token/couleur pour primary + aperçu (`preview.html` / Storybook / page statique) alignée avec l’ADR.
2. **Spécification de merge** : `EXPORT_v4_README` mis à jour avec noms de tests **réels**, chemins, et “Definition of done” par SFC.
3. **Bindings** : document `BINDING_MAP_POS_V4.md` (ou équivalent) = zéro interaction critique orpheline.
4. **Hors piège métier** : aucun nouvel état de commande / paiement implicite non validé backend.
5. **Qualité d’usage** : critères de **vitesse caisse** (clics, visibilité total, latence) explicités et testés sur cible matérielle (au moins un passage manuel sur POS réel ou résolution cible).
6. **A11y min** : cibles 44px, `focus-visible`, contrastes vérifiés sur thème retenu.

---

## 3. Synthèse : audit **du** rapport (Claude Code, 2026-04-25)

*Points forts du rapport* : conflit couleur P0, gel `<script>`, §7 actionnable, séparation outil canvas vs runtime.

*Manques à combler* :

- **Inventaire bindings** outillé (table, pas seulement une mention).
- **DoD par SFC** et critères de “merge acceptable” file par file.
- **Règles portage JSX → Vue** (interdits : état React, `className` non migré, etc.).
- **Owner / scope** dark : POS seul vs admin — éviter contamination CSS.
- **Tests** : vérifier existence réelle des classes PHPUnit/JS citées.
- **ADR** : chemin, format, auteur, lien Graphiti/épisodes si décision durable.
- **Performance** : le rapport ne traite pas assez jank, toggle `fk-dark`, grille catalogue.

*Nuances risques* :

- **Marque** : mélange dans `preview.html` **invalide** toute QA visuelle — gate bloquante *partielle* aussi.
- **Bindings** : risque de casse *silencieuse* (ref, `@click` déplacé) — idéal : vérification par diff/structure (manuelle ou outil) avant merge.
- **Caisse** : 3 colonnes + dark = charge CSS / re-renders — ajouter **critères** (fps, pas de re-layout sur chaque add item) dans le test plan.

---

## 4. Phases d’exécution (ordre recommandé)

### Phase 0 — Décision & documentation (P0) — *aucun SFC produit modifié*

| Étape | Livrable | Critère d’acceptation |
|-------|----------|------------------------|
| 0.1 | ADR primaire + accents (`docs/decisions/ADR-001-primary-color.md` ou nom projet) | PO / produit tranche ; un seul primary ; aperçu cohérent |
| 0.2 | Mettre à jour le ZIP / README : section “Source of truth tokens” + lien ADR (copie ou référence repo) | README ne contredit plus l’ADR |
| 0.3 | Vérifier `preview.html` : une seule story de couleur pour le bouton / lien primaire par contexte (POS dark) | Capture ou checklist QA signée |

### Phase 1 — Faisabilité technique (P0) — *repo + zip alignés*

| Étape | Livrable | Critère d’acceptation |
|-------|----------|------------------------|
| 1.1 | `docs/design/BINDING_MAP_POS_V4.md` : Pos, Payment, Floorplan, Parked, Receipt | Chaque binding critique mappé statut (OK / N/A / à risque) |
| 1.2 | Liste tests **exacte** : `rg` ou `phpunit --list-tests` + Vitest | Table dans README ou `TEST_PLAN_POS_V4.md` |
| 1.3 | Décision modals Payment : sous-fichiers vs inline (owner fichier) | Revue 1 page max dans ADR-002 ou section |

### Phase 2 — Stabilisation du **pack** (P1) — *toujours hors branche `main` si préférée*

| Étape | Livrable | Critère d’acceptation |
|-------|----------|------------------------|
| 2.1 | Remplacer emojis / mocks par légendes “binding API” dans README | Aucune ambiguïté “prix = mock” |
| 2.2 | `pos-v4.css` (ou équivalent) : tokens **dérivés** de l’ADR (alias `--fk-primary` → résolu) | Build statique / preview OK |
| 2.3 | “États” : liste des écrans à maquetter (vide, erreur, payé, parké, KDS envoyé) | Checklist cochable |

### Phase 3 — Intégration incrémentale FoodKing (P1–P2) — *un SFC par PR ou par lot*

| Étape | Livrable | Critère d’acceptation |
|-------|----------|------------------------|
| 3.1 | **Claim** : `agent-activity-log.sh start` sur fichiers listés (cross-agent) | Pas de collision |
| 3.2 | SFC 1 (ex. Receipt ou plus simple) : template seulement + classes | Tests + visuel + pas de logique JS nouvelle |
| 3.3 | Répéter pour Pos, Parked, Floorplan, **Payment** en dernier (surface max) | `SYMMETRY_NOTE` si OrderService jamais touché : N/A |
| 3.4 | `npm run build` + suite ciblée + invariants | Comme plan cycle standard |

### Phase 4 — Durcissement (P2)

- Tests visuels / Storybook (si existant) ou captures Playwright **si** le plan d’un cycle l’exige.
- Revoir **SYMMETRY** : si `FrontendOrderService` ou POS API touché indirectement — revue de paire.
- Métriques perfs (option) : throttling CPU dans Chrome pour stress grille.

---

## 5. Reprise des **P0** issus de Codex (design pack — rappel)

*(Condensé de `missions/POS_V4_DESIGN_AUDIT_001/output_codex.json`.)*

- Ne pas merger sans **passage opérations** : commande active, lignes, modificateurs, taxes, paiement, parké, plan de table, KDS, branche.
- **Bloquer** toute logique métier empruntée aux mocks (prix, statuts, transitions).
- Protéger le **happy path** caisse (add, qté, retirer, park, payer, envoi cuisine) en ≤ interactions qu’aujourd’hui ou justifier écart.
- Toute **nouvelle** transition ou API = revue backend **avant** PR UI.

**Merge checklist** (extraite) : template/style seulement ; chaque valeur d’écran mappée ; prix/totaux serveur ; statuts existants ; `branch_id` ; pas de dispatch UI nouveau ; clics caisse validés ; a11y ; couleurs ; régression / fixtures longues commandes.

---

## 6. Prochaine action `run-cycle`

1. Décider : ce **TASK_ID** = uniquement *préparation docs + ADR* (Phase 0–1) **ou** déjà SFC 1.  
2. Écrire `tasks/POS_V4_EXPORT_READINESS_2026-04-25.md` (intake) si besoin.  
3. Remplir `ACTIVE_CYCLE` + `REPORT_FILE` + traces `EXECUTE_DELEGATION` / `AUDIT` selon `run-cycle.md`.

## SYMMETRY_NOTE

N/A si aucun changement `OrderService` / `FrontendOrderService`. À réévaluer si le design impliquait des appels API ou champs d’ordre nouveaux.

## SCOPE_PRESSURE

*À remplir seulement en exécution si dérive.*

## ESCALATION

*Idem.*

## Audit Status

- [x] Plan rédigé — revue humaine / orchestrateur
- [ ] Exécution Phase 0–1
- [ ] Exécution Phase 2–3
- [ ] Audit terminal Claude post-merge lot

---

**Note technique** : pour un avis long **GPT-5.5** sur *ce* plan, utiliser le chat outil côté API ou un prompt hors `codex.runner` (implémentation-only) ; le contenu structuré ci-dessus reprend déjà le second avis Codex **P0/P1** de la mission `POS_V4_DESIGN_AUDIT_001` + l’audit secondaire Claude sur le markdown du rapport.
