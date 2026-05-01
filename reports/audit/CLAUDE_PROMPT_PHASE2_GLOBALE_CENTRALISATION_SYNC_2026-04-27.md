# Mission Claude — Phase 2 : Synchronisation globale, centralisation, intersections multi-surfaces

**Mode : orchestration / audit / plan** — *aucune* suppression de fichiers, *aucun* patch de code en cette passe. Toute exécution = cycles futurs `run-cycle` / exécuteur (Codex) sur **sous-ensembles allowlistés** après approbation humaine du plan.

**Effort requis** : **raisonnement maximal** (exploration transverses, contradictions, risques) — l’équipe attend une **cartographie massive**, une **décision par intersection critique**, et un **plan actionnable** (pas un résumé superficiel).

**Préalable produit (hypothèse de travail)** : la **Phase 1 borne / ultra audit** associée a produit le livrable attendu ; tu **cites** les conclusions pertinentes (chemins) sans les réécrire. Si des pièces Phase 1 manquent sur le disque, l’indiquer **sous 3 lignes** et documenter l’**écart** dans la matrice d’exigences.

---

## 0) Politique d’alimentation mémoire (obligatoire, début de session)

1. **Graphiti MCP** (si disponible) : appeler **au moins** `search_memory_facts` (requête naturelle : « synchronisation borne caisse, catalogue, stock, queue, outbox, branch ») avec `group_id` = **`foodking`**. En cas d’MCP indisponible : **une ligne** « Graphiti indisponible : activer `~/.cursor/mcp.json` » puis lire `memory/INDEX.md` + épisodes ciblés sous `memory/episodes/*.jsonl`.
2. Puis lire : `AGENTS.md` (au moins *Parcours obligatoire* + EXECUTE / mémoire), `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `docs/DEVICE_FLOW.md`, `docs/ORDER_FLOW.md`, `docs/BUSINESS_RULES.md` (extraits applicables), `docs/ARCHITECTURE.md` (si lié). Ne pas recharger toute l’histoire de chat : **dépôt = SSOT**.

---

## 1) Périmètre conceptuel (Phase 2) — qu’est-ce que « global » ici

Tu dois couvrir **d’un seul cadrage** (sans mélanger les gates) :

| Axe | Question centrale |
| --- | --- |
| **A — Identité de la donnée** | Où est la **vérité** (menu, catégories, prix, disponibilité, `branch_id`, commandes, file) — admin / API / cache / chaque appareil ? |
| **B — Intersections synchrones** | Borne ⟷ (POS + réaltime) ⟷ (KDS + statuts) ⟷ (OSS / file) ⟷ back-office (produits, stock, catégories) : **qui écrit**, **qui lit**, **délai**, **invalidation**. |
| **C — File & commande** | Commande kiosk → file caisse, numéro, outbox, événements : exigence de **visibilité unifiée** côté pilotage. |
| **D — Rupture / cohérence** | « Même entité modifiée à deux endroits » (ex. produit, catégorie, stock) : risque de **double source** ; où **centraliser l’intention** (typiquement admin / règles) sans violer invariants. |
| **E — Propreté de dépôt (planification seulement)** | Doublons, branches mortes, anciennes bomes `borne (Remix)/` vs Vue, bundles legacy, docs obsolètes : **jamais** imposer un `rm` de fichiers **potentiellement** primaires. |

Respecter **invariants** FoodKing : **prix = backend SSOT** ; `OrderStatus` **enum** ; `branch_id` = isolement business ; **dispatch after commit** ; `OrderService` / `FrontendOrderService` **symétrie** si l’on touche les commandes ; **frozen zones** / gates humains tels qu’`human-gates.mdc`.

---

## 2) Matrice d’intersections (livraison **obligatoire**)

Construire un tableau (≥ **12** lignes de concepts **distincts** — pas de doublons) :

- Colonnes (à adapter) : **Surface (Borne, POS, KDS, OSS, Admin/Dashboard, API)** | **Type de donnée** | **Chemin d’écriture** (fichiers, routes) | **Chemin de lecture** | **Mécanisme de sync** (HTTP, jobs, events, Echo, outbox) | **Latence / risque** | **Test ou sentinelle existant** | **Gaps** | **Niveau critique (P0/P1/P2)**.

Toute case « inconnuue » = **EXPLICITE** `à vérifier` + proposition de **fichier / test** à ouvrir — pas d’invention.

---

## 3) Analyse des doublons, legacy et dette (lecture seule, sortie = plan)

- Inventorier les **candidats doublon** (ex. : deux wizards, deux DSKiosk, `kiosk.js` générés, dossiers `borne (Remix)/` vs `resources/js/.../kiosk/`) : pour chacun, **P1 quelle est la brique *canon* ** (preuve : import dans routes, `webpack`, `mix`, `package.json`, `README`).
- Règle **gouvernance exécution future (Codex)** :  
  - **Interdit** de supprimer directement un fichier ambigu.  
  - **Politic par défaut** : **déplacer** vers un dossier **`archive/phase2-dedup-YYYY-MM-DD/`** (ou `docs/archive/…`) + **`MANIFESTE.md`** (raison, date, SHA, chemins d’origine, qui a validé) **+** conserver l’**historique git** (déplacer, commit explicite).  
  - Si doute = **0 déplacement** ; ouvrir **gate** humain ou issue « DECISION: duplicate resolution ».  
- Ton livrable ici = **liste** classée **DANGER HAUT** / moyen / bas + **recommandation** (garder, archiver après validation, conserver + commentaire).

---

## 4) Ultra plan (structure **obligatoire**)

Le plan doit être **surgé** pour **Codex** et **humain** :

### 4.1 Phases (A, B, C, …) avec contrainte de clôture

- Chaque phase : **objectif d’ingénierie**, **non parallélisable** si conflit de vérité ou de schéma, **sortie binaire** (ex. : « 0 test failed sur le filtre X » ; « un seul SSOT documenté pour Y »).  
- **Gates** : quels blocs requièrent avis **humain** (migration, auth, `branch` isolation, frozen).

### 4.2 Sous-ensemble **critique** réservé à l’alimentation Codex

Pour chaque tâche **P0 d’intersection** (synchronisation réellement dangereuse) :

- **`TASK_ID` proposé** (hors `CV1-MXX-…` si `MASTERPLAY_FROZEN=1` : respecter le repo) ; **allowlist fichiers** stricte ; interdiction de **scope expansion**.  
- **Double audit requis** sur le livrable :  
  1) **self-audit GPT** (`reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md`) ;  
  2) **second passage** (Claude relecture ciblée **ou** règle projet `GPT_FINAL_AUDIT` / `AUDIT_VERDICT` — indiquer la chaîne attendue selon `AGENTS.md`).  
- **Point d’intersection** nommé (ex. : « outbox + queue_number + POS real-time ») et **ce qui prouve** l’absence de dérive (tests + critère binaire).  
- **Ligne `EXECUTE_DELEGATION: codex-extension`** à tracer pour chaque exécution, dans `reports/post_execute_latest.log` (réf. `AGENTS.md`).

### 4.3 Tâches dites « nettoyage global » (séquencées, non destructrices)

- Lot distinct : `CV2-CLEAN-ARCHIVE-DEDUP-*` — uniquement **après** manifeste + relecture, **déplacements** vers `archive/…` avec tests verts sur les chemins **non archivés**. Aucun « grand rm ».

### 4.4 Ce qu’on attend pour que la Phase 2 **serve vraiment** l’orchestration produit (Dashboard / pilotage, sans implémenter ici le Dashboard entier)

- 1 paragraphe : **où** vivra la **décision** « une modification catalogue/stock pousse-t-elle toutes les surfaces » (rôle admin vs règles) ; **3 risques** si mauvaise coupure ; **3 preuves** de succès (hors bavardage).

---

## 5) Livrables (sortie Claude) — check-list

1. **Résumé exécutif** (≤ 15 lignes) : **verdict** prêt / bloqué / en attente.  
2. **Matrice d’intersections** (§2) complète.  
3. **Audit des doublons** + politique `archive` + `MANIFESTE` (§3).  
4. **Ultra plan** (§4) avec tâches Codex critiques et **double audit** sur chacune.  
5. **Liste** des **fichiers / sujets** où tu n’as **pas** eu assez d’évidence disque (7 items max) — *questions ouvertes*.  
6. **Annexe** : comment brancher l’orchestrateur (humain) **après** ce document : ordre de lecture, ordre d’appel Codex, re-run tests globaux.

**Verdict final une ligne** : `PHASE2_STRATEGIE` = `PRÊT_POUR_DÉCOUPAGE_EXÉCUTION` | `BLOQUÉ_JUSQU_À_…`.

**Style** : nul adoucissement factuel, pas de promesse de « tout est synchronisé » sans preuves. Citer les **chemins** dès qu’on affirme l’existant.

---

*Document à envoyer tel quel à Claude. Après sa réponse : validation humaine → tâches Codex une par une avec le présent texte + plan Claude en annexe, toujours allowlist + double audit sur intersections P0.*  
*Fichier : 2026-04-27*
