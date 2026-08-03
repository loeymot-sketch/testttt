# Audit API Messages (sans CLI `claude`)

- **Base** : `https://api.orcai.cc`
- **Modèle demandé** : `claude-opus-4-7-20250514`
- **max_tokens** : 32768
- **usage** (API) : `{"input_tokens":0,"output_tokens":7707}`
- **stop_reason** : `end_turn`
- **Limite** : pas d’outils — pas de lecture directe des fichiers listés dans le prompt ; le modèle raisonne sur le texte fourni.

---

# AUDIT EXTERNE SENIOR — FoodKing Système Central Data/Sync/Gestion Produit

Auditeur: Kiro (externe, adversarial)
Date: 2025-07-10
Scope: logiciel central uniquement (hardware/TPE/imprimante/Maps = hors scope, listés en annexe UAT)
Base d'analyse: informations déclarées dans le prompt (aucun accès fichier réel)

---

## 1. Cohérence du verdict Codex : Core Sync PASS / Système complet HOLD

Le verdict Codex est **logiquement cohérent**. Voici pourquoi :

VA-SYS-06 à VA-SYS-10 couvrent le **noyau data/sync** : pricing reject, stock choix, authz, outbox/realtime, docs, et une validation massive (175 PHP + 42 Vitest + 4 Playwright + npm prod). Ce périmètre correspond au pipeline critique : écriture centrale → outbox → fanout → projection branches → guards POS/Kiosk. Le qualifier PASS_LOCAL / PASS_RUNTIME_LOCAL_STRONG est honnête car il n'y a pas eu de validation sur infra staging multi-nœuds réelle.

VA-SYS-00 à VA-SYS-05 restent **ouverts**. Sans connaître leur contenu exact (NOT_VALIDATED — je n'ai pas lu `TASKLIST.md`), leur existence suffit à justifier le HOLD système. Un PASS core sync ne vaut pas PASS système si des missions portant sur des couches adjacentes (migrations, seed, permissions fines, UI flows complets, edge cases métier) n'ont pas été exécutées.

**Verdict partiel : PASS — la logique PASS core + HOLD système est saine.** Le risque serait de confondre les deux et de déclarer le logiciel prêt prématurément.

---

## 2. Audit de centralisation — point par point

### 2.1 Ajout / Modification / Suppression produit

| Aspect | Statut | Preuve / Justification |
|---|---|---|
| Création produit simple (central) | PARTIAL | VA-SYS-10 mentionne 175 PHP PASS mais le détail des cas couverts (création avec tous les champs obligatoires, validation métier, slugs, traductions) n'est pas listé dans le prompt. |
| Création produit complexe (wizard/composer) | PARTIAL | VA-SYS-06 couvre pricing reject + stock choix wizard. Le flow complet wizard (étapes, sauvegarde partielle, publication) = NOT_VALIDATED sans lecture de `WIZARD_PRODUCT_MODEL.md`. |
| Modification produit (central → fanout) | PARTIAL | L'outbox VA-SYS-08 prouve le mécanisme CatalogChanged fanout. Mais le test de modification partielle (un seul champ) vs modification complète n'est pas confirmé. |
| Suppression produit (soft delete, cascade) | NOT_VALIDATED | Aucune mention explicite de test de suppression dans les verdicts fournis. Risque P0 : une suppression mal propagée laisse un produit fantôme en POS/Kiosk. |
| Contract violation before broadcast | PASS | VA-SYS-08 le mentionne explicitement. |

### 2.2 Catégories

| Aspect | Statut | Justification |
|---|---|---|
| CRUD catégories central | NOT_VALIDATED | Aucune mention dans les rapports cités. |
| Réordonnancement catégories | NOT_VALIDATED | Critique pour l'affichage Kiosk/OSS. |
| Propagation catégorie vers branches | NOT_VALIDATED | Le fanout outbox existe (VA-SYS-08) mais le test spécifique catégorie n'est pas confirmé. |
| Catégorie vide / catégorie avec produits indisponibles | NOT_VALIDATED | Edge case UX important. |

**Risque P1** : les catégories sont le squelette de navigation client. Si le CRUD + propagation n'est pas testé, le Kiosk/OSS peut afficher des catégories vides ou désordonnées.

### 2.3 Photos

| Aspect | Statut | Justification |
|---|---|---|
| Upload photo produit réservé rôles globaux | PASS | VA-SYS-07B le confirme explicitement. |
| Stockage / CDN / resize | NOT_VALIDATED | Aucune mention d'intégration storage dans les rapports. |
| Suppression photo (orphan cleanup) | NOT_VALIDATED | |
| Propagation URL photo vers branches | NOT_VALIDATED | Le fanout existe mais le payload photo n'est pas confirmé. |

### 2.4 Stock produit

| Aspect | Statut | Justification |
|---|---|---|
| Rupture produit (indisponibilité) | PASS | VA-SYS-06 explicite. |
| Décrémentation stock sur commande | NOT_VALIDATED | Aucune mention. Potentiellement dans VA-SYS-00..05. |
| Seuil alerte stock | NOT_VALIDATED | |
| Sync stock multi-branches (chaque branche a son stock) | PARTIAL | VA-SYS-07B confirme branch isolation + availability fanout scoped. Le mécanisme existe, le test exhaustif n'est pas confirmé. |

### 2.5 Stock choix wizard (stockable choices)

| Aspect | Statut | Justification |
|---|---|---|
| Rupture choix wizard stockable | PASS | VA-SYS-06 explicite. |
| Backend pricing reject stale/forged/unavailable choices | PASS | VA-SYS-06 explicite. |
| POS/Kiosk guards sur choix indisponibles | PASS | VA-SYS-06 explicite. |
| Décrémentation stock choix sur commande | NOT_VALIDATED | Même lacune que stock produit. |

### 2.6 Composer / Wizard — produit simple vs complexe

| Aspect | Statut | Justification |
|---|---|---|
| Data flow composer | NOT_VALIDATED | `CATALOG_COMPOSER_DATA_FLOW.md` existe mais non lu. |
| Wizard produit model | NOT_VALIDATED | `WIZARD_PRODUCT_MODEL.md` existe mais non lu. |
| Distinction simple/complexe en base | PARTIAL | Les tests VA-SYS-06 impliquent que le modèle wizard existe et fonctionne pour les choix stockables. Le modèle complet = NOT_VALIDATED. |
| Sauvegarde partielle wizard (draft) | NOT_VALIDATED | |
| Publication wizard → catalogue actif | NOT_VALIDATED | |

### 2.7 Projection POS / Kiosk / KDS / OSS

| Aspect | Statut | Justification |
|---|---|---|
| Fanout CatalogChanged vers branches actives | PASS | VA-SYS-08 explicite. |
| Projection POS (menu, prix, dispo) | PARTIAL | Guards POS confirmés (VA-SYS-06). Projection complète du catalogue = NOT_VALIDATED. |
| Projection Kiosk (navigation, images, choix) | PARTIAL | Même raisonnement. |
| Projection KDS (items commande, modifiers) | NOT_VALIDATED | KDS reçoit des commandes, pas le catalogue directement, mais la cohérence des données affichées dépend du catalogue. |
| Projection OSS (menu online, disponibilité temps réel) | NOT_VALIDATED | |
| Latence de propagation acceptable (< X secondes) | NOT_VALIDATED | Aucun SLA de latence mentionné dans les verdicts. |

### 2.8 Outbox / Realtime / Fallback

| Aspect | Statut | Justification |
|---|---|---|
| Outbox pattern implémenté | PASS | VA-SYS-08 PASS_RUNTIME_LOCAL_STRONG. |
| Rescue/retry sur échec | PASS | VA-SYS-08 explicite. |
| Provider failure recovery | PASS | VA-SYS-08 explicite. |
| Décision API/outbox et non MCP runtime | PASS | VA-SYS-09 explicite. |
| Fallback polling si realtime down | NOT_VALIDATED | Le rescue/retry couvre le côté serveur. Le fallback côté client (POS/Kiosk perd la connexion WS) n'est pas mentionné. |
| Idempotence des messages outbox | NOT_VALIDATED | Critique pour éviter les doublons en cas de retry. |
| Ordering guarantee (pas de message ancien écrasant un récent) | NOT_VALIDATED | Risque P0 si non géré. |

### 2.9 Branch isolation

| Aspect | Statut | Justification |
|---|---|---|
| Authz central management | PASS | VA-SYS-07B. |
| Branch dashboard scope (pas de leak cross-branch) | PASS | VA-SYS-07B explicite. |
| Composer show no leak | PASS | VA-SYS-07B explicite. |
| Availability fanout scoped par branche | PASS | VA-SYS-07B explicite. |
| Données commande isolées par branche | NOT_VALIDATED | L'authz catalogue est prouvé. L'isolation des commandes/rapports = NOT_VALIDATED. |

### 2.10 Historique / Snapshots

| Aspect | Statut | Justification |
|---|---|---|
| Historique modifications produit (audit trail) | NOT_VALIDATED | Aucune mention. |
| Snapshots catalogue (rollback possible) | NOT_VALIDATED | |
| Versioning prix (historique tarifaire) | NOT_VALIDATED | |

---

## 3. Risques restants classés P0 / P1 / P2

### P0 — Bloquants avant toute mise en production

| # | Risque | Justification |
|---|---|---|
| P0-1 | **Suppression produit non testée** | Un produit supprimé centralement mais toujours visible en POS/Kiosk = commande sur produit inexistant = erreur caisse/cuisine. |
| P0-2 | **Idempotence outbox non prouvée** | Un retry qui recrée un événement = double modification, prix incohérent, stock faux. |
| P0-3 | **Ordering guarantee outbox non prouvé** | Un ancien message arrivant après un récent = rollback silencieux du catalogue sur une branche. |
| P0-4 | **Décrémentation stock sur commande non testée** | Si le stock ne décrémente pas, la rupture automatique ne se déclenche jamais. Tout le mécanisme VA-SYS-06 devient cosmétique. |
| P0-5 | **VA-SYS-00..05 contenu inconnu** | Ces missions peuvent contenir des fondations (migrations, seed, permissions de base) sans lesquelles les PASS de VA-SYS-06..10 reposent sur des fixtures de test et non sur un schéma réel. |

### P1 — Dégradation significative si non traité

| # | Risque | Justification |
|---|---|---|
| P1-1 | Catégories CRUD + propagation non testés | Navigation Kiosk/OSS cassée. |
| P1-2 | Fallback client si realtime down | POS/Kiosk figé sur ancien catalogue. |
| P1-3 | Latence propagation sans SLA | Changement de prix central non reflété en branche pendant X minutes = vente à mauvais prix. |
| P1-4 | Wizard flow complet (draft → publish) non validé | Opérateur bloqué à la création de produit complexe. |
| P1-5 | Projection OSS (online store) non testée | Canal de vente online potentiellement incohérent. |

### P2 — Améliorations importantes mais non bloquantes pour UAT

| # | Risque | Justification |
|---|---|---|
| P2-1 | Historique/audit trail modifications | Pas bloquant pour fonctionner, bloquant pour debug en prod. |
| P2-2 | Snapshots/rollback catalogue | Filet de sécurité, pas critique jour 1. |
| P2-3 | Orphan photo cleanup | Coût storage, pas fonctionnel. |
| P2-4 | Seuils alerte stock | Nice-to-have, l'opérateur peut surveiller manuellement au début. |

---

## 4. Plan d'exécution VA-SYS-00 à VA-SYS-05

Le contenu exact de ces missions est **NOT_VALIDATED** (je n'ai pas lu `TASKLIST.md`). Le plan ci-dessous est une **reconstruction adversariale** basée sur ce qui manque logiquement entre "rien" et les PASS de VA-SYS-06..10. L'ordre est celui des dépendances techniques.

### VA-SYS-00 — Fondations : Migrations, Schéma, Seed

**Objectif exact** : Garantir que le schéma de base de données en production correspond exactement à celui utilisé par les tests VA-SYS-06..10. Migrations exécutables de zéro, seed de données de référence (rôles, permissions, config branches).

**Fichiers à lire/modifier** :
- `database/migrations/*` — vérifier complétude et ordre
- `database/seeders/*` — seed rôles, permissions, branches de test
- `config/database.php` ou équivalent — connexions multi-tenant si applicable
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md` — procédure de setup

**Tests à créer/lancer** :
- `php artisan migrate:fresh --seed` sur base vierge → 0 erreur
- Test unitaire : chaque migration up/down réversible
- Test : seed produit un état cohérent (au moins 1 branche, 1 user par rôle, 1 catégorie, 1 produit simple, 1 produit complexe)

**Critères PASS** : migrate:fresh + seed = 0 erreur, schéma identique à celui des tests VA-SYS-06..10, down migrations fonctionnelles.
**Critères REWORK** : toute erreur de migration, colonne manquante, FK cassée.

**Ordre d'exécution** : **1er** (tout le reste en dépend).

**Risque business** : si le schéma de test diverge du schéma réel, tous les PASS VA-SYS-06..10 sont invalides en production.

---

### VA-SYS-01 — CRUD Catalogue Complet (Produits + Catégories)

**Objectif exact** : Valider le cycle de vie complet : création, lecture, modification, suppression (soft delete) de produits simples, produits complexes (wizard), et catégories. Inclut la validation métier (champs obligatoires, unicité slug, contraintes prix).

**Fichiers à lire/modifier** :
- `app/Http/Controllers/` — contrôleurs produit et catégorie
- `app/Models/Product.php`, `app/Models/Category.php` et relations
- `app/Services/CatalogComposer*` ou équivalent (cf. `CATALOG_COMPOSER_DATA_FLOW.md`)
- `app/Http/Requests/` — form requests validation
- `resources/js/` — composants wizard si Vitest couvre le front

**Tests à créer/lancer** :
- Feature test : CRUD produit simple (create, read, update, soft-delete, restore)
- Feature test : CRUD produit complexe via wizard (create draft, add steps/choices, publish, update, soft-delete)
- Feature test : CRUD catégorie (create, reorder, update, delete avec produits associés, delete vide)
- Feature test : suppression produit → vérifier qu'il disparaît des projections (ou est marqué unavailable)
- Feature test : modification prix → vérifier cohérence avec pricing engine VA-SYS-06
- Edge case : création produit avec catégorie inexistante, produit sans prix, produit avec choix circulaire

**Critères PASS** : tous les tests verts, soft-delete propagé, aucun produit fantôme après suppression.
**Critères REWORK** : produit supprimé toujours visible en projection, validation métier contournable.

**Ordre d'exécution** : **2ème** (dépend de VA-SYS-00).

**Risque business** : sans CRUD validé, l'opérateur ne peut pas gérer son catalogue. C'est la fonctionnalité n°1 du logiciel.

---

### VA-SYS-02 — Propagation & Projection Multi-Canal

**Objectif exact** : Valider que toute mutation catalogue (VA-SYS-01) déclenche correctement l'outbox (VA-SYS-08) et que chaque canal (POS, Kiosk, KDS, OSS) reçoit une projection cohérente et à jour.

**Fichiers à lire/modifier** :
- `app/Services/Outbox/` ou équivalent — événements CatalogChanged
- `app/Projections/` ou `app/Listeners/` — handlers par canal
- `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md` — logique de disponibilité
- `docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md` — matrice existante

**Tests à créer/lancer** :
- Integration test : création produit central → outbox event émis → projection POS mise à jour
- Integration test : modification prix → projection Kiosk reflète le nouveau prix
- Integration test : suppression produit → projection OSS ne le montre plus
- Integration test : rupture stock (VA-SYS-06) → projection POS/Kiosk marque indisponible
- Test de latence : mesurer le temps entre mutation et projection à jour (définir SLA, ex: < 2s en local)
- Test idempotence : rejouer le même événement outbox 2x → projection identique (pas de doublon)
- Test ordering : envoyer événement ancien après événement récent → projection garde l'état récent

**Critères PASS** : projection cohérente sur les 4 canaux, idempotence prouvée, ordering prouvé, latence < SLA défini.
**Critères REWORK** : doublon en projection, état stale après replay, canal oublié.

**Ordre d'exécution** : **3ème** (dépend de VA-SYS-01 + VA-SYS-08 déjà PASS).

**Risque business** : P0-1, P0-2, P0-3 sont tous fermés par cette mission. C'est la mission la plus critique du backlog.

---

### VA-SYS-03 — Stock Transactionnel (Décrémentation sur Commande)

**Objectif exact** : Valider que passer une commande décrémente le stock produit et le stock choix wizard, et que l'atteinte du seuil 0 déclenche la rupture automatique (connecte avec VA-SYS-06).

**Fichiers à lire/modifier** :
- `app/Services/Order/` ou `app/Actions/PlaceOrder*` — logique commande
- `app/Models/Stock.php` ou `app/Models/ProductBranchStock.php`
- `app/Services/Stock/` — décrémentation + trigger rupture
- `app/Events/StockDepleted*` ou équivalent

**Tests à créer/lancer** :
- Feature test : commande 1 unité → stock décrémente de 1
- Feature test : commande qui amène stock à 0 → produit passe en rupture automatiquement
- Feature test : commande avec choix wizard stockable → stock choix décrémente
- Feature test : commande concurrente (2 commandes simultanées, 1 seul stock restant) → une seule réussit, l'autre reçoit erreur stock insuffisant
- Feature test : annulation commande → stock ré-incrémenté (si applicable au business)
- Edge case : commande avec quantité > stock disponible → rejet

**Critères PASS** : décrémentation atomique, rupture auto à 0, concurrence gérée sans oversell, lien avec VA-SYS-06 prouvé.
**Critères REWORK** : oversell possible, rupture non déclenchée, race condition.

**Ordre d'exécution** : **4ème** (dépend de VA-SYS-01 pour les produits, VA-SYS-06 déjà PASS pour la rupture).

**Risque business** : P0-4. Sans décrémentation, le stock est décoratif. L'opérateur vend des produits qu'il n'a plus.

---

### VA-SYS-04 — Photos & Assets Produit

**Objectif exact** : Valider upload, stockage, association produit, propagation URL vers branches, et suppression (cleanup orphelins).

**Fichiers à lire/modifier** :
- `app/Http/Controllers/ProductPhotoController*`
- `app/Services/Storage/` ou `config/filesystems.php`
- `app/Models/ProductPhoto.php` ou relation sur Product
- Front : composant upload dans le wizard/composer

**Tests à créer/lancer** :
- Feature test : upload photo → stockée, URL retournée, associée au produit
- Feature test : upload par rôle non autorisé → rejet (connecte avec VA-SYS-07B déjà PASS)
- Feature test : suppression produit → photo marquée orpheline ou supprimée
- Feature test : modification photo → ancienne URL invalidée dans les projections
- Feature test : propagation URL photo dans le payload CatalogChanged vers branches
- Edge case : upload fichier non-image, fichier trop gros, format non supporté

**Critères PASS** : upload/delete fonctionnel, authz respecté, URL propagée dans outbox, validation format/taille.
**Critères REWORK** : photo non propagée, orphelins non gérés, bypass authz.

**Ordre d'exécution** : **5ème** (dépend de VA-SYS-01 pour le produit, VA-SYS-02 pour la propagation, VA-SYS-07B déjà PASS pour l'authz).

**Risque business** : P1 pour le Kiosk/OSS (l'image produit est un driver de conversion). P2 pour le POS (souvent texte seul).

---

### VA-SYS-05 — Smoke Test End-to-End & Regression Gate

**Objectif exact** : Scénario complet de bout en bout simulant une journée opérateur : setup branche, créer catégories, créer produits simples et complexes, uploader photos, modifier prix, mettre en rupture, passer commandes, vérifier projections, vérifier stock, vérifier isolation inter-branches.

**Fichiers à lire/modifier** :
- `tests/E2E/` ou `tests/Browser/` — Playwright ou Dusk
- Tous les fichiers touchés par VA-SYS-00..04
- `docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md` — compléter la matrice

**Tests à créer/lancer** :
- Playwright E2E : scénario opérateur complet (15-20 étapes)
- Playwright E2E : scénario multi-branches (2 branches, vérifier isolation)
- Regression suite : relancer les 175 PHP + 42 Vitest + 4 Playwright existants → 0 régression
- Smoke API : curl/httpie sur les endpoints critiques avec assertions

**Critères PASS** : E2E vert, 0 régression sur suite existante, matrice de validation complétée et signée.
**Critères REWORK** : toute régression, scénario E2E échoué, matrice incomplète.

**Ordre d'exécution** : **6ème et dernier** (dépend de tout le reste).

**Risque business** : sans E2E, les PASS unitaires/intégration peuvent masquer des incohérences de flux. C'est le gate final avant UAT matériel.

---

## 5. Résumé des statuts par domaine

| Domaine | Statut | Missions de couverture |
|---|---|---|
| Outbox/realtime/retry/recovery | **PASS** | VA-SYS-08 |
| Authz/branch isolation catalogue | **PASS** | VA-SYS-07B |
| Stock choix wizard + pricing reject | **PASS** | VA-SYS-06 |
| Docs/décisions architecture sync | **PASS** | VA-SYS-09 |
| Suite de tests existante (175+42+4) | **PASS** | VA-SYS-10 |
| Migrations/schéma/seed | **NOT_VALIDATED** | → VA-SYS-00 |
| CRUD catalogue complet | **NOT_VALIDATED** | → VA-SYS-01 |
| Propagation multi-canal + idempotence + ordering | **NOT_VALIDATED** | → VA-SYS-02 |
| Stock transactionnel (décrémentation) | **NOT_VALIDATED** | → VA-SYS-03 |
| Photos/assets | **NOT_VALIDATED** | → VA-SYS-04 |
| E2E smoke + regression gate | **NOT_VALIDATED** | → VA-SYS-05 |
| Catégories CRUD + propagation | **NOT_VALIDATED** | → VA-SYS-01 (inclus) |
| Historique/snapshots/audit trail | **NOT_VALIDATED** | Hors VA actuel, P2 |
| Fallback client (POS/Kiosk offline) | **NOT_VALIDATED** | À planifier post-VA ou dans VA-SYS-02 |

---

## 6. Liste UAT Matériel (hors scope, pour mémoire)

À traiter **après** SOFTWARE_DECISION = READY :
- Intégration TPE (paiement carte)
- Intégration imprimante tickets (ESC/POS)
- Intégration Google Maps (localisation branches)
- Test hardware Kiosk (écran tactile, lecteur NFC)
- Test réseau dégradé (latence, coupure WiFi)
- Test impression KDS

---

## 7. Graphe de dépendances d'exécution

```
VA-SYS-00 (Fondations)
    │
    ▼
VA-SYS-01 (CRUD Catalogue)
    │
    ├──────────────────┐
    ▼                  ▼
VA-SYS-02           VA-SYS-03
(Propagation)       (Stock Transactionnel)
    │                  │
    ▼                  │
VA-SYS-04             │
(Photos)              │
    │                  │
    └────┬─────────┘
             ▼
         VA-SYS-05
      (E2E + Regression)
```

VA-SYS-02 et VA-SYS-03 peuvent être parallélisés si deux agents Codex sont disponibles. VA-SYS-04 dépend de VA-SYS-02 (propagation photo). VA-SYS-05 attend tout.

---

## 8. Avertissements adversariaux

**Ce que je ne peux pas prouver sans accès fichier** : la totalité de cet audit repose sur les verdicts déclarés dans le prompt. Si les rapports VA-SYS-06..10 contiennent des tests qui passent sur des fixtures artificielles et non sur le schéma réel de production, les PASS sont **invalides**. VA-SYS-00 est conçu pour fermer ce risque.

**Ce qui m'inquiète le plus** : l'absence de test de suppression produit (P0-1) et de décrémentation stock (P0-4). Ce sont des opérations quotidiennes d'un restaurant. Si elles ne fonctionnent pas, le logiciel est inutilisable indépendamment de la qualité du sync.

**Ce qui est solide** : le pattern outbox + authz + branch isolation + pricing reject forme un socle technique sain. Les choix architecturaux (API/outbox plutôt que MCP runtime) sont pragmatiques. La couverture de test existante (175+42+4) est un bon point de départ.

---

## 9. Verdicts

```
MASTER_AUDIT_VERDICT:   REWORK
```

Justification : 5 missions ouvertes couvrant des fonctionnalités fondamentales (CRUD, stock transactionnel, propagation complète, photos, E2E). 3 risques P0 non fermés. Le noyau sync est solide mais le système n'est pas complet.

```
SOFTWARE_DECISION:      READY_FOR_VA_SYS_00_05_EXECUTION
```

Justification : aucun blocage architectural détecté. Les fondations (outbox, authz, pricing) sont en place. Les missions VA-SYS-00..05 sont des extensions logiques, pas des refactorisations. Le chemin vers PASS est clair et linéaire.

```
NEXT_CODEX_MISSION:     VA-SYS-00 (Fondations: Migrations, Schéma, Seed)
```

Justification : tout le reste en dépend. Exécution estimée : la plus courte des 6 missions, mais la plus critique pour valider que les PASS existants tiennent sur un schéma réel.

```
SÉQUENCE COMPLÈTE:
  VA-SYS-00 → VA-SYS-01 → [VA-SYS-02 ∥ VA-SYS-03] → VA-SYS-04 → VA-SYS-05
```