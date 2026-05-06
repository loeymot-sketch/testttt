# Plan maître — Audit E2E massif multi-surfaces (Playwright) — 2026-05-04

## Statut d’exécution

| Champ | Valeur |
| --- | --- |
| **Type** | Plans + **RUN d’exécution** documenté (2026-05-04) |
| **EXÉCUTION** | **Effectuée** sur ordre utilisateur « non-stop P0→P5 » — bundle : `reports/e2e-massive/20260504_1956_E2E_MASSIVE/` (manifest + logs + `RAPPORT_CONSOLIDE_P0_P5.md`). Pointeur : `reports/antigravity/latest.md`. |
| **Stratégie de test déclarée** | `playwright-full-e2e` + vérifications manuelles ciblées là où l’automate ne peut pas signer (imprimante, TPE réel, horloge borne). |
| **Mémoire projet** | Graphiti consulté (sync POS/KDS/Kiosk, flows critiques) ; complément local `memory/INDEX.md` + épisodes `memory/episodes/*.jsonl`. |

## Objectif

Décomposer l’application en **plans d’audit E2E massifs**, chacun :

- **Architecturé** (prérequis données, ordre des scénarios, dépendances entre plans).
- **Riche en données** (matrices article / variation / supplément / ingrédient / branche).
- **Traçable** : à chaque étape significative : **capture d’écran nommée**, **vérification visuelle ou assertion**, **entrée dans le rapport de lot** (voir conventions ci-dessous).
- **Tri profond** des problèmes : P0 bloquant prod, P1 fonctionnel, P2 UX/a11y/i18n, P3 perf/flaky, P4 intégrité données (branch_id, SSOT prix).

## Ordre d’exécution recommandé (dépendances)

1. **P0** — [`E2E_MASSIVE_AUDIT_P0_CENTRALE_STOCK_SYNC_2026-05-04.md`](./E2E_MASSIVE_AUDIT_P0_CENTRALE_STOCK_SYNC_2026-05-04.md)  
   Centrale (admin) + synchronisation « stock » au sens large : **produit fini**, **variations / compositions**, **suppléments / sauces / crudités**, **ruptures** (item, variation, ingrédient), **V1 simple** catégories / articles (hors complexité V2).
2. **P1** — [`E2E_MASSIVE_AUDIT_P1_POS_CAISSE_2026-05-04.md`](./E2E_MASSIVE_AUDIT_P1_POS_CAISSE_2026-05-04.md)  
   Caisse vendeur : parcours massifs alignés sur les capacités réelles du POS.
3. **P2** — [`E2E_MASSIVE_AUDIT_P2_KIOSK_BORNE_2026-05-04.md`](./E2E_MASSIVE_AUDIT_P2_KIOSK_BORNE_2026-05-04.md)  
   Borne client : idle, type commande, wizard, panier, paiement, file d’attente, offline si applicable.
4. **P3** — [`E2E_MASSIVE_AUDIT_P3_KDS_CUISINE_2026-05-04.md`](./E2E_MASSIVE_AUDIT_P3_KDS_CUISINE_2026-05-04.md)  
   Écran cuisine : réception commande, stations, statuts, résilience WS/polling.
5. **P4** — [`E2E_MASSIVE_AUDIT_P4_OSS_ETAT_COMMANDE_2026-05-04.md`](./E2E_MASSIVE_AUDIT_P4_OSS_ETAT_COMMANDE_2026-05-04.md)  
   OSS / écran état commande (si déployé dans l’environnement de test) : cohérence avec KDS/POS.
6. **P5** — [`E2E_MASSIVE_AUDIT_P5_CROSS_SURFACE_SYNCHRONISATION_2026-05-04.md`](./E2E_MASSIVE_AUDIT_P5_CROSS_SURFACE_SYNCHRONISATION_2026-05-04.md)  
   **Chorégraphie globale** : modifier stock / rupture côté centrale → observer POS + Kiosk + KDS (+ OSS) ; commandes croisées ; paiement ; ticket.

## Conventions d’artefacts (captures + rapports)

### Dossier par run

```
reports/e2e-massive/<RUN_ID>/
  manifest.md          # liste ordonnée des steps + fichier capture + verdict
  screenshots/         # PNG plein écran ou viewport ciblé
  network/             # optionnel : HAR export Playwright si activé
  console/             # optionnel : dump console par step
```

`<RUN_ID>` = `YYYYMMDD_HHMM_<PLAN_TAG>_<git_short>` (ex. `20260504_1530_P0_a1b2c3d`).

### Nommage capture (obligatoire)

`<PLAN_TAG>__<STEP_ID>__<SURFACE>__<slug>.png`

Exemples :

- `P0__S03__admin__ingredients_list_before_toggle.png`
- `P1__S12__pos__wizard_step_totals.png`
- `P5__S07__kds__order_line_after_rupture.png`

### Vérification « double » après chaque capture

1. **Automate** : assertion Playwright (selector, texte, requête réseau attendue, ou `expect` API si test hybride).
2. **Humain / rapport** : dans `manifest.md`, colonnes : `capture OK visuellement ?` `anomalie ?` `gravité P0-P4` `fichier code suspect` `issue GitHub #`.

### Rapport consolidé par plan

Après chaque lot P* :

- Mettre à jour **`reports/antigravity/latest.md`** (règle repo Playwright) **ou** créer `reports/e2e-massive/<RUN_ID>/RAPPORT_P*.md` et y faire un lien depuis `latest.md` pour ne pas écraser l’historique.

## Architecture technique des tests (à respecter à l’exécution)

| Couche | Rôle |
| --- | --- |
| **Fixtures** | Comptes `admin`, `caissier`, `chef` ; `branch_id` fixe ; jeux d’articles seedés ou créés via API/factory en `beforeAll`. |
| **Page objects** | `tests/Playwright/pages/AdminIngredientsPage.js`, `PosCashierPage.js`, etc. (à créer lors de l’implémentation — pas dans cette phase plan). |
| **Idempotence** | `workers: 1` déjà dans `playwright.config.js` ; pas de parallélisme sur login. |
| **Broadcast** | Vérifier `BROADCAST_DRIVER` non silencieux en staging (sentinel PHPUnit existant) avant run E2E réaliste. |
| **Invariants FoodKing** | Pas de logique prix côté front ; `OrderStatus` enum ; `branch_id` partout ; pas d’édition zones NF525 sans gate. |

## Fichiers Playwright existants (ancrage)

Répertoire : `tests/Playwright/` — inclut notamment `critical-flow/v1-ingredient-rupture-propagation.spec.js`, `pos-receives-kiosk-realtime.spec.js`, `KdsMultiScreenPlaywrightTest.spec.js`, etc. Les plans P* référencent **extension** de ces specs, pas duplication aveugle.

## Prochaine action (après validation humaine des plans)

1. Relecture humaine des 6 plans + ce maître.
2. Ordre explicite : « GO exécution P0 » puis enchaînement.
3. Création branche + réservation `agent-activity-log.sh start` sur les dossiers touchés par l’implémentation des specs.
4. Runs Playwright + remplissage `manifest.md` + `RAPPORT_P*.md`.

---

*Fin du plan maître — aucune exécution de test dans cette livraison.*
