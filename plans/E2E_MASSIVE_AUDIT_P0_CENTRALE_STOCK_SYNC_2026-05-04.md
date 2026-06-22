# P0 — Centrale + synchronisation « stock » (produits, compositions, ruptures) — E2E massif — 2026-05-04

| Champ | Valeur |
| --- | --- |
| **TAG plan** | `P0` |
| **Surface principale** | Admin (backend / « centrale ») |
| **Dépend de** | Environnement : `php artisan serve` + front compilé ; DB seed minimale ; compte admin + branche active. |
| **Bloque** | P1–P5 (les surfaces consommatrices supposent un catalogue et des niveaux de stock cohérents). |
| **Test strategy** | `playwright-full-e2e` + captures ; API/DB en option pour **preuve d’intégrité** (comparaison `stock_levels`, `item_attributes.is_available`). |

## 1. Périmètre fonctionnel (ce que ce plan couvre)

### 1.1 Stock « produit fini »

- Article **actif / inactif** ; visibilité surface (si applicable en V1).
- **Disponibilité article** (rupture « sandwich entier », libellé métier type DIVIDEEZ / message rupture selon config).
- Impact sur **projections menu** (liste catégories / items côté admin preview si présent).

### 1.2 Stock « variation » (taille, viande, etc.)

- Variation liée à un **attribut** ; quantités **stockables** (`stock_levels` sur `ItemVariation` quand le modèle le prévoit).
- Rupture **stock numérique** (on_hand = 0) vs rupture **ingrédient** (attribut indisponible — priorité métier documentée dans les tests PHPUnit récents).

### 1.3 Stock « composition » — suppléments, sauces, crudités, extras

- **Extras** (prix, statut, visibilité surface).
- **Addons** (article lié, disponibilité).
- Cohérence **admin → resolver** : ce qui est indisponible côté attribut ingrédient doit remonter comme **non commandable** au pricing (déjà couvert backend ; ici on vérifie **UI admin** + **préparation données** pour les plans P1/P2).

### 1.4 Gestion V1 « simple » catégories / articles

- CRUD **minimal** catégorie V1 (sans parcours wizard V2 complexe).
- Position / tri basique ; pas d’exigence « refonte catalogue V2 ».

### 1.5 Hors périmètre explicite (déporter vers V2 ou plans séparés)

- Refonte **Catalog Studio / wizard** complet hors sentinels déjà couverts.
- Modification **NF525 / PaymentComponent** (zones sensibles — gate si touché).

## 2. Matrice de données recommandée (jeu « référence »)

Créer **une branche dédiée** `BR_E2E_STOCK` (ID noté dans le manifeste) et **5 articles** :

| Code | Type | Variations | Extras | Addon | Stock variation | Attribut ingrédient |
| --- | --- | --- | --- | --- | --- | --- |
| `A_SANDWICH` | Produit menu | 2 (ex. S / L) | 1 sauce | 1 boisson addon | S:10 L:0 | — |
| `B_TACOS` | Menu composer | viande A/B | crudités | — | A:0 B:5 | Viande A liée ingrédient X |
| `C_SIMPLE` | Plat simple | — | — | — | — | — |
| `D_EXTRA_ONLY` | Produit + extra obligatoire | — | 2 extras | — | — | — |
| `E_GLOBAL_OFF` | Rupture globale | — | — | — | — | `is_available=0` sur article |

Documenter les **IDs** réels dans `manifest.md` après création (script seed ou UI).

## 3. Phases et étapes (STEP_ID + capture + vérifications)

Convention capture : voir maître [`E2E_MASSIVE_AUDIT_MASTER_2026-05-04.md`](./E2E_MASSIVE_AUDIT_MASTER_2026-05-04.md).

### Phase C — Connexion & garde-fous

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S01` | Login admin, choisir branche `BR_E2E_STOCK` | `P0__S01__admin__dashboard_branch.png` | URL dashboard admin ; pas d’erreur console | P1 |
| `S02` | Onglet / route **Articles** (liste) | `P0__S02__admin__items_list.png` | Au moins N articles seedés visibles | P1 |
| `S03` | Ouvrir **Ingrédients** (liste + recherche) | `P0__S03__admin__ingredients_list.png` | Liste charge ; permission OK | P1 |

### Phase I — Ingrédients & propagation (préparation sync P5)

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S10` | Filtrer / localiser ingrédient `X` lié à `B_TACOS` | `P0__S10__admin__ingredient_detail.png` | Fiche visible ; `branch` cohérent | P2 |
| `S11` | **Toggle** disponibilité ingrédient (rupture) | `P0__S11__admin__ingredient_after_toggle.png` | Toast / état UI ; **note heure** pour corréler avec P1/P2 | P0 si API 403 |
| `S12` | Revenir liste : badge / libellé rupture | `P0__S12__admin__ingredients_list_after.png` | Cohérence liste | P2 |

### Phase V — Variations & stock numérique

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S20` | Éditer `A_SANDWICH` → onglet variations | `P0__S20__admin__item_variations.png` | 2 variations visibles | P1 |
| `S21` | Mettre **L** stock à 0 (ou via écran stock dédié) | `P0__S21__admin__variation_L_zero.png` | Sauvegarde OK | P0 |
| `S22` | Vérifier **S** stock > 0 | `P0__S22__admin__variation_S_ok.png` | Valeur persistée (refresh page) | P0 |

### Phase E — Extras / sauces / crudités

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S30` | Éditer article avec extras ; liste extras | `P0__S30__admin__extras_list.png` | Prix affichés **lecture serveur** (pas recalcul front) | P2 |
| `S31` | Désactiver un extra « sauce » | `P0__S31__admin__extra_disabled.png` | Extra inactif persisté | P1 |

### Phase A — Addons

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S40` | Vérifier addon lié à item actif | `P0__S40__admin__addon_active.png` | Addon item actif | P1 |
| `S41` | Mettre **addon item** en rupture / inactif | `P0__S41__admin__addon_unavailable.png` | Sauvegarde | P0 |

### Phase R — Rupture « produit entier »

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S50` | `E_GLOBAL_OFF` : basculer disponibilité article | `P0__S50__admin__item_global_rupture.png` | Message métier cohérent (DIVIDEEZ / custom) | P1 |
| `S51` | Refresh liste catégories : carte grisée ou badge | `P0__S51__admin__item_list_rupture.png` | UI cohérente | P2 |

### Phase G — Catégories V1 (simple)

| STEP_ID | Action | Capture | Vérification double | Gravité si échec |
| --- | --- | --- | --- | --- |
| `S60` | Liste catégories V1 | `P0__S60__admin__categories_list.png` | Tri visible | P3 |
| `S61` | Création catégorie test `CAT_TMP` | `P0__S61__admin__category_create.png` | Créée ; pas d’erreur | P2 |
| `S62` | Suppression ou désactivation `CAT_TMP` | `P0__S62__admin__category_cleanup.png` | Nettoyé | P2 |

## 4. Preuves « sync » attendues (sans encore POS/Kiosk — préparation)

| Événement admin | Attendu technique (documenter oui/non au run) |
| --- | --- |
| Toggle ingrédient | Job/event **après commit** ; broadcast `ItemAvailabilityChanged` ou équivalent ; outbox si présent. |
| Stock variation | Recalcul disponibilité dans resolver ; cache menu invalidé côté clients au prochain fetch. |

Pour **preuve sans navigateur consommateur** : option `curl` API menu POS/kiosk + JSON sauvé dans `reports/e2e-massive/.../api/` (étape optionnelle mais **P4** utile).

## 5. Rapport & anomalies

À la fin du run P0, produire `RAPPORT_P0.md` avec :

1. Tableau **STEP_ID → verdict** (PASS / FAIL / SKIP).
2. Liste **P0–P4** des anomalies avec lien capture.
3. **Symétrie** : si un bug touche `OrderService`, noter revue `FrontendOrderService` (invariant I5).

## 6. Mapping specs Playwright à étendre (implémentation future)

| Spec existante | Extension proposée |
| --- | --- |
| `critical-flow/v1-ingredient-rupture-propagation.spec.js` | Ajouter chemin **admin toggle** + assertion API menu. |
| `tests/Playwright/pos-receives-kiosk-realtime.spec.js` | Réutiliser patterns attente broadcast pour **post P0**. |

---

*Plan P0 — création documentaire uniquement ; aucun test exécuté ici.*
