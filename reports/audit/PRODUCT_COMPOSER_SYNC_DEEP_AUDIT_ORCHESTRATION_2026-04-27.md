# Product Composer + Catalogue + Stock Sync - Deep Audit & Orchestration - 2026-04-27

AUDIT_MODE: PLAN_AND_ORCHESTRATION_ONLY  
TASK_ID: PRODUCT-COMPOSER-SYNC-MEGA-ORCH-2026-04-27  
VERDICT: READY_FOR_SEQUENCED_PLANNING_NOT_READY_FOR_DIRECT_MEGA_PATCH

## 1. Demande utilisateur archivee explicitement

Ce document archive les demandes qui etaient dispersees ou partiellement ignorees:

1. Audit technique de tous les produits, categories, supplements, variations, extras et addons.
2. Dashboard central dans la caisse pour ajouter/modifier/supprimer categorie, produit, prix, photo, offre, disponibilite et stock.
3. Stock partage caisse + borne: si rupture cote caisse/admin, rupture cote borne; si rupture borne, caisse doit savoir.
4. POS et borne partagent le meme catalogue et les memes categories, mais gardent des designs separes.
5. Ne pas casser le wizard POS existant; ne porter que les donnees/configuration.
6. Product composer type Shopify: construire un produit avec etapes configurables, choix, prix, photos, surfaces, stock.
7. Composer par type produit: assiette, sandwich, tacos, burger, salade, produit simple, menu/offre.
8. Les etapes doivent etre activables/desactivables: pain, viandes, crudites, sauces, supplements, menu/frites/boisson, addons.
9. Une assiette peut avoir crudites/sauces mais pas forcement pain; un sandwich peut avoir pain/sauce/crudites; chaque produit doit pouvoir override le preset.
10. Les choix doivent etre reutilisables et administrables sans modifier le code.
11. Les photos produit doivent etre modifiables depuis le dashboard, surtout pour la borne.
12. Les offres hebdomadaires/mensuelles doivent pouvoir etre creees comme produits/compositions exploitables.
13. Queue number sans doublons.
14. POS live board voit commandes POS + kiosk.
15. KDS/OSS suivent le cycle commande.
16. Paiement simulation valide.
17. Nettoyage FR/demo/Bangladesh sans casser l'historique.
18. Audit avant/apres chaque mission, puis audit global et handoff Claude.

## 2. Etat technique actuel observe

### Fondations deja presentes

- CRUD produit: `routes/api.php` expose `/admin/item`, `/admin/item/{id}`, `/admin/item/change-image/{item}`.
- Fiche produit: `resources/js/components/admin/items/ItemShowComponent.vue` contient onglets information, image, variations, extras, addons.
- Creation produit: `ItemCreateComponent.vue` gere nom, prix, categorie, taxe, image, type, upsell, status, description.
- Photos produit: upload deja implemente via `ItemController::changeImage()` et `ItemService::changeImage()`, avec broadcast `ItemAvailabilityChanged(type='full')`.
- Categories: `ItemCategoryRequest` supporte `wizard_template`, `has_menu`, `default_menu_kiosk`, `sauce_included_menu`.
- Attributs: `item_attributes` a `min_select`, `max_select`, `allow_repeat`; UI admin attributs expose deja ces champs.
- Variations: `ItemVariationCreateComponent.vue` cree choix par attribut avec prix additionnel, status, visibility `visible_on`.
- Extras: `ItemExtraCreateComponent.vue` cree extras avec prix additionnel, status, `group_label`, visibility `visible_on`.
- Addons: `ItemAddonCreateComponent.vue` relie un produit addon a un produit parent, avec choix de variations addon.
- Availability/86: `item_branch_availability` existe par `(item_id, branch_id)`, avec `is_available`, `max_daily_qty`, `daily_consumed_qty`, release idempotent via `order_items.released_qty`.
- MenuProjectionService existe, mais le commentaire du service indique encore que POS/kiosk ne sont pas totalement migres sur cette projection unique.

### Manques structurants

1. Le dashboard ne propose pas encore une experience "composer" unique; la configuration est dispersee entre produit, categorie, attributs, variations, extras, addons.
2. `wizard_template` est porte par la categorie, pas par le produit; impossible de faire proprement un produit exception sans nouvelle categorie ou heuristique.
3. Les etapes wizard ne sont pas des entites explicites. Les composants detectent encore des choix par noms/groupes: sauce, viande, supplement, etc.
4. Les choix ont un prix, mais pas un contrat central "step -> source -> choix -> stock target -> surfaces".
5. Les addons n'ont pas encore de role explicite `menu_drink`, `side`, `bundle`, `offer_component`; ils sont juste un lien item + variation JSON.
6. Le stock quantitatif reel n'est pas encore un SSOT general. `item_branch_availability` gere disponibilité et quota journalier, mais pas un stock atomique multi-stockable complet.
7. Les supplements/options ne sont pas couverts par un stock quantitatif general.
8. La synchronisation catalog create/update/delete repose encore sur events heterogenes; il faut un contrat `CatalogChanged` versionne.
9. Les tests E2E "admin cree produit compose -> POS le commande -> kiosk le commande -> stock rupture -> POS/kiosk voient" ne sont pas encore la preuve principale.

## 3. Decision architecture recommandee

### Principe

Ne pas remplacer les tables existantes. Les tables actuelles doivent rester les sources metier pour les choix et leurs prix:

- `items`: produit vendable.
- `item_categories`: preset categorie et affichage.
- `item_attributes`: groupes de variations avec contraintes min/max/repetition.
- `item_variations`: choix de type viande/pain/sauce avec prix additionnel.
- `item_extras`: choix supplement/garniture avec prix additionnel et `group_label`.
- `item_addons`: produit lie, utile pour boisson/menu/bundle.

Ajouter seulement une couche fine de composition pour supprimer les heuristiques:

- `item_wizard_profiles`: profil composer par produit.
- `item_wizard_steps`: ordre, visibilite, libelle, source de choix, contraintes override, surfaces.

Cette couche reference les choix existants mais ne porte pas les prix finaux. Le prix reste calcule par `PricingService`.

### Decision stock

Pour repondre a "produits + supplements + choix connectes au stock", le stock V2 doit etre generalisable:

- Recommande: `stock_levels` polymorphe/stockable (`stockable_type`, `stockable_id`) pour `item`, `variation`, `extra`, et plus tard `ingredient`.
- Alternative rapide: `stock_levels(item_id)` seulement. Cette alternative ne satisfait pas toute la demande utilisateur, car elle ne stocke pas les supplements/viandes/sauces.

Recommendation Codex: Option B `stockable_type/stockable_id`, gate humain `HG-STOCK-STOCKABLE-SCOPE`, implementation progressive item-first puis choices.

## 4. Plan d'execution cree

Plans:

- `plans/PLAN_PRODUCT_COMPOSER_SYNC_MASTER_2026-04-27.md`
- `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN1_AUDIT_SCHEMA_2026-04-27.md`
- `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN2_DASHBOARD_COMPOSER_2026-04-27.md`
- `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN3_PROJECTION_RUNTIME_2026-04-27.md`
- `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN4_STOCK_ORDER_SYNC_2026-04-27.md`
- `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN5_E2E_RELEASE_2026-04-27.md`

Mission briefs:

- `missions/PRODUCT-COMPOSER-SYNC-00-DEMAND-REGISTRY/`
- `missions/PRODUCT-COMPOSER-SYNC-01-SCHEMA-ADR/`
- `missions/PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER/`
- `missions/PRODUCT-COMPOSER-SYNC-03-PROJECTION-RUNTIME/`
- `missions/PRODUCT-COMPOSER-SYNC-04-STOCK-ORDER-SYNC/`
- `missions/PRODUCT-COMPOSER-SYNC-05-E2E-CLAUDE-HANDOFF/`

## 5. Ordre obligatoire

```text
00 Demand registry + baseline
01 Schema ADR + data contract
02 Dashboard composer
03 Projection runtime POS/Kiosk
04 Stock/order sync
05 E2E + Claude handoff
```

Ne pas inverser 04 avant 01: le stock des supplements depend du contrat `stockable`.
Ne pas inverser 03 avant 02: POS/Kiosk doivent consommer un payload stable, pas une UI incomplete.

## 6. Definition of Done globale

Le sujet est termine uniquement si:

- Un manager cree une categorie "Assiette test" depuis dashboard.
- Il cree un produit "Assiette Composee Test".
- Il ajoute une photo.
- Il choisit le preset assiette.
- Il active ou desactive les etapes: crudites, sauces, supplements, boisson, menu.
- Il ajoute des choix et prix supplementaires sans toucher au code.
- POS et kiosk voient le meme produit et les memes choix, avec designs separes.
- Le prix final POS/kiosk est identique et vient du backend.
- Une rupture stock sur produit ou choix bloque/affiche correctement POS + kiosk.
- Une commande POS et une commande kiosk passent en simulation.
- Le POS live board voit les commandes.
- KDS/OSS suivent le statut.
- Queue number reste unique.
- Claude peut auditer le rapport et reproduire les tests.

## 7. Gates humains

- `HG-COMPOSER-SCHEMA-ADR`: autorise `item_wizard_profiles` + `item_wizard_steps`.
- `HG-STOCK-STOCKABLE-SCOPE`: choisit stock item-only ou stockable polymorphe.
- `HG-FROZEN-ORDERSERVICE-UNLOCK`: requis avant decrement/release order.
- `HG-DASHBOARD-AUTHZ-CATALOG-OPS`: roles pouvant modifier catalogue/stock/composer.
- `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`: validation borne physique + caisse + KDS + impression.

## 8. Risques critiques

1. Copier les prix dans `item_wizard_steps` casserait le pricing SSOT.
2. Garder les heuristiques de noms pour sauce/viande rendrait le composer fragile.
3. Stock item-only ne repond pas a la demande supplements/choix stockables.
4. Patcher OrderService avant gate frozen risque d'abimer Train A/D-M13.
5. Faire un mega patch rendrait impossible l'audit Claude.

AUDIT_VERDICT: READY_FOR_SEQUENCED_EXECUTION_AFTER_HUMAN_GATES
