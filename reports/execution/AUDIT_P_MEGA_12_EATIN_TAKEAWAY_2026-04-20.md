# AUDIT P-MEGA-12 — Eat-in vs Takeaway (TVA + ticket)

**Date** : 2026-04-20
**Mode** : READONLY (Phase A du cycle W5)
**HEAD** : 781232fb4 (référence demandée ; fichiers non suivis type `posNormalizeIds.*` ignorés)
**Subagent** : `explore` (readonly very thorough)

## 0. Synthèse exécutive (5 lignes max)

Le **mode de consommation** est modélisé par `orders.order_type` (ex. **KIOSK=25** « sur place », **TAKEAWAY=10** « à emporter »), mais le **calcul fiscal SSOT** (`PricingService::calculateOrder`) **ne lit jamais `order_type`** : la TVA vient uniquement de `items.tax_id` → `taxes.tax_rate`. Donc **changer sur place / à emporter ne change pas la TVA**, ce qui est **non conforme** à une logique française multi-taux selon le mode. Côté ticket : le POS (`ReceiptComponent.vue`) **n'étiquette pas** `order_type=25` (clé absente du map i18n), et le **ticket borne** (HTML + `kioskPrinter.js`) **n'imprime pas** explicitement le mode ni un breakdown TVA obligatoire comparable au POS. Aucun test PHPUnit ne compare les **montants TVA** entre 25 et 10 pour un même panier.

## 1. État actuel du modèle données

- **`orders`** : colonne `order_type` `tinyInteger`, défaut historique `OrderType::DELIVERY` dans la migration de création (`database/migrations/2022_11_17_110810_create_orders_table.php` L30). Pas de colonne dédiée `is_takeaway` / `consumption_mode` en parallèle.
- **Énumération** : `App\Enums\OrderType` — constantes `DELIVERY=5`, `TAKEAWAY=10`, `POS=15`, `DINING_TABLE=20`, `KIOSK=25` (`app/Enums/OrderType.php` L7-L12).
- **`taxes`** : schéma sans discriminant `order_type` — `name`, `code`, `tax_rate`, `type`, etc. (`database/migrations/2022_11_17_110459_create_taxes_table.php` L16-27).
- **`items`** : `tax_id` FK vers `taxes` (`database/migrations/2022_11_17_110514_create_items_table.php` L22).
- **Snapshots fiscaux ligne** : `order_items` porte `tax_name`, `tax_rate`, `tax_type`, `tax_amount` (migration `database/migrations/2023_07_20_095843_add_tax_to_order_items_table.php` L17-20). Ces valeurs sont **remplies par le pricing** au moment de la commande, pas recalculées par `order_type` ensuite.

## 2. Flow UX kiosk + POS

**Kiosk**
- Choix **sur place / à emporter** dans le panier : barre radio, constantes **25 / 10** (`resources/js/components/frontend/kiosk/KioskCartComponent.vue` L81-110, L345-347, L422-424).
- Valeur par défaut **25** dans le store (`resources/js/store/modules/kioskCart.js` L58-59, L234-235).
- **Persisté** via `vuex-persistedstate` sur `kioskCart.orderType` (`resources/js/store/index.js` L237-241).
- Soumission paiement : `submitOrder({ paymentMethod, orderType })` (`resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` L285-287).
- **Pas de recalcul TVA côté client** quand on change le type : `selectOrderType` appelle seulement `setOrderType` (L421-424) ; les prix affichés restent basés sur le catalogue + promos, pas sur une matrice mode×TVA.

**POS**
- Sélection **Dine-in / Takeaway / Delivery** dans `PosComponent.vue` (radios + `checkoutProps.form.order_type`, ex. L116-154 d'après grep projet).
- Après impression, `PaymentComponent.vue` **réinitialise** `form.order_type` vers **TAKEAWAY** (commentaire BUG-A2 FIX, ~L237) — risque UX / erreur de saisie commande suivante.
- `resources/views/master.blade.php` (L172-184) force côté navigateur la sélection **takeaway** dans certains contextes — à noter pour cohérence avec le flux caisse.

**KDS**
- Quatre colonnes : `DINING_TABLE`, `DELIVERY`, `TAKEAWAY`, `KIOSK` (`resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` L611-622).
- Impression cuisine : libellés **Sur place / À emporter / Livraison / Borne** (`KitchenDisplaySystemComponent.vue` L727-731). Une commande **borne « à emporter »** est **TAKEAWAY (10)** et apparaît dans la colonne emporter, pas dans « Borne ».

## 3. Calcul TVA — analyse code

- **`PricingService::calculateOrder`** : pour chaque ligne, `tax_id` lu depuis **`Item`** en base, puis `tax_rate` depuis `Tax::get()` (`app/Services/Pricing/PricingService.php` L49-55, L171-182). **Aucune lecture de `order_type`**, **aucun paramètre** dans `PricingRequest` (`app/Services/Pricing/PricingRequest.php` L12-27, factories `forKiosk` / `forPos` L50-108).
- **Conséquence** : pour un même `item_id`, **KIOSK (25) et TAKEAWAY (10) produisent la même `tax_rate` ligne**, donc **pas de conformité** à une règle du type « 10 % sur place vs 5,5 % à emporter » sans duplication d'articles ou de taxes côté catalogue.
- **Chemins commande** : création kiosk passe par `FrontendOrderService` → `PricingRequest::forKiosk(...)` (`app/Services/FrontendOrderService.php` L211-220) ; POS via `PricingRequest::forPos` (`OrderService.php` ~L620). Même moteur, **même absence de `order_type`**.

## 4. NF525 ticket

- **API** : `OrderDetailsResource` expose `order_type`, montants, et **`tax_lines`** (groupement par `(tax_type, tax_rate, tax_name)` à partir des lignes) (`app/Http/Resources/OrderDetailsResource.php` L34-35, L62-65, L78-116). C'est la base **CGI / ticket** côté données.
- **Ticket POS (modal)** : `ReceiptComponent.vue` affiche `tax_lines` si présent (L118-128), et une ligne **Type de commande** via `enums.orderTypeEnumArray[order.order_type]` (L158). Or le map **ne contient pas `KIOSK` (25)** ni `POS` (15) — seulement `DELIVERY`, `TAKEAWAY`, `DINING_TABLE` (L223-227) → **libellé vide ou incorrect** pour les commandes 25/15.
- **Ticket kiosk HTML** : `KioskConfirmationComponent.vue` — **aucune** occurrence de `order_type` / mode consommation dans le template (recherche exhaustive : 0 match). Affichage file d'attente, lignes, total, fidélité — **pas de mention obligatoire « sur place / à emporter »**.
- **Ticket thermique ESC/POS** : `buildReceiptData` dans `resources/js/helpers/kioskPrinter.js` (L293-330) **ne prend pas** `order_type` ; structure = enseigne, date, lignes, sous-total, remise, total, paiement, fidélité — **pas de mode**, **pas de TVA par taux** sur le flux thermique.
- **Traductions PHP `lang/*/orderType.php`** : **pas d'entrée `OrderType::KIOSK`** (ex. `lang/en/orderType.php` L5-11 ; `lang/fr/orderType.php` L5-10) → tout PDF/admin utilisant `trans('orderType.'.$id)` pour 25 est **incomplet**.

## 5. Tests existants — couverture

- **`tests/Feature/Orders/*.php`** : **6 fichiers** référencent `order_type` (grep) — usage principalement **fixture** (`TAKEAWAY`, etc.), pas de test **différentiel TVA** 25 vs 10.
- **Sécurité / persistance type borne** : `tests/Feature/KioskSecurityTest.php` — **validité** des types 10/25 (pertinent pour l'intégrité du champ, pas pour la fiscalité).
- **TVA** : `tests/Feature/PosOrderTaxTest.php` — vérifie taxe > 0 depuis **DB item** avec `order_type` **TAKEAWAY** fixe (L37-103) ; **aucune** variante `DINING_TABLE` / `KIOSK`.
- **Breakdown ticket** : `tests/Feature/PosReceiptTaxLinesTest.php` — agrégation `tax_lines` avec `OrderType::POS` (L50-52) ; **ne couvre pas** eat-in vs takeaway.
- **Vitest** : `tests/js/KioskCartRestyle.spec.js` (sélecteur panier), `tests/js/kioskCartSendPayload.spec.js` (`order_type` dans payload), `tests/js/posNewOrderNotify.spec.js` (websocket / types) — **pas de test** « montant TVA change quand on bascule 25↔10 ».
- **Couverture % branches `order_type`** : **non mesurée** dans cet audit (nécessite exécution d'outil de couverture).

## 6. Dette technique identifiée (NIVEAU SÉVÉRITÉ)

| ID | Sévérité | Description | Fichier:ligne |
|----|----------|-------------|----------------|
| T1 | 🔴 | **TVA indépendante du mode** : `PricingService` n'utilise pas `order_type` ; risque fiscal direct FR. | `app/Services/Pricing/PricingService.php` L171-182 ; `app/Services/Pricing/PricingRequest.php` L12-27 |
| T2 | 🔴 | **Ticket POS** : libellés `orderTypeEnumArray` **sans KIOSK/POS** → mention mode incomplète sur reçu admin. | `resources/js/components/admin/pos/ReceiptComponent.vue` L221-227, L158 |
| T3 | 🔴 | **Ticket borne** : pas de mention mode consommation sur confirmation HTML ni sur `buildReceiptData` thermique. | `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` (template sans order_type) ; `resources/js/helpers/kioskPrinter.js` L293-330 |
| T4 | 🟡 | **i18n backend** : `lang/en/orderType.php` et `lang/fr/orderType.php` **sans clé 25** → PDF/exports utilisant `trans('orderType.'.$type)` incorrects pour borne. | `lang/en/orderType.php` L5-11 ; `lang/fr/orderType.php` L5-10 |
| T5 | 🟡 | **Reset POS** : après impression, forçage **TAKEAWAY** peut fausser la commande suivante. | `resources/js/components/admin/pos/PaymentComponent.vue` ~L237 |
| T6 | 🟡 | **KDS sémantique** : libellé **« Borne »** vs **« Sur place »** — correct pour traçabilité cuisine, mais **pas équivalent** à une mention fiscale « consommation sur place ». | `KitchenDisplaySystemComponent.vue` L727-731 |
| T7 | 🟢 | **Aucun event** `OrderTypeChanged` / recalcul idempotent côté domaine — changement de type **n'est pas** un événement métier dédié (recherche `OrderTypeChanged` : 0 résultat). | — |
| T8 | 🟢 | **`kioskFilter` store (W3.B)** : **aucune** référence à `order_type` (grep) — pas le levier actuel pour le mode conso. | `resources/js/store/modules/kioskFilter.js` |

## 7. Risques fiscaux concrets

- **Ticket** sans mention claire **sur place / à emporter** (borne thermique + PDF si `orderType` manquant en traduction) → **non-conformité** aux attentes NF525 / contrôle sur le mode de vente.
- **TVA** identique pour 25 et 10 alors que la loi distingue les taux selon le mode et la nature du produit → **erreur de collecte** (trop perçu ou pas assez) et **redressement** possible si contrôle croise caisse / déclarations.
- **Cohérence déclarative** : `tax_lines` reflète les **lignes persistées**, pas une règle légale multi-mode ; si le catalogue n'a pas été dupliqué manuellement par mode, les **agrégats Z** (`ZReportService` agrège par `tax_rate` des lignes) restent **internellement cohérents mais faux au regard du mode réel**.

## 8. Recommandations correctives (avec impact LOC + zones touchées)

1. **Introduire une règle métier** « `(item|catégorie) × order_type → tax_id ou taux` » dans **une couche unique** (extension `PricingService` + chargement règles ou tables dédiées) — **~200-400 LOC** touchant `app/Services/Pricing/`, éventuellement `database/migrations/`, `app/Models/Tax.php`, seeds/tests.
2. **Propager `order_type` dans `PricingRequest`** et tests PHPUnit **matrice** — **~80-150 LOC** `PricingRequest.php`, `PricingService.php`, appels dans `OrderService` / `FrontendOrderService`.
3. **Tickets** : compléter **tous** les maps `orderTypeEnumArray` + traductions PHP **KIOSK** ; ajouter champs **mode** + **tax_lines** sur ticket kiosk (HTML + `kioskPrinter.js`) — **~120-250 LOC** `resources/js/components/...`, `resources/js/helpers/kioskPrinter.js`, `lang/*`.
4. **Aligner reset POS** après impression avec le **comportement métier** souhaité (garder dernier type vs défaut TAKEAWAY) — **~10-30 LOC** `PaymentComponent.vue`.

## 9. Tests sentinelles à créer (avant fix)

- PHPUnit : même `item_id` + panier identique, **`order_type=25` vs `10`** → **`total_tax` et `order_items.*.tax_rate` diffèrent** selon la matrice attendue.
- PHPUnit : `OrderDetailsResource` / GET commande kiosk → **`tax_lines`** cohérents avec règle mode×article.
- PHPUnit : traduction / rendu PDF `trans('orderType.'.OrderType::KIOSK)` **non vide**.
- Vitest : changement **25↔10** dans le panier → **payload** `order_type` correct ; après fix **mock API** → total affiché aligné serveur (si preview existe).
- Régression : **ticket thermique** contient une ligne **mode** (snapshot `buildReceiptData`).

## 10. Décisions humaines requises (input pour GATE_BRIEF)

- **Matrice fiscale** exacte par catégorie produit (alcool, boisson, plat, sandwich) pour **sur place vs emporter** — validation **expert-comptable / fiscal FR**.
- **Modèle catalogue** : accepter-on **deux fiches article** (même produit, deux `tax_id`) vs **une table de règles** centralisée ?
- **NF525** : le texte légal attendu sur ticket **borne thermique** (FR seul vs bilingue) et **niveau de détail TVA** (lignes actuelles POS vs allégé kiosk).
- **KDS** : faut-il afficher **« Sur place »** pour `KIOSK` au lieu de **« Borne »** pour alignement client / fiscal ?

---

**Référence transverse** : l'audit `reports/execution/AUDIT_P_MEGA_23_DRIFT_ROOT_CAUSE_2026-04-20.md` documente le **drift admin ↔ kiosk** sur les resources / wizard ; pour P-MEGA-12, le parallèle est un **drift UX ↔ moteur fiscal** : l'UI expose bien 25/10 (`KioskCartComponent.vue`, store), mais le **SSOT prix** (`PricingService`) **ignore** ce signal — même famille de problème « front dit X, backend ne l'utilise pas pour le calcul légal ».
