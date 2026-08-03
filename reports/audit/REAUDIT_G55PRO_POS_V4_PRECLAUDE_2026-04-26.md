# Re-audit pré-orchestrateur (équivalent GPT-5.5 Pro) — POS v4

**Date** : 2026-04-26  
**Cible** : `plans/PLAN_POS_V4_IMPL_MASTER_2026-04-26.md`  
**Appel API** : `npm run codex:complex -- POS_V4_G55_PRECLAUDE_001` avec `CODEX_MODEL_COMPLEX=gpt-5.5-pro` puis `gpt-5.5-high` — **HTTP 503** (service indisponible après reprises).  
**Statut** : ce document est un **re-audit rédigé en dépôt** pour respecter l’enchaînement *GPT-5.5 (avis) → Claude terminal (orchestration / plan d’exécution)*. À **re-générer** via l’API quand disponible, puis comparer (diff) pour validation.

---

## 1) Synthèse exécutive

Le plan maître est **exécutable** et **bien calibré** sur les risques POS SaaS (W0–W4, workstreams, gates, KPI, red-team, matrice tests). Les principales **tensions** à adresser avant le premier merge de code ne sont **pas** dans la structure des vagues, mais dans **l’alignement noms concrets (SFC FoodKing) ↔ zones abstraites (shell, catalogue)**, le **pilotage binaire** des deux risques H (binding + charte), et l’**absence de liens** vers les **chemins de fichiers** `resources/js/components/admin/pos/*.vue` dans le plan (à figer en Phase W0).

---

## 2) Lacunes & hypothèses implicites


| #   | Manque / hypothèse                                                                                                                    | Impact                                       |
| --- | ------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| L1  | Le plan parle de « SFC / zones logiques » sans lier chaque lot au **fichier `.vue` réel** (Receipt, Parked, Floorplan, Pos, Payment)  | Découpage PR et ownership flous              |
| L2  | La **JOIN** (ADR + BINDING_MAP) est correcte, mais le **format** de la binding map (table CSV / YAML / colonne risk) n’est pas imposé | Retard ou revue superficielle                |
| L3  | **KPI** « ≤ 45 s / ≤ 10 actions » — **baselines** actuelles FoodKing **non requises** pour déclarer régression                        | Faux négatifs en prod                        |
| L4  | **KDS** et **Kiosk** sont dans le rapports systémiques, pas dans les critères de sortie **W2–W3** du plan maître                      | Sous-vérification cross-surface              |
| L5  | **Fiscal / reçu / imprimante** : cités ailleurs dans le repo, pas **explicitement** dans les gates W3–W4 de ce plan                   | Regressions furtives post-design             |
| L6  | `.fk-pos-v4` proposé en §15 — pas encore dans **l’export ZIP** (hors arbre)                                                           | Décrochage design pack ↔ repo                |
| L7  | **Playwright / E2E** : plan maître les « mentionne » à travers gates ; **pas** de nom de spec obligatoire                             | Couverture caisse incomplète si oubli humain |


---

## 3) P0 bloquants (avant W1 / premier merge SFC)

1. **ADR charte** signé (primary unique + rôle de l’accent `#0084FF` si retenu).
2. `**BINDING_MAP_POS_V4.md`** complète, avec colonnes : SFC, binding, cible template v4, statut, test Vitest/PHPUnit de garde.
3. **Liste de fichiers autorisés** (chemins) + claim `agent-activity-log` pour le premier lot.
4. **Baseline KPI** (mesure une fois l’existant) ou décision explicite « baseline = à définir post-W1 ».
5. **Scope CSS** : règle écrite namespace + ce qui constitue une **violation** (ex. `fk-dark` en dehors de `.fk-pos-v4`).

---

## 4) Ce qu’on ne délègue **pas** à l’exclusivité d’un modèle (humain requis)

- **Produit** : choix couleur, périmètre dark, parcours critique caisse.  
- **Sécurité / données** : toute question `branch_id` si ambiguïté UI.  
- **Gate** zone **frozen** (Payment) : relecture humaine ou audit terminal prévus par `run-cycle.md`.  
- **Tranchage** « template-only » si un dev « glisse » une logique dans le `<script>` : **rejet de PR** sans négociation.

---

## 5) Dix recommandations de resserrage (exécution)

1. **Lier** chaque workstream (A–D) à des **dossiers** `resources/js/...` dans `PLAN` W0.
2. **Geler** l’ordre SFC : Receipt → Parked → Floorplan → **Pos** → **Payment** (comme §15) ; tronçonner chaque SFC en **sous-PRs** thème (template / style) si le diff dépasse un seuil d’équipe.
3. **Exiger** une **capture** avant/après** pour G4 (totaux) et G5 (paiement) — preuve de non-régression visuelle.
4. **Ajouter** 2 scénarios **Vitest** « flux long » (panier → paiement simulé) si absents, **sans** mocker le prix.
5. **Branch banner** : critère d’acceptation explicite en G2 (texte + test si existant).
6. **Chrono** W2 : throttle CPU 4× sur grille catalogue (comme proposé §15) — documenter l’**outil** (Chrome devtools / flag).
7. **Re-sync** hebdo : 15 min orchestrateur, relevé des **gates** ouverts.
8. **Dette** : fichier `docs/design/DEBT_POS_V4.md` append-only pour toute entorse temporaire.
9. **Re-audit G55** : rejouer `codex:complex` quand l’API re-vit, **diff** avec ce rapport.
10. **Claude terminal** : produit le `PLAN_…_EXEC_FINAL` avec traçabilité `AUDIT_CHANNEL` + `REAUDIT_G55_…` en prérequis.

---

## 6) Ligne de passage (ready for **Claude** orchestration)

**GO** : ce plan maître + ce re-audit sont **suffisants** pour que **Claude (terminal)** rédige le plan d’implémentation opérationnel final (`PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md`) à condition d’y intégrer : ordre SFC, JOIN gates, exigences P0, et **ligne de fuite** si API/scope dérape (`ESCALATION`).

**CONDITION** : si **Codex 503** persiste, considérer ce document comme **proxy G55** jusqu’à preuve d’équivalence API.

---

*Mission: `POS_V4_G55_PRECLAUDE_001` — preuve d’échec API: journal shell du 2026-04-24 (HTTP 503).*