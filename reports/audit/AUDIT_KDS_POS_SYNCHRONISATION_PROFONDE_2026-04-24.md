# Audit approfondi — écran cuisine (KDS) & synchronisation avec le POS

**Périmètre** : flux de données, cohérence d’affichage, parité POS↔KDS, temps réel, état/bump, risques cachés.  
**Méthode** : lecture du code (`KitchenDisplaySystemComponent.vue`, `KitchenDisplaySystemOrderService`, ressources API, stores `kitchenDisplaySystemOrder` / `kds`, `eventContract`, `OrderItemResource`) + tests `tests/Feature/*Kds*`, `tests/js/kds*`.  
**Date** : 2026-04-24

---

## Synthèse exécutive

| Axe | Évaluation | Commentaire |
|-----|------------|-------------|
| **Intégrité des données (branch, filtre, snapshot)** | **Bonne** | Filtre `branch_id` par `=` côté service, tests anti-fuite, `OrderItemResource` branche T07 (snapshot) |
| **Cohérence d’état (409, state machine, lock DB)** | **Bonne** | `changeStatus` avec `lockForUpdate` + comparaison `expectedFrom` ; `KdsChangeStatusConcurrencyTest` |
| **Synchronisation temps réel (Echo vs polling)** | **Bonne avec réserves** | Comportement **double** compte (branch : Echo + debounce ; admin `branch_id=0` : surtout polling) |
| **Cohérence “items board” (gauche) / commandes (droite)** | **Moyen** | Deux requêtes, deux agrégations ; risques de **décalage** d’inclusion statut (voir §5) |
| **Cohérence bump / prêt (PREPARED auto)** | **Moyen—risque** | État **localStorage** côté navigateur, pas serveur ; **auto-`PREPARED`** dès toutes les lignes bump — peut diverger d’intention opérationnelle |
| **Lisibilité & erreurs de préparation (viandes, “sans”)** | **À renforcer** | Renseignement présent mais **homogénéité** faible vs risque d’oubli (détaillé §6) |
| **Couverture de tests** | **Solide côté API** | Moins côté E2E KDS bout-en-bout (opt-in) |

**Verdict global** : le socle **backend + contrats API** est **robuste** (multi-tenant, concurrence, snapshots). La **surface “intelligence de cuisine”** (moins d’erreur humaine) repose surtout sur l’**UI actuelle** (lignes texte) — **pas** un défaut de synchronisation POS→DB, mais un **déficit d’acuité visuelle/structurelle** documenté dans d’autres plans (KDS design).

---

## 1. Cartographie POS → persistance → KDS

1. **POS** (`PosComponent` + `PosController` / `OrderService`) : création de commande avec `order_items` — snapshot **T07** (`composition_snapshot`, `allergens_snapshot` possible).  
2. **Base** : `orders` + `order_items` (statuts `ACCEPT` → `PREPARING` → `PREPARED` …).  
3. **KDS liste** : `GET admin/kds-order` → `KitchenDisplaySystemOrderService::list()` — filtre `branch_id` si `userBranchId > 0` ; **admin 0 = toutes branches** (décision explicite dans le code, risque d’encombrement, pas d’oubli technique de filtrage côté staff filiale).  
4. **KDS items (colonne gauche / items board)** : `GET admin/kds-order/items` → `orderItems()` — requête **séparée** ; fusion **côté serveur** de lignes identiques (groupement JSON sur item + variations + extras + instruction normalisée) pour **dédupliquer l’affichage agrégé**.  
5. **Mises à jour statut** : `POST admin/kds-order/change-status/{id}` — transaction + `OrderStateMachine` + `OrderStatusChanged` dispatch.  
6. **Front KDS** : `kitchenDisplaySystemOrder` stores les listes ; `kds` gère le **bump** en `localStorage` ; subscription **Echo** (si `authBranchId > 0`) sur `OrderStatusChanged`, `OrderCreated`, `ItemAvailabilityChanged` + **intervalle** 10s / 60s selon WS.

---

## 2. Ce qui est **validé** (fait, testé, ou fortement cadré)

### 2.1 Multi-tenant & filtres d’ID

- **Filtre `branch_id` / `order_type` en `=` (entier)** sur les colonnes ID — correction historique fuite `LIKE '%1%'` (voir `KdsBranchFilterExactTest`, commentaires P51 dans le service).  
- **Test** `test_chef_kds_does_not_leak_other_branch_orders` (via `BranchIsolationTest`) et logique 403/409 sur conflit d’opérateur (voir `KdsChangeStatusConcurrencyTest`).

### 2.2 Concurrence & “double écran”

- **Lock pessimiste** + **409** si l’état a changé entre l’écran KDS et le POST (état *stale*). C’est le bon pattern pour 2 onglets ou 2 postes.  
- **Gestion 409 côté store** : `changeStatus` recharge la liste (effet de resynchronisation).

### 2.3 Snapshots (T07) & allergènes

- `OrderItemResource` : priorité `composition_snapshot` / `allergens_snapshot` pour **variations** et **extras** ; colonnes legacy en repli.  
- **Test** `KDSAllergenVisibilityTest` : l’API KDS **expose** `allergens_snapshot` par item — *condition : données présentes côté ligne*.

### 2.4 Avancé / plage de dates (commandes “zombie”)

- `list()` et `orderItems()` : logique unifiée **avance** (≤ aujourd’hui, non livrée/annulée) pour éviter disparition au-delà de 24 h (bug historique `Carbon::yesterday` corrigé, commenté en code).

### 2.5 Station cuisine (`kds_station`)

- `normalizeKdsStation` / `filterOrdersByStation` en **JS pur** (`kdsDisplay.js`) — testé en Vitest (`kdsStationFilter.spec.js`).

### 2.6 Escalade d’attente (SLA ressenti)

- `getKdsEscalationClass` (vert / orange / rouge + pulse) + tick 30s ; cohérent “psychologie d’attente”.

### 2.7 Rafraîchissement antispam

- `OrderStatusChanged` + `ItemAvailabilityChanged` → handler **debouncé 300ms** (évite triple appel).  
- **Perte WS** : bannière + **polling 10s** (vs 60s si connecté) — repli explicite.

### 2.8 POS ↔ rappel commandes borne (lien opérationnel, pas l’écran KDS classique)

- `PosComponent` : appels `admin/kds-order` + `change-status` vers **DELIVERED** sur volet encaissement kiosque — c’est un **pont** POS/KDS côté API. Couverture utile en charge “front caisse + cuisine” (tests indirects E2E existants hétérogènes).

---

## 3. Problèmes & zones **douteuses** (synchronisation, logique, ops)

### 3.1 **Zombie / hors liste : limite 50 commandes**

- `list()` tronque à **50** commandes actives. Surcharge extrême = **ordres ABSENTS** du flux visuel (pas un bug de “sync” mais de **product cap**). *Mitigation* : opérations, tri, ou alerte d’arrière-plan (non couvert ici).  
- **Niveau de risque** : moyen en heure de pointe sur site très chargé + admin multi-branche (densité d’exposition accrue).

### 3.2 **Admin `branch_id = 0` : Echo désactivé**

- `subscribeEcho` **ne s’abonne pas** si `branchId <= 0` — repli **polling 60s** (et refresh manuel). Sous-charge, un admin central peut **voir l’ordre moins tôt** qu’un opé de succursale (1 min vs instantané).  
- **Caché** : souvent on croit “pas de WebSocket = bug” — c’est un choix d’**implémentation** (éviter canaux mélangeant toutes les branches) avec **déséquivalent de fraîcheur**.

### 3.3 **Divergence `list` vs `orderItems` (statuts inclus)**

- `list()` : `ACCEPT`, `PREPARING`, `PREPARED` (dans l’espace requête date).  
- `orderItems()` : seulement `ACCEPT` et `PREPARING` pour le **plancher gauche** agrégé.  
- Conséquence : un ordre en **`PREPARED` reste** dans la **grille de droite** (si encore dans le jeu de `list` avant passage suivant) mais **disparaît** de la “fusion” d’items du plancher — c’est partiellement **voulu** (items board = “en cours de fabrication”), mais peut **désorienter** si l’on attend une **unicité d’inventaire** des lignes *partout*.

### 3.4 **Groupement agressif sur `orderItems`**

- Clé = `item_id` + hash variations/extras + `instruction` lowercé. Deux plats *différents* avec même composition mais **deux fautes de frappe d’instruction** légèrement différentes → **2 lignes** (OK). Problème inverse : **fusion involontaire** si deux consignes distinctes se normalisent de la **même façon** (rare) — *risque théorique* faible.

### 3.5 ** Bump & auto `PREPARED`**

- `bump` + `isReadyOrder` (tout bump) → `orderStatus(..., PREPARED)` **dans le même client**.  
- **Hors consistance serveur** : d’abord, la ligne est **marquée locale** ; l’**état** **PREPARED** n’est fiable qu’**après** le POST. En cas d’**échec** réseau, la carte UI peut ne pas refléter l’écart (selon rechargement).  
- `localStorage` (bump) n’est **pas** synchronisé entre appareils — *deux postes KDS* affichent des “prêts” **différents** sur la **même** commande. **Très caché** : source majeure de “je l’avais sorti, pas lui” en cuisine multi-écrans.  
- **Fenêtre recall 60s** (pure client) : correct pour anti-abus, mais pas de miroir serveur.

### 3.6 **Dépendance au rechargement / Echo**

- Si l’**event** `ItemAvailabilityChanged` arrive mais que le **métier** ne pousse pas, la cuisine voit l’**ancien** contenu (rupture) jusqu’au **refresh** (debouncé) — moins pire qu’un écran jamais mis à jour, mais *latence* possible.

### 3.7 **Cohérence d’`item_id` en `kds` bump**

- Le **bump** utilise `item.id` = **ligne** `order_items` (via affichage). C’est cohérent avec l’**agrégation côté items** qui conserve la **première** ligne d’un groupe — *si* un jour l’**items board** montre une forme unifiée différente de l’idétail par ligne à droite, l’**identité** des lignes bumpées peut **ne plus s’aligner** (risque d’**architecture d’écran** à surveiller, pas prouvé comme bug aujourd’hui).

### 3.8 **Fiscal / reçu / KDS**

- KDS n’est **pas** l’impression ticket client ; l’enjeu ici = **opération** — *pas* de fuite fiscale sur l’écran, mais *pas* de *double validation* ticket vs cuisine non traitée ici (hors sujet, mais mention *indirect* pour éviter mélange des responsabilités en audit).

### 3.9 **G-3 (parité recul variation)** — *rappel d’histoire*

- *Parité* **POS** vs Kiosk sur **rappel** d’**indisponibilité** au niveau **variation** a été un **gap** document (méga-checklist) ; le **KDS** **affiche** ce qu’on lui envoie — *si* la **source** a une variation floue, l’**erreur** n’est **pas** corrigée en **affichage seul*.

### 3.10 **Désalignement d’`item.id` (resource)**

- L’API expose parfois `id` côté resource comme l’`orderItem` (voir `item_id` dans `OrderItemResource` — noms mélangeants). Les tests utilisent le bon id pour bump ; toute **régression d’**étiquetage* dans le template pourrait cibler la mauvaise clé. **C’est un risque d’*indirect* (lisibilité du modèle) au-delà d’un *sync bug*.**

---

## 4. Synchronisation **détaillée** (check-list)

| Nœud | Mécanisme | Fiable ? | Gaps / détails cachés |
|------|------------|----------|------------------------|
| **Création commande (POS) → DB** | Order service / transactions | Oui (hors sujet détail) | Délai jusqu’à `ACCEPT` = visible en KDS selon workflow caisse. |
| **KDS ↔ API liste** | GET + filtre + limite 50 | Oui avec cap | Surcharge = *blind spot*. |
| **KDS items board** | GET séparé + groupBy | Oui, logique propre | Statut ≠ `list` (voir 3.3) ; pas “une seule source de vérité” côté écran. |
| **Changement de statut** | POST + 409 + SM | Oui | Erreur UX si message pas affiché clairement (côté UI) — à valider. |
| **Event broadcast** | Echo 3 event types | Oui, branch>0 | **Admin 0 = lent** — voir 3.2. |
| **Perte réseau** | Polling 10s + `ConnectionStatusBanner` | Oui, rafraîchissement périodique | Bruit/flash si 10s = pic charge API. |
| **86 / rupture** | Event → refresh | Partiel | Pas de *badge* explicite par article dans le template (refresh brut). |
| **Bump** | `localStorage` + recall 60s | Cohérent *localement* | **Non synchronisé multi-écrans** — *gap structurel* majeur si 2+ KDS. |

---

## 5. Écran vs **intelligence** (où l’on perd le “fil” côtation humain)

*Ce n’est **pas** listé en “bug P0 de sync” : ce sont des **pénibilités d’exploitation** recoupées par nos échanges (sans oignon, viande, suppléments) :*

- **Variations** affichées en `variation_name: name` sur une seule **ligne** parfois longue, **sans** distorsion **typographique** pour retraits/ajouts.  
- **Consigne libre** (`instruction`) = même poids visuel qu’un *extra* (petit gris) — *erreur de préparation* si la consigne est l’enjeu n°1.  
- **`allergens_snapshot` présent en B/E** mais l’**UI** ne l’isole pas de façon **systématique** (selon champs) — l’*audit* API ≠ *audit* cognitif.

---

## 6. Recommandations **priorisées** (hors exécution ici)

1. **P0 — Cohérence opération** : décider si le **bump** doit devenir un **enregistrement côté serveur** (ou reprise du serveur) pour *multi-écrans* ; *sinon* documenter “**un seul poste KDS actif** par cuisine”.  
2. **P0 — Surcharge** : *alerte* / tri quand on approche la **limite 50** (même simple log admin).  
3. **P1 — UI sémantique** : appliquer le *méga plan* `PLAN_MEGA_KDS_…` (légende, retraits, protéine en tête) — c’est là que la **dette “erreur humain”** se règle.  
4. **P1 — Admin 0** : bannière *“Rafraîchissement 60s — recharger manuel”* explicite pour éviter faux tickets support.  
5. **P2 — Tests** : E2E scénario **2 onglets KDS** (démontrer *décalage bump* si on veut prouver le **gap**).

---

## 7. Renvoi de couverture **tests** (rappel)

- **API / lock / filtre** : couvert.  
- **Cognition / couleur** : *non* — besoin d’*tests* manuels d’*usage* (et accessibilité couleur, daltonisme).  
- **E2E** : *partiel* (`tests/e2e/04-kds-status.spec.js` + specs POS — pas d’*audit* automatisé *complet* de “toute la soirée service” en CI).

---

## 8. Conclusion d’**audit d’intelligence** (cinq phrases)

1. **La synchronisation POS–serveur–KDS (données) est, dans l’ensemble, saine** : le code montre un **niveau de maturité** (locks, 409, snapshots, filtre d’entier) rarement trivial en *legacy* POS.  
2. Les **failles** les **plus pénalisantes** pour la *cuisine* ne sont **pas** des *bugs de réplication SQL*, mais des **découpages** d’*affichage* (deux requêtes) et d’*état local* (**bump**), **cachés** quand l’on scale à *plus d’un* écran ou *plus de 50* commandes.  
3. **L’administrateur “toutes branches”** vit une *fraîcheur* moindre — c’est *structural*, *pas* un hasard.  
4. L’*intelligence visuelle* (sanctions / viandes) est **largement une dette d’*interface***, indépendante d’un *rate limit* d’API.  
5. **Prochaine itération haute valeur** : **(a)** gouvernance *multi-KDS* du bump, **(b)** cap *50* visible, **(c)** design *instruction vs allergène vs suppléments* (plan dédié), **(d)** E2E ciblé.

*Fin du rapport. Pour suivi, créer un `RUN_*` si une *phase* de correction est planifiée.*
