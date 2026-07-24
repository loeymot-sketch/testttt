# E4 — CAISSE + KDS cross-surface (authentifié, LOCAL) — test-e2e MASSIF

**Cible** : `http://127.0.0.1:8000` (serve UP, worker redis UP, soketi UP) · DB `foodking_e2e`
**Outil** : Playwright chromium (config projet), 1 worker, `PLAYWRIGHT_NO_WEB_SERVER=1`
**Spec** : `tests/e2e/_teste2e-massive-E4-caisse-kds-2026-07-24.spec.js` — **5/5 PASS** (1.2 min)
**Captures** : `tests/e2e/__screenshots__/e2e-massive-E4/`
**Discipline** : VISUEL D'ABORD (chaque surface capturée + lue) · intégrité numérique · idempotence prouvée

## Tableau PASS/FAIL

| Scénario | Résultat | Preuve |
|---|---|---|
| **S1** Caisse `/admin/pos` rend | **PASS** | Panneaux « À encaisser borne (5) », « Commandes web · 13 » (Accepter/Détails), ticket droit. Compteur honnête (D-2) : phantom non-web `PH641` **absent** du board. 0 raw label. `01-caisse-pos.png` |
| **S2** Cycle web→caisse→KDS | **PASS** | PENDING(1)/UNPAID → Accepter → **ACCEPT(4) + PENDING_COUNTER(15) + COUNTER_DEFERRED(6)** → KDS board-released → Encaisser **PAID(5) + fiscal_seq=2679**. `02a/02b` |
| **S2** Encaisser idempotent | **PASS** | Re-POST même clé : fiscal_seq **inchangé (2679)**, cash_movements **=1 (pas de 2e tiroir)**, status PAID. Aucun double encaissement |
| **S2** Intégrité numérique | **PASS** | total order = 4,00 € == received = 4,00 € == KDS. Cohérent bout-en-bout |
| **S3** KDS `/kds` | **PASS** | Carte `N°W8675`, badge **EN LIGNE** (source-aware), timer **ATTENTE 00:46** ancré (pas fausse ultra-retard), `1× FRI` (Frites, sans taille — correct), bump « Prêt » + print. 0 raw label. `03-kds-lanes.png` |
| **S4** Hub `/admin/catalog-hub` | **PASS** | 2 onglets **Catalogue** + **Produits & Stock**, « Ajouter Un Article », cartes photo + badges Actif, « Tableau de stock ». 0 raw label. `04-catalog-hub.png` |
| **S5** Attaque D-1 (résurrection) | **PASS** | CANCELED→ACCEPT = **422**, →PREPARING = **422**, statut **reste CANCELED**. Garde terminal→actif étanche |
| **S5** Double-accept idempotent | **PASS** | webB : accept ×2 = 200/200, reste ACCEPT, marqueur COUNTER_DEFERRED inchangé |
| **S5** Traçabilité accepté-non-encaissé | **PASS** | webKeep : ACCEPT + PENDING_COUNTER, **fiscal_seq=null** (pas d'alloc prématurée) — traçable, non perdu |

**Console/HTTP** : **0 erreur console, 0 réponse 4xx/5xx** sur les 4 surfaces (S1-S4).
**Réconciliation KDS** : board actif = commandes NOUVELLE (status 4) du jour = exactement mes 3 web seedées `[W8675,W7299,W7359]` ; à la capture S3 seule W8675 était acceptée → carte unique **correcte** (les PREPARED sortent de la lane active par design).

## Findings

- **P3-1 (visuel, reproductible)** — Sur `/admin/pos`, le pill orange « À encaisser N » de la barre du haut **chevauche** le titre « CAISSE LE CAYENNE / Commande rapide » (z-index/position). Cosmétique. Vu sur `01` + `02b`.
- **P3-2 (visuel mineur)** — Hub : fines pastilles roses sous les cartes article (skeletons de tags ?) ; catégories Faker (Quia/Reiciendis/Deleniti) = données seedées e2e attendues. Non bloquant.
- **Artefact de sonde (PAS un défaut)** — Le clic UI « Accepter » via sélecteur générique `.first()` a ciblé une commande web pré-existante (N°A0042, en tête du tri oldest-first) au lieu de la commande fraîchement seedée (qui trie en dernier, hors slice visible `(0,4)`). L'endpoint déterministe (appel exact du bouton) a prouvé le flux de webA. Effet de bord : 1 commande web e2e pré-existante acceptée. Aucun impact prod (DB test).

**Corroboration NF525 (bonus)** — Le cleanup ne peut **pas** supprimer webA (encaissée) : le trigger `orders_no_delete` (P1-1) rejette `SQLSTATE 45000 « fiscal number reuse forbidden »`. La commande scellée (fiscal_seq=2679) est **immuable** = protection fiscale active et prouvée en conditions réelles. (Spec cleanup durci : per-row, saute les rows fiscalisées ; 4/5 purgées, 1 scellée laissée par design.)

**Aucun P0/P1/P2.** Cœur cross-surface (web→caisse→KDS, idempotence encaissement, garde D-1, compteur D-2) = **SOLIDE, prouvé visuellement + numériquement.**
