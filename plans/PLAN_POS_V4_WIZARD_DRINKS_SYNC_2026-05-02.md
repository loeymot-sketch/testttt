# PLAN — POS wizard : liste de boissons synchronisée borne ↔ caisse

| Champ | Valeur |
| --- | --- |
| **TASK_ID** | `POS-V4-WIZARD-DRINKS-SYNC-2026-05-02` |
| **PRIMARY_EXECUTION_MODEL** | À choisir — voir `EXECUTION_OPTIONS` |
| **REASONING_EFFORT** | medium |
| **PLAN_REVIEW** | Requis avant EXECUTE (PRIMARY_EXECUTION_MODEL = Codex si choisie l'option B) |
| **SUBSYSTEMS_TOUCHED** | `public/js/pos-wizard.js` (lecture catalogue + render boisson dans single-page), `resources/js/components/admin/pos/ItemComponent.vue` (passer `categories` + `items` du store au modal via attribut DOM ou prop) |
| **SUBSYSTEMS_OFF_LIMITS** | Backend pricing (CartCheckoutService, OrderService), `pos-wizard.js` flux étapes hors single-page, kiosk wizard, NF525, dispatch / events |
| **GATE_CONDITIONS** | Soft gate si l'option B (nouveau endpoint backend) est retenue → vue + test API requis |
| **INVARIANTS_AT_RISK** | **(I.1) Backend pricing SSOT** — la boisson incluse doit avoir `price = 0` côté backend (recalcul) ; **(I.3) branch_id** — la liste doit être filtrée par branche ; **(I.5) Symétrie kiosk/POS** — éviter qu'un Coca apparaisse côté POS et soit absent côté kiosk pour la même branche |

## 1. Diagnostic — état observé

### Côté kiosk (référence, à copier)
- `KioskWizardComponent.vue#kioskMenuDrinkChoiceAvailable` (~ligne 929) lit le store **`kioskMenu/categories`** + **`kioskMenu/allItems`** (alimenté par `GET /api/frontend/menu`).
- Identification d'une boisson : catégorie dont `name`/`slug` matche `/\b(boisson|boissons|drink|drinks|soda|sodas|beverage|beverages)\b/i`.
- Liste reconstruite côté front à chaque rendu — invalidation pilotée par `kioskMenu` (cache backend 60 s).

### Côté POS (cassé / incomplet)
- `pos-wizard.js#renderSinglePage` (lignes 2620+) : aucun appel à `renderBoissonChoiceStep`. La sélection menu / boisson seule **ne déclenche aucune liste de boissons** dans la vue mono-page.
- Le legacy multi-steps (`renderBoissonChoiceStep`, ligne 1323) construit la liste depuis **`item.addons`** filtré par heuristique nominative (`coca|fanta|sprite|...`). Sources : `addonItems = lastItemData.addons`.
- Conséquences :
  1. Si l'admin ajoute une boisson nouvelle (ex. « Vittel ») non listée dans l'heuristique → invisible POS.
  2. Si la boisson n'est pas dans `item.addons`, elle n'apparaît jamais — **désynchronisé du catalogue global** que voit la borne.

### Backend
- Pas d'endpoint dédié « drinks catalog ».
- POS charge déjà `posCategory/lists` (catégories filtrées surface=pos) et `item/lists` (items filtrés surface=pos, `branch_id` scoped) via `PosComponent.vue`.

## 2. Goal

Quand le wizard POS expose **Menu Complet** ou **Boisson Seule**, afficher la **liste de boissons disponibles** :
1. Source = même catalogue que le kiosk (catégorie de type « boisson/drink/soda/beverage » filtrée par `branch_id`).
2. Sélection envoyée au panier comme `pos_line_addons` avec `item_id` réel et **prix 0** (incluse dans la formule, prix recalculé backend).
3. Pas de saisie nominative côté code — toute boisson ajoutée en back-office apparaît automatiquement.
4. Pas de prix unitaire affiché à côté des boissons (juste « Incluse » ou « + cf. formule » selon décision UX).

## 3. Options d'exécution

### Option A — Lecture côté front depuis les stores POS déjà chargés (recommandée)
- **Données** : `posCategory/lists` (catégories) + `item/lists` (items POS-surface filtrés `branch_id`) déjà en mémoire dans `PosComponent.vue`.
- **Pont Vue → wizard** : `ItemComponent.vue` écrit la liste de boissons disponibles dans un attribut DOM (`data-pos-drinks-catalog`, JSON sérialisé) ou via une prop sur `#item-variation-modal` au moment où le wizard ouvre le modal. `pos-wizard.js` lit cet attribut dans `interceptModal`.
- **Filtre** : même regex que `kioskMenuDrinkChoiceAvailable` (catégorie name/slug) → garantit la **symétrie kiosk ↔ POS**.
- **Synchro** : `item/lists` est déjà rafraîchi par l'event Echo `CatalogChanged` (déjà câblé dans `PosComponent`). Aucune nouvelle plomberie temps-réel.
- **Effort** : ~80–120 lignes.
- **Risques I** : aucun nouveau ; respecte (I.1)/(I.3)/(I.5).
- **PRIMARY_EXECUTION_MODEL** : Cursor Composer (frontend pur, pas de logique pricing).

### Option B — Endpoint backend dédié `GET /api/admin/pos/wizard-drinks-catalog`
- **Données** : nouvel endpoint, réutilise `KioskMenuService` (ou un `PosWizardCatalogService`) pour renvoyer la liste de boissons normalisée.
- **Avantages** : cache contrôlable, logique métier extensible (exclure familles, rangée d'affichage, etc.).
- **Coût** : controller + resource + tests Feature + cache invalidation + doc.
- **Effort** : ~3–4× option A.
- **PRIMARY_EXECUTION_MODEL** : Codex `codex-extension` (backend Laravel + frontend), `model_reasoning_effort=xhigh`.
- **Quand** : si on prévoit un 3ᵉ surface (web/app) ou des règles métier complexes — pas pour Caisse V1 J0.

### Option C — Reproduire la lecture `kioskMenu` côté POS
- Charger `GET /api/frontend/menu` depuis le POS aussi.
- **Risque** : route protégée par `auth:sanctum` + `kiosk:order` ability — POS n'a pas cet ability. Réécrire les middleware = surface d'attaque.
- **Rejeté.**

## 4. Checklist invariants (à valider avant CLOSE)

- [ ] (I.1) Backend pricing SSOT : la boisson choisie est ajoutée comme bundled addon `price = 0` ; backend `CartCheckoutService` recalcule le total — **aucun calcul prix côté JS**.
- [ ] (I.3) branch_id : `item/lists` POS est déjà filtré par `branch_id` (props.search.branch_id) → la liste de boissons hérite du filtre. À confirmer test E2E.
- [ ] (I.5) Symétrie : la même expression régulière (`/\b(boisson|boissons|drink|drinks|soda|sodas|beverage|beverages)\b/i`) doit identifier les catégories de boissons sur les **deux** surfaces. Si on touche kiosk plus tard, on synchronise.

## 5. Test plan

- **Vitest** : nouveau spec `tests/js/posWizardDrinksSync.spec.js` (mock catégories + items, assert `data-pos-drinks-catalog` populated, vide si aucune boisson).
- **Lecture côté wizard** : test JS unitaire sur la fonction d'extraction (renvoyer un tableau d'items « boissons » à partir de catégories + items).
- **E2E manuel** : ajouter une boisson au back-office → POS sans rechargement (Echo `CatalogChanged`) doit la voir → wizard menu complet → la nouvelle boisson est sélectionnable → checkout passe avec prix correct (vérifier ticket cuisine + total).
- **Ne pas casser** : `tacos-4-viandes-cash-flow.spec.ts`, `pos-receipt-kds-instruction-sync.spec.js`.

## 6. Décisions à prendre par le humain (gates de planification)

1. **Option A vs B vs C ?** Recommandation : **A** pour Caisse V1, B en backlog si besoin futur.
2. **Scope « Boisson Seule »** : doit-on garder cette formule sur sandwich/burger/tacos ? Aujourd'hui désactivée par allowlist hardcoded (`['sandwich','burger','tacos']` exclus). Si le user veut l'activer, scope élargi.
3. **Affichage prix boisson** : on affiche « Incluse » (formule menu) ou rien ? Standard : « Incluse ».
4. **Boisson par défaut** : auto-sélectionner la 1ʳᵉ boisson ou exiger un choix explicite ? Standard kiosk : choix obligatoire (`hasBoissonSelected` retourne `false` tant que rien n'est choisi).

## 7. Suite proposée

1. **Humain** : valide l'**option A** + répond aux 4 questions §6.
2. **Claude** (orchestrateur) : finalise le plan, route vers l'EXECUTE.
3. **Composer** (option A) ou **Codex** (option B) : implémente.
4. **VALIDATE** : Vitest + lint + 1 build Mix + spot-check manuel POS.
5. **AUDIT** : Claude relit (terminal `claude` ou fallback Cursor) — checklist invariants §4.
6. **CLOSE** : `EXECUTE_DELEGATION` + `AUDIT_VERDICT: PASS` → archive.

## 8. Hors scope (intouchable sur ce cycle)

- Toute logique de pricing.
- Le design / fonctionnalités du wizard (formules, viandes, sauces, supplements) — protégé par instruction utilisateur (« je l'ai fait manuellement »).
- Wizard kiosk (`KioskWizardComponent.vue`) — référence, pas modifié.
- NF525 / dispatch / OrderService.
