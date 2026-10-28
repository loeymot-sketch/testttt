# GOAL — CAISSE : voir TOUT ce que le client a pris (2026-08-24)

> Mission propriétaire (verbatim) : « audit et corrige et finis par boucle de test-e2e jusqu'à
> tout validé pour la gestion complète pour toutes les fonctionnalités principales de la caisse
> et de visionnage comme les commandes qui sont en cours, les commandes qui sont par téléphone,
> faudrait pas juste voir le total vaut mieux voir si je clique sur voir tout … mettre même les
> noms de produits … je peux voir ce qu'il a pris et toutes les personnalisations qu'il a fait …
> surtout la rapidité et l'interface pour la caisse, toutes les pages, ultra profond. »

**Le besoin réel, en une phrase** : un client est au comptoir, le caissier n'a pas son nom —
il doit pouvoir l'identifier par le CONTENU de sa commande, produits ET personnalisations,
en français lisible, sans quitter son écran.

---

## §0 — Préambule

**§0.1 Arbre de travail.** Worktree `.claude/worktrees/goal-caisse-vision-2026-08-24`,
branche `goal/caisse-vision-2026-08-24` créée depuis `43b120c7d` (= HEAD de
`pos/category-first-caisse-2026-06-23`, == origin, == prod). Le checkout partagé porte ~80
fichiers modifiés par une autre session : **hors périmètre, jamais touchés**. `origin/main` a
2485 commits de retard — il n'a PAS servi de base.

**§0.2 Environnement.** `vendor/` + `node_modules/` en liens durs (0 octet disque),
`.env` copié avec `APP_URL=http://127.0.0.1:8000`, bundles reconstruits (`npx mix`, exit 0,
17 bundles), serveur `php artisan serve` sur `127.0.0.1:8000` — 200 sur `/login`, `/admin/pos`,
`/kds`. PHPUnit tourne sur SQLite `:memory:` (`phpunit.xml:68-69`) : **la base MySQL partagée
n'est jamais touchée par les tests**.

**§0.3 Pipeline par tâche.** `~/.claude/skills/ultra-audit-profond/` — non re-décrit ici.

**§0.4 Convergence.** Deux cycles consécutifs avec P0+P1 = 0 ET jeux de constats identiques.

---

## §1 — Ancres vérifiées (ANCHOR-FIRST)

Cartographie parallèle 4 spécialistes lecture seule, rapports sur disque :
`reports/goal-caisse-vision-2026-08-24/{CARTO_SURFACES,CARTO_COMPOSITION,CARTO_FLUX_PERF,CARTO_TESTS}.md`

- **23 surfaces caisse** inventoriées (17 routes Vue + 6 pages Blade « roue »).
- **5 fichiers FROZEN** dans le périmètre : `admin-pos-v4.blade.php`, `pos-wizard.js`,
  `pos-wizard.css`, `PaymentComponent.vue`, `v5/PosV5TrancheRow.vue` → **zéro ligne**.
- **Chaîne d'alimentation du suivi** : `PosOrdersTrackerComponent.vue:1518` →
  `store/modules/posOrder.js:177` → `routes/api.php:1358` → `PosOrderController.php:250` →
  `OrderService.php:133 list()` → `SimpleOrderResource.php:21`.
- **Mesure réelle du flux** (trace SQL) : 6 requêtes + 1 (`sealedOrderIds`) = **7**,
  **77 ms**, **97 935 o pour 100 commandes** (972 o/commande, 1,08 ligne/commande).
- **Fait décisif** : variations/extras/snapshot sont des **colonnes de `order_items`**
  (`app/Models/OrderItem.php:71-73`, casts `:101-103`), déjà rapatriées par le `select *` de la
  requête existante (`OrderService.php:151-155`, set `lean`). **Les exposer coûte 0 requête SQL.**
- **Normaliseur canonique déjà branché sur la caisse** : `resources/js/helpers/posReceiptBuilder.js`
  — `normalizeReceiptVariations:164`, `normalizeReceiptExtras:198`, `normalizeReceiptAddons:244`.
  Il réconcilie les DEUX formes (legacy `{variation:{variation_name,name}}` et instantané NF525
  `{attribute_name, variation_name}`, rôles inversés — discriminant `:174`). 20 cas Vitest verts.

---

## §2 — Défauts CONFIRMÉS (chacun relu dans le code, pas rapporté sur parole)

| id | sév | Constat | Preuve |
|---|---|---|---|
| **D1** | **P0** | Le suivi caisse n'expose AUCUNE personnalisation. Le caissier voit 3 noms de produits et un total — il ne peut pas identifier un client par sa commande. | `SimpleOrderResource.php:224-245` ne ship que `item_id, item_name, quantity, instruction` |
| **D2** | **P0** | Pas de « voir tout ». La carte coupe à 3 lignes et « + N autres » n'est pas cliquable ; le reste exige un changement de page (et un rechargement complet depuis `/admin/pos-v4`). | `PosOrdersTrackerComponent.vue:1995` `slice(0,3)` ; `:331` `<li>` inerte ; `pos-app.js:118-140` stubs `window.location.assign` |
| **D3** | **P1** | Les **suppléments de formule (addons)** sont facturés et imprimés sur le ticket, mais **absents du détail caisse**. | `grep -c addon PosOrderShowComponent.vue` = **0** ; rendus en `ReceiptComponent.vue:162-170` ; construits en `CompositionSnapshotBuilder.php:166-177` |
| **D4** | **P1** | **La commande TÉLÉPHONE est indistinguable d'une vente au comptoir** : `sourceOf()` ne connaît pas `'phone'` → retombe sur `'pos'` → 🛒 « Caisse ». Or le client n'est PAS là : il faut l'appeler et il viendra payer. Même défaut pour `uber_eats` (23 cmd) et `delivery` (42 cmd). 4 onglets de filtre pour 6 canaux réels. | `PosOrdersTrackerComponent.vue:1944-1958`, `:1961-1965`, `sourceTabs() :1027` ; canal `phone` créé en `OrderService.php:1273`, prouvé par `tests/Feature/Pos/PhoneOrderDeferredTest.php` |
| **D5** | **P1** | Board KDS *legacy* : le template lit `extra.name` alors que l'instantané NF525 porte `extra_name` ⇒ « Extras: , , ». **Voie KDS, hors voie caisse** — arbitré §5. | `KitchenDisplaySystemComponent.vue:273,527,715,891,1063` vs `CompositionSnapshotBuilder.php:110` et `KDSOrderItemsResource.php:81-86` |
| **D6** | **P2** | `item_name` peut être `null` (produit supprimé) → ligne vide sans repli sur la carte. | `SimpleOrderResource.php:237` ; template `:329` |
| **D7** | **P2** | Cadence réelle du suivi = **5 s / 12 req/min en permanence** (et non 60 s) : `lastEventAt` n'est réarmé que par un event Echo livré, donc `eventsStale` est vrai dès 35 s sans commande. Aucune pause sur onglet caché. Déjà consigné POSPERF-09. | `PosOrdersTrackerComponent.vue:794,801,813,1406,1409-1419` ; pas de `visibilitychange` |

---

## §3 — Budget de performance (contrainte dure, mesurée)

1. **≤ 12 req/min au repos, ≤ 32 à l'ouverture** — `tests/e2e/pos-request-budget.spec.js:33,36`.
   L'enrichissement voyage **dans la réponse existante**, jamais dans un second appel.
2. **≤ 8 requêtes SQL par tick** (référence 7). Conserver la garde `relationLoaded`
   (`SimpleOrderResource.php:225`) ; **n'ajouter aucune relation** au set `lean`.
3. **≤ +150 o/commande en moyenne, ≤ 600 o pour la commande la plus composée.**
   ⇒ forme **compacte** obligatoire, **jamais** le passthrough du `composition_snapshot` brut
   (mesuré : +267 o/cmd moyen, +1 209 o/cmd pire cas = +124 %).
   Cible payload total **≤ 125 Ko / 100 commandes** (référence 97 231 o).
4. **≤ 100 ms serveur** pour la page de 100 (référence 77 ms).
5. **Zéro régression** sur `PosOrderListLeanPaginationTest.php`,
   `posKioskPollingCadenceSentinel.spec.js`, `pos-request-budget.spec.js`.

---

## §4 — Vagues

### Vague A — Backend : la composition compacte dans le flux (D1)
- **T-A.1** Écrire d'ABORD le test qui échoue : le payload du suivi porte variations, extras,
  suppléments et instruction, sous forme compacte, pour les DEUX formes (legacy + instantané NF525).
  → *(test À CRÉER : `tests/Feature/Pos/TrackerCompositionPayloadTest.php`)*
- **T-A.2** Garde N+1 : payload enrichi sans **aucune** requête SQL supplémentaire, prouvé par
  `DB::listen` dans le test. → *(même fichier)*
- **T-A.3** Garde de poids : la commande la plus composée reste sous 600 o d'enrichissement.
  → *(même fichier)*
- **T-A.4** Non-régression du contrat existant.
  → `tests/Feature/Pos/SimpleOrderResourceTrackerContractTest.php` (4 cas) reste vert.
- **Acceptance** : les 3 fichiers ci-dessus verts + `tests/Feature/Pos` complet sans nouvelle rouge.

### Vague B — Caisse : « voir tout » + personnalisations lisibles (D1, D2, D6)
- **T-B.1** Sous chaque produit de la carte de suivi, une ligne de personnalisation compacte
  en français (« Sauce algérienne · Salade, Tomate · +2 Cheddar »), tronquée proprement.
- **T-B.2** **« Voir tout »** : ouvre sur place un panneau listant **toutes** les lignes avec
  **toutes** les personnalisations — **zéro appel réseau** (les données sont déjà en mémoire),
  fermeture Échap, focus piégé, testid stable.
- **T-B.3** Repli sur nom de produit manquant (D6) : jamais de ligne vide.
- **T-B.4** Vitest : montage réel de `itemsPreview`/`extraItemsCount`/panneau, les 2 formes.
  → *(test À CRÉER : `tests/js/posTrackerCompositionVisible.spec.js`)*
- **Acceptance** : `tests/js/posTrackerCompositionVisible.spec.js` vert +
  `tests/js/PosOrdersTrackerComponent.spec.js`, `posTrackerWebIntel.spec.js`,
  `posOrdersTrackerWebVisibility.spec.js` restent verts + capture Playwright lue et analysée.

### Vague C — Canaux : le téléphone cesse d'être invisible (D4)
- **T-C.1** `sourceOf()` reconnaît `phone`, `uber_eats`/`deliveroo`, `delivery` ; icône + libellé FR
  dédiés ; onglets de filtre alignés sur les canaux réellement présents.
- **T-C.2** Clés i18n FR ajoutées (`pos.tracker.source_phone`, `…_platform`, `…_delivery`).
- **T-C.3** Vitest sur les 6 canaux.
  → *(test À CRÉER : `tests/js/posTrackerCanaux.spec.js`)*
- **Acceptance** : `posTrackerCanaux.spec.js` vert + aucun libellé brut à l'écran.

### Vague D — Détail caisse : les suppléments facturés deviennent visibles (D3)
- **T-D.1** `PosOrderShowComponent.vue` rend les addons via `normalizeReceiptAddons`
  (`posReceiptBuilder.js:244`), même vocabulaire que le ticket (`label.addons` = « Suppléments »).
- **T-D.2** Vitest étendu. → `tests/js/posOrderShowComposition.spec.js` (7 cas existants) + cas addons.
- **Acceptance** : `posOrderShowComposition.spec.js` vert, addons prouvés sur les 2 formes.

### Vague E — Boucle e2e adversariale jusqu'au vert
- **T-E.1** Spec Playwright de bout en bout : commande multi-lignes personnalisée → carte de suivi
  → « voir tout » → détail. → *(test À CRÉER : `tests/e2e/goal-caisse-vision-2026-08-24.spec.js`)*
- **T-E.2** Budget de requêtes tenu. → `tests/e2e/pos-request-budget.spec.js` reste vert.
- **T-E.3** Captures lues et analysées (pas seulement prises) sur les surfaces touchées.
- **T-E.4** Contre-audit adverse indépendant des captures ; boucle jusqu'à convergence.
- **Acceptance** : 2 cycles consécutifs identiques, P0+P1 = 0, zéro erreur console.

---

## §5 — Arbitrages

- **D5 (KDS legacy) est hors voie caisse.** Un extra perdu en cuisine = un produit faux remis au
  client : c'est réel et grave. Décision : **corriger**, dans un commit séparé et explicitement
  étiqueté hors-voie, après la convergence caisse — jamais mélangé aux commits caisse.
- **D7 (cadence 5 s)** : déjà consigné POSPERF-09, cause identifiée ici avec précision. **Non
  traité dans ce GOAL** — c'est un changement de contrat de synchro (zone partagée §6 SYSTEM_MAP,
  coordination requise). Consigné pour arbitrage propriétaire.
- **Zone FROZEN** : aucune des 5 vagues ne nécessite de toucher un fichier §7. Si l'implémentation
  en venait à l'exiger → STOP + `lock-plan`.

## §G — Portes propriétaire

| Porte | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| G1 | Validation du rendu « voir tout » sur le VRAI poste de caisse | Propriétaire physique | Capture ou accord verbal sur l'écran du comptoir | `PROJECT_BRAIN.md §2` | OUVERTE |
| G2 | Correction D5 (KDS legacy) hors voie caisse | Propriétaire | Accord pour un commit hors-voie | message de commit | OUVERTE |
| G3 | D7 cadence de rafraîchissement (contrat de synchro partagé) | Propriétaire | Arbitrage 5 s vs 60 s + pause onglet caché | `PROJECT_BRAIN.md §4` | OUVERTE |
| G4 | Push vers `origin` | Propriétaire | Accord explicite (CLAUDE.md §3quater) | — | OUVERTE |

## §F — Règle finale
Livré = production-parfait, pas « presque ». Toute capture avec un libellé brut, une erreur
console, une ligne frozen touchée, ou un P0 non traité ⇒ REJET et boucle de soin.
