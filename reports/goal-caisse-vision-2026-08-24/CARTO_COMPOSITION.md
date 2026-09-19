# CARTO_COMPOSITION — chaîne de composition d'une commande
Spécialiste COMPOSITION (lecture seule) — 2026-08-24 — worktree `goal-caisse-vision-2026-08-24`

Objectif métier : un caissier sans le nom du client doit lire, en français, TOUT ce qui a été
commandé. Ce document cartographie où vit la composition, qui la normalise, qui la rend.

---

## 1. Schéma de stockage

Tout vit sur **`order_items`** (une ligne = un produit vendu).

| Morceau | Colonne | Type / forme réelle | Preuve |
|---|---|---|---|
| Produit | `item_id` → `items.name` | FK | `database/migrations/2022_11_17_110832_create_order_items_table.php:20` |
| Quantité | `quantity` | integer | idem `:21` |
| Variations (legacy) | `item_variations` | `longText` JSON, cast `string` | migration `:25` ; `app/Models/OrderItem.php:100` |
| Extras (legacy) | `item_extras` | `longText` JSON, cast `string` | migration `:26` ; `OrderItem.php:101` |
| **Composition NF525** | `composition_snapshot` | `json` nullable, cast `array` | `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php:11` ; `OrderItem.php:102` |
| Instruction libre | `instruction` | `text` nullable (**NULL, pas `''`**) | migration `:30` |
| Allergènes | `allergens_snapshot` | json cast `array` | `2026_04_18_140004_add_allergens_snapshot_to_order_items.php` |

**Forme exacte du snapshot** — `app/Services/Pricing/CompositionSnapshotBuilder.php:181-187` :

```
{schema_version:1, captured_at, lines:[…], extras:[…], addons:[…]}
lines[]  : {variation_id, attribute_id, attribute_name, variation_name, quantity, unit_price, line_total}  (:71-79)
extras[] : {extra_id, extra_name, quantity, unit_price, line_total}                                        (:108-114)
addons[] : {addon_id, addon_item_id, addon_name, role, quantity, unit_price, line_total, catalog_price}    (:166-177)
```

Immutabilité : hook `OrderItem::updating` (`app/Models/OrderItem.php:50-58`) + trigger DB
(`2026_05_24_040211_add_composition_snapshot_immutability_trigger.php`).

**Cinquième forme, hors base** : `cart_display` (panier vivant caisse), chaîne « Groupe: valeurs »
produite par `buildCartDisplay()` dans `public/js/pos-wizard.js` (FROZEN §7), reformatée par
`resources/js/helpers/posCartCompactDisplay.js:1-24`. Et l'`instruction` est composée par
`buildTicketInstruction` (pos-wizard.js ~l.3984), documentée dans
`resources/js/helpers/posWizardInstruction.js:1-23`.

---

## 2. Normaliseurs canoniques

Il n'y en a **pas un mais trois**, non interchangeables :

**(a) Ticket / caisse — `resources/js/helpers/posReceiptBuilder.js`**
- `normalizeReceiptVariations(rawVariations)` → `[{label, name, quantity}]` — `:164`
- `normalizeReceiptExtras(rawExtras)` → `[{name, quantity, line_total}]` — `:198`
- `normalizeReceiptAddons(rawAddons)` → `[{name, quantity, line_total}]` — `:244`
- `receiptInstructionForPrint(item)` → dédoublonne l'instruction — `:275`

Les DEUX formes réconciliées sont citées dans son propre en-tête (`:146-163`) :
> « LEGACY (pre-T07) — `[{id, variation: {variation_name, name}}]` … • SNAPSHOT (post-T07) —
> `[{variation_id, attribute_name, variation_name, quantity, unit_price}]` … le snapshot utilise
> `variation_name` pour la valeur et `attribute_name` pour le label, PAS `name`. »

Discriminant réel : `const fromSnapshot = typeof v.attribute_name === 'string' || typeof v.variation_id !== 'undefined'` (`:174`).

**(b) Cuisine lisible — `resources/js/helpers/kdsCustomization.js`**
`kdsVariationGroupValue(v)` (`:183`) et `kdsVariationLine(v)` (`:203`) : discriminant = présence de
`attribute_name`. `renderItem(orderItem)` (`:383`) produit les lignes typées `header / variation /
variation-flat / supplement / menu_child / addon / instruction / allergen`. Lecture snapshot-first :
`readVariations/readExtras/readAddons` (`:138-161`).

**(c) Cuisine symbolique — `resources/js/helpers/kdsSymbolic.js`**
`renderItemSymbolic(orderItem)` (`:595`) : codes courts (`G | SANDWICH | P | STO | SAM`) — jumeau PHP
`app/Services/Hardware/KitchenTicketSymbolicFormatter.php`. **Ce n'est PAS du français lisible.**

Côté serveur, le seul « normaliseur » est la priorité snapshot → legacy :
`OrderItemResource::resolveVariationsForApi/ExtrasForApi/AddonsForApi` (`app/Http/Resources/OrderItemResource.php:75-107`),
dupliquée dans `KDSOrderItemsResource::resolveVariationsForKds/ExtrasForKds/AddonsForKds` (`:52-89`).

### Ce que chaque ressource expose
| Ressource | Clés composition | file:line |
|---|---|---|
| `OrderItemResource` | `item_variations`, `item_extras`, `item_addons`, `composition_snapshot`, `allergens_snapshot`, `instruction` | `:33-37`, `:50` |
| `OrderDetailsResource` | `order_items` → `OrderItemResource` | `:130` |
| `KDSOrderDetailsResource` | `order_items` → `OrderItemResource` | `:77` |
| `KDSOrderItemsResource` | `item_variations`, `item_extras`, `item_addons`, `instruction`, `allergens_snapshot` — **pas** `composition_snapshot` brut | `:18-45` |
| `SimpleOrderResource` | `order_items:[{item_id, item_name, quantity, instruction}]` + `has_instruction` — **ni variations, ni extras, ni addons** | `:155`, `:161`, `:225-247` |
| `CDSOrderDetailsResource` | id, serial, token, queue_number, order_type, status — **aucune composition** | `:17-24` |

---

## 3. Matrice de rendu

| Surface | Produit | Variations | Extras | Suppléments (addons) | Instruction | Cuisson | file:line |
|---|---|---|---|---|---|---|---|
| Carte suivi caisse (`PosOrdersTrackerComponent`) | oui (3 max) | **non** | **non** | **non** | 1re seulement | non | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:323-333`, `:1995`, `:2069-2073` |
| Détail commande caisse (`PosOrderShowComponent`) | oui | oui (normalisé) | oui (normalisé) | **NON (0 occurrence de « addon »)** | oui | via variations | `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:230-243`, `:267-279`, `:280-285`, `:757-760` |
| Ticket client réimprimé (`ReceiptComponent`) | oui | oui | oui + prix | oui + prix | oui (assainie) | via variations | `resources/js/components/admin/pos/ReceiptComponent.vue:144-170`, `:535-555`, `:576-581` |
| Ticket client ESC/POS | oui | oui `Groupe: Valeur` | oui | oui | oui | via variations | `app/Services/Hardware/OrderReceiptEscPosRenderer.php:493-558`, `:559` |
| Carte KDS V2 (`KdsOrderCard`) | symbolique | symboles | symboles | symboles | oui | bandeau `cuissonForOrder` | `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:164`, `:238-241`, `:560-563` |
| Board KDS legacy (`KitchenDisplaySystemComponent`) | oui | `kdsVariationLine` | **`{{ extra.name }}` cassé** | oui | oui | non | `:261-290`, `:1895-1897` |
| Ticket cuisine imprimé | symbolique | symboles | `supplementLines` | `menuLine`/`drinkLines` | `cleanInstruction` | bandeau CUISSON | `OrderReceiptEscPosRenderer.php:253`, `:280-300`, `:370-400` ; `KitchenTicketSymbolicFormatter.php:202,301,541,766,808` |
| Écran OSS | **rien** (numéro/jeton) | non | non | non | non | non | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:34-37`, `:64-67` |

---

## 4. Fuites de libellés techniques

**F1 — Extras invisibles sur le board KDS legacy (P1).**
`{{ extra.name }}` sur 5 sites : `KitchenDisplaySystemComponent.vue:273, 527, 715, 891, 1063`.
Or la forme snapshot n'a pas `name` mais `extra_name` (`CompositionSnapshotBuilder.php:109`), et
`KDSOrderItemsResource::resolveExtrasForKds` (`:81-89`) sert le snapshot en priorité. Condition
exacte : `composition_snapshot.extras ≠ []` ⇒ rendu « Extras: , , ». C'est exactement le défaut déjà
corrigé côté caisse et documenté en clair dans `PosOrderShowComponent.vue:252` (« les extras de
l'instantané n'exposent pas `name` mais `extra_name`, d'où le "Extras: ," orphelin »).

**F2 — Suppléments de formule absents du détail caisse (P1).**
`grep -c addon PosOrderShowComponent.vue` = **0**. Les addons du snapshot (boisson/frites d'un menu,
`role: menu_full|menu_frites|menu_boisson`) sont facturés (`line_total`, `CompositionSnapshotBuilder.php:166-177`)
et rendus sur le ticket (`ReceiptComponent.vue:162-170`), mais jamais sur l'écran de détail caisse.

**F3 — Nom de groupe technique en secours KDS.**
`KdsOrderLine.vue:110-117` : si `label.kds_group_<clé>` manque, on affiche `this.line.group`, donc la
clé brute anglaise (`bread`, `crudites`, `size`…). Les 10 clés actuelles existent en FR ; toute
nouvelle catégorie ajoutée dans `GROUP_PATTERNS` (`kdsCustomization.js:25-32`) fuit immédiatement.

**F4 — Slug 3 lettres pour sauce inconnue.**
`kdsSymbolic.js:112` : `return n.replace(/^sauce\s+/,'').slice(0,3).toUpperCase()` — « Sauce Bulgare »
→ « BUL ». Jumeau PHP `KitchenTicketSymbolicFormatter.php:103`. Illisible pour un caissier.

**F5 — Garniture silencieusement effacée.**
`cruditeSymbol` (`kdsSymbolic.js:115-126`) renvoie `''` hors table (Salade/Tomate/Oignon). Toute autre
crudité disparaît de la ligne symbolique. Même moteur réutilisé par le panier caisse
(`posCartCompactDisplay.js:26`, `foldCrudites` `:50-68`).

**F6 — Nom de produit vide.**
`SimpleOrderResource:237` → `item_name = $line->orderItem?->name` (null possible) ; le template
`PosOrdersTrackerComponent.vue:329` fait `{{ item.item_name || item.name }}` → chaîne vide, pas de
repli. Idem `OrderItemResource:26`.

**F7 — Anglicisme assumé.** `label.extras` = « Extras » (`resources/js/languages/fr.json:1152`),
affiché tel quel en caisse (`PosOrderShowComponent.vue:268`) et sur le ticket (`ReceiptComponent.vue:154`).

---

## 5. Vocabulaire FR déjà disponible (`resources/js/languages/fr.json`)

| Clé | Valeur FR | Ligne |
|---|---|---|
| `label.extras` | « Extras » | 1152 |
| `label.addons` | « Suppléments » | 985 |
| `label.instruction` | « Instruction » | 1182 |
| `label.unnamed_extras` | « {count} supplément(s) non identifié(s) » | 1121 |
| `label.variation` | « Variante » | 1420 |
| `label.kds_group_bread` … `_other` | Pain / Crudités / Sauce / Supplément / Cuisson / Boisson / Taille / Avec / Menu / Choix | 964-973 |
| `label.kds_allergen_warning_prefix` | « Allergènes : » | 924 |
| `…supplement_default_desc`, `…supplements` (kiosk) | « Supplément », « QUEL SUPPLÉMENT ? » | 2144, 2163 |

**Absents** : `label.variations` (pluriel), `label.supplement`, `label.cuisson`, et **toute clé
« sans X » / retrait**. Le retrait n'existe aujourd'hui que comme heuristique de texte :
`kdsSymbolic.js:121` (`/^(sans|pas\s+d[eu'])\b/`), `kdsLineSemantics.js:44 matchesExclusion`,
`posCartCompactDisplay.js:19` (« un RETRAIT "Sans oignon" est conservé et mis en capitales »).
Aucun libellé i18n, aucune colonne : **le retrait n'est pas une donnée, c'est du texte libre.**

---

## Synthèse pour le GOAL

- La vérité NF525 est complète (`composition_snapshot` : produit, variations groupées, extras,
  addons avec rôle et prix réel). Rien ne manque **en base**.
- Ce qui manque est **au rendu caisse** : le suivi ne montre que des noms de produits ; le détail
  oublie les addons ; le KDS legacy perd les extras ; la cuisine parle en symboles.
- Un normaliseur « FR lisible » réutilisable existe déjà — `posReceiptBuilder.js` — et il est déjà
  branché sur les deux surfaces caisse. C'est le socle naturel.
