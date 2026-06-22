# Plan — 10 passes POS (design + front + back + orchestration)

**Date** : 2026-04-24  
**Rôle** : cerveau d’orchestration (Claude) — plan **validé ici** pour enchaînement exécution **étape par étape** (ta prochaine demande = attaquer l’étape 1, puis 2, …, puis **audit global** de clôture).  
**Skill design** : `.agents/skills/frontend-design/SKILL.md` + `.cursor/skills/foodking-vue-best-practices/SKILL.md` (obligatoire dès qu’on touche le front POS).  
**Mémoire** : `memory/episodes/07_pos_features.jsonl` + (avant exécution) `search_memory_facts` / `search_memory_nodes` côté Graphiti (`group_id=foodking`) — **ne pas** recoller tout le dépôt dans le prompt.  
**Terminal (abonnement utile)** : `bash scripts/after-execute-memory.sh` puis `bash scripts/foodking-claude-orchestrate.sh context` puis, **par passe livrée**, `audit-brief` (un seul audit ciblé = efficacité crédit / qualité, pas 10 audits vides). Voir `docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md`.

---

## 0. Auto-validation (intelligence de plan)

Ce découpage est **volontairement 10 blocs** (ni 8 ni 15) pour :

- isoler le **design system** avant le code (évite l’“AI slop” : direction esthétique explicite + contraintes opérateur).
- regrouper ce qui **dépend du même rythme métier** (ex. reçu + print + fisc) sans fusionner l’inconciliable (ex. ne pas mélanger floorplan et snapshot NF525).
- respecter **zones gelées** (`PaymentComponent.vue` et cœur pricing/symétrie : pas de bricolage en douce).
- prévoir une **clôture** (étape 10) : audit d’intégration, a11y, parité, et passage terminal **une fois** sur l’état final.

**Critères d’arrêt** par étape : critères de validation + sentinels listés (Vitest/PHPUnit/Playwright selon gravité), sans scope creep.

---

## 1. Direction visuelle & charte (skill frontend-design)

| Champ | Décision proposée |
|--------|-------------------|
| **Purpose** | Caisse haute fréquence : lire vite, moins d’erreurs, fatigue réduite (cuisine, rush, salle). |
| **Tone** (bold mais adapté) | **Industriel / haute lisibilité** (pas “landing purple gradient”) : contrastes forts, une **display** distinctive pour totaux/alertes, une **body** lisible à 60 cm ; micro-mouvement **limité** aux changements d’état (busy, 86, erreur paiement). *Éviter* Inter / Roboto par défaut ; s’appuyer sur `frontend-design` pour *une* identité (ex. géométrique, ou editorial sobre) **cohérente** avec le thème existant. |
| **A11y** | WCAG AA cible sur zones focus (T18 historique) : ordre de tab, contrastes, pas de seulement couleur pour l’état. |
| **Livrable** | Document court : tokens (couleurs, spacing, type scale), **états** (idle / loading / 86 / error / success), règles d’icône + F-keys si présents. Aucun patch back dans cette passe. |

**Délégation** : en général `foodking-routine-implementer` (doc + spec CSS variables) + relecture orchestrateur.  
**Graphiti (avant)** : requête « POS UI layout header cart footer a11y ».  
**Terminal (après)** : optionnel, `audit-brief` si gros choix d’AD visuelle à verrouiller.

---

## 2. Enveloppe d’app : 3 zones + mode opérateur (shell UI)

| Front | `PosComponent.vue` + layout (header / main / footer), modes sur place / emporte, en-têtes (branche, opérateur, total panier). |
|--------|------------------------------------------------------------------|
| **Back** | Alignement `branch_id` / auth (déjà — pas de contournement idempotence). Vérifs routes API déjà en place. |
| **Risque** | Ne pas casser le routage F-keys / v-print / handlers existants. |
| **Valider** | Fumée manuelle + Vitest ciblés s’il existe sur shell ; pas de changement de frozen. |

**Délégation** : routine possible si surtout structure + styles ; **complexe** si refactor store ou navigation.  
**Ingest mémoire** (si Décision) : `07_pos_features` ou `12_decisions` une ligne.

---

## 3. Parcours catalogue : recherche, grilles, fiche item / attributs

| Front | `ItemComponent` + grilles, recherche, barcode, modales de variations, extras — **cohérent** avec charte §1. |
| **Back** | Lecture menu / dispo via ressources existantes (pas de prix côté front, SSOT). |
| **Risque** | Parité POS↔Kiosk sur compteurs de variations, min/max, 86. |
| **Valider** | `posKioskVariationParity` (si en place), scénarios multi-viande. |

**Délégation** : `foodking-complex-implementer` dès qu’on touche logique panier/attributs.  
**Graphiti** : « item variation min_select max_select POS Kiosk ».

---

## 4. Panier : clarté, totaux, multi-lignes, **performance (K5)**

| Front | Cart : réactivité, skeleton / optimistic patterns déjà en partie (`SkeletonGrid`), listes denses, erreur réseau. |
| **Back** | Totaux côté serveur ; i18n cents ; `composition_snapshot` inchangé côté invariants. |
| **Risque** | Recompute lourd (régression T04) ; conserver mémoisation / budgets. |
| **Valider** | `posPerformance` / scénarios timing si présents, régression 0 sur Feature Order/POS. |

**Délégation** : complexe si refonte store ; sinon routine perf ciblée.

---

## 5. Park / hold / recall / discard (Pause commande)

| Front | `ParkedOrdersComponent`, drawer, messages warnings (variations indisponibles). |
| **Back** | `PosParkedOrderService` — recall / cross-branch, purge. |
| **Risque** | G-3 (disponibilité variations) — tests dédiés. |
| **Valider** | `PosParked*` Feature + Vitest sur flows recall. |

**Délégation** : **complexe** (multi-tenant + état) si au-delà du polish UI.

---

## 6. Paiement (multi-tender) — intégration autour du **noyau gelé**

| Front | **Gel** : ne pas “refondre from scratch” `PaymentComponent.vue`. Améliorer périphérie (layout parent, toasts, états, accessibilité bouton encaissement) en respectant invariants. |
| **Back** | `idempotency`, multi-tender sum = total — inchangé sauf **gate** explicite. |
| **Risque** | Symétrie `FrontendOrderService` / Kiosk. |
| **Valider** | `MultiTenderTest`, pas de `ORDER_FLOW` enfreint. |

**Délégation** : routine pour UI périphérique ; **jamais** gros changement cœur sans plan + `foodking-complex`.

---

## 7. Reçu client, impression, DUPLICATA, NF525 (G-1 / G-2)

| Front | `ReceiptComponent` + `posReceiptBuilder` + `ReceiptDuplicataMarker` — cohérence d’affichage quantités, variations, extras. |
| **Back** | `PosReceiptPrintController` audit, chaîne NF525, reprints. |
| **Risque** | Snapshot immutabilité, pas de fuite d’`undefined` en reprint. |
| **Valider** | Vitest `posReceiptBuilder`, Feature receipt / fiscal, sentinels cross-vague. |

**Délégation** : **complexe** dès qu’on touche l’enchaînement print + audit.  
**Graphiti** : « composition_snapshot receipt print NF525 ».

---

## 8. Remboursements / retours (hors remise ligne si gate T09)

| **Front** | Recherche commande, line-item refund UX, clarté des motifs, états. |
| **Back** | `RefundService` / payments négatifs / `OrderStatusChanged` / KDS notifié. |
| **Risque** | Gate NF525 G14-B si règles compta (reste en backlog explicite si non débloqué). |
| **Valider** | `PartialRefund` / scénarios Feature existants. |

**Délégation** : **complexe** (argent + stock + audit).  
**Ne pas** étendre le scope “discount ligne” ici si gate non signé (réf. `HUMAN_GATES`).

---

## 9. Plan de salle (floorplan) : assignation, transfert, anti-race

| Front | `FloorplanComponent` (inFlight), états de table, transferts, feedback erreur. |
| **Back** | `DiningTable` + guards `order_id` / `branch_id` (P1 transverse déjà). |
| **Risque** | Double request (pattern inFlight) ; cohérence `dining_table_id` sur l’ordre. |
| **Valider** | Feature floorplan, Vitest ciblés, pas de 409 fantôme. |

**Délégation** : **complexe** dès qu’on touche modèle + concurrence.  
**Graphiti** : « floorplan table transfer inFlight KDS dining_table_id ».

---

## 10. Finitions & audit global (UI “mal pimenter”, a11y, clôture)

| Contenu | **(a)** Repasser toutes les étapes 2–9 : cohérence visuelle (spacing, typo, états d’erreur), **(b)** a11y ciblée (T18), **(c)** i18n / clés, **(d)** non-régression : Vitest + PHPUnit Périmètre POS+Pricing+Order, **(e)** option Playwright (si pipeline décidé) sur flux “cash + ticket”. |
|--------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Rôle orchestre** | *Un* passage **Claude** : **audit d’intégration** (lire `MEGA_CHECKLIST` items POS restants, `RUN_*.md` sans `EXECUTE_DELEGATION` = dette) — **+** `bash scripts/foodking-claude-orchestrate.sh context` + **`audit-brief`**. **Graphiti** : requêtes ciblées “what changed POS last” plutôt que full chat. |
| **Sortie** | Rapport unique `reports/execution/RUN_P_POS_10PASSES_CLOTURE_<DATE>.md` avec liste de correctifs (P0/P1) et rappel gates ouverts. |

**Délégation** : `foodking-routine` pour copy/petits fix ; `foodking-complex` pour toute dette transverse.  
**Sub-agents** : l’orchestrateur lance `explore` *si* gros doute d’où se situe le bug, pas par défaut.

---

## Schéma de flux (10 blocs)

```mermaid
flowchart LR
  S1[1. Charte design] --> S2[2. Enveloppe 3 zones]
  S2 --> S3[3. Catalogue + item]
  S3 --> S4[4. Panier perf K5]
  S4 --> S5[5. Park / recall]
  S5 --> S6[6. Paiement gelé]
  S6 --> S7[7. Reçu + print NF525]
  S7 --> S8[8. Rembours.]
  S8 --> S9[9. Floorplan]
  S9 --> S10[10. Audit global + doc]
```

---

## Table exécution (raccourci)

| # | Nom | Design (skill) | Front / Back (focus) | Orchestration |
|---|-----|----------------|----------------------|--------------|
| 1 | Charte + tokens + états | **Oui** (bold industriel) | Fichier spec + variables | Graphiti + doc |
| 2 | Shell 3 zones | Héritage style §1 | `PosComponent` + layout / auth | Sub-agent + smoke |
| 3 | Catalogue / item | Cohérence | Item / variations / barcode | **Complexe** si logique |
| 4 | Panier | Densité lisible | Store + i18n + K5 | Perf + tests |
| 5 | Park / recall | Warnings clairs | Parked + service | **Complexe** max |
| 6 | Paiement | Cadrage autour noyau | Parent UI / pas gelé seul | Gate frozen |
| 7 | Reçu / print | Ligne ticket lisible | Receipt + builder + back | **Complexe** + fiscal |
| 8 | Remboursement | Moins d’ambiguïté | UI + Refund + audit | Gate compta ? |
| 9 | Floorplan | États table forts | Floor + tables + inFlight | Concurrency |
| 10 | Clôture + audit | Polish global | Transversal + rapport | `audit-brief` **1×** |

---

## Rappel tokens (anti-gaspi, anti-abus inutile)

1. Avant grosse exécution d’une étape : **Graphiti** 1 requête domaine, pas l’historique de chat.  
2. Après : **`after-execute-memory`**, ligne JSONL si Décision durable, **`context`** puis **un** `audit-brief` *par grosse livraison* (les 10 = au plus ~10 appels serrés, pas 1000).  
3. Sub-agents : `PRIOR_CONTEXT` dans le plan + rôle (routine vs complex) du `.cursor/routing.md` — c’est *ça* la réduction de bruit, pas moins d’intelligence.  
4. Abonnement terminal = **deuxième cerveau** quand l’IDE ne suffit pas, pas un remplacement du cycle `run-cycle`.

---

## Approbation

**Valider par l’orchestrateur (cette version)** : oui — le plan est **exécutable** étape par étape, cohérent FoodKing, couvre toute la surface POS réelle (mémoire + V14) et tranche l’espace *design* vs *gel* vs *gate*.

**Ta prochaine action** (ton workflow) : confirmer “OK 10 passes” (ou demander ajustement d’une seule étape) puis lancer **Étape 1** (charte) avec délégation explicite.

---

## PRIOR_CONTEXT (sub-agents — obligatoire si exécution)

- Dépôt : Laravel + Vue 2/3 mix selon `webpack.mix` : suivre `foodking-vue` skill.  
- Invariants : pricing SSOT, `OrderStatus` enum, `DispatchableAfterCommit`, `branch_id`, `PaymentComponent` FROZEN, symétrie POS↔Kiosk.  
- Mémoire : `07_pos` + `12_decisions` pour ADR, `GLOBAL_SYSTEM_PRIMER` §4.2 pour épisodes.  
- Terminal : ne pas omettre `</dev/null` / `context` + `audit-brief` selon `AGENTS.md`.

---

**EXECUTE_DELEGATION** (plan documentaire, pas de code dans ce commit logique) : *orchestrateur Claude (session Cursor) — prochaine étape = déléguer par fichier `tasks/execute-…` + sub-agent au moment d’EXÉCUTER*.
