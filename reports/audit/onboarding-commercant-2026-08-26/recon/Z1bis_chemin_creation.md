# Z1bis — Le chemin complet de création (catégorie → article vendable), lecture seule

Vérifié 2026-08-27 en LECTURE SEULE stricte : aucun fichier modifié hors ce rapport, aucun POST/PUT/PATCH/DELETE HTTP émis, aucune écriture en base (uniquement des `SELECT`). Serveur cible du GOAL (:8802) non lancé dans cette session ; toute évidence est du code source + de la base partagée `foodking_e2e` (lecture). Chaque `file:line` ci-dessous a été ouvert et vérifié — aucune extrapolation non marquée. Les 26 taxes « AUDIT-KIOSK-MULTI TVA 0 » et 58 articles de test résiduels ne sont jamais comptés comme données légitimes.

Question directrice : *un commerçant reçoit ce logiciel vide — peut-il y recopier sa carte en une soirée, seul ?*

---

## 1. Créer une catégorie

- Route unique : `/admin/items/studio` (`resources/js/router/modules/itemRoutes.js:41-50`).
- Composant : `CatalogStudioComponent.vue`, bouton « + catégorie » (`:9-15`) → formulaire inline (pas de changement d'écran) `:82-88` → handler `createCategory()` `:549-574`.
- Champ visible au commerçant : **un seul, le nom** (`:84-87`, `v-model="categoryQuickForm.name"`, `data()` n'a que `{ name: "" }` à `:224-226`).
- Payload réellement envoyé (`:557-563`) : `name`, `status: statusEnum.ACTIVE` (forcé, jamais montré), `description: ""` (forcé vide).
- Règles serveur (`app/Http/Requests/ItemCategoryRequest.php:31-38,47`) : seuls `name` (required, max 190, unique parmi non-supprimées) et `status` (required) sont obligatoires. Tout le reste (`parent_id`, `image`, `wizard_template`, `channels`, `kiosk_sort`, `pos_sort`, `kiosk_label` — `:39-63`) est nullable et **inaccessible** depuis ce formulaire rapide (il faut passer par `Réglages > Catégories`, écran séparé non testé ici).
- 1 écran, 2 clics (bouton, puis « Enregistrer »baseline) + une saisie clavier.

## 2. Créer un article

Deux chemins coexistent :
- **a) Quick Create Studio** (page d'accueil catalogue, `CatalogStudioComponent.vue:102-123`) : champs visibles = catégorie (si aucune sélectionnée), nom, prix, description, image. Rien d'autre.
- **b) Tiroir complet** (`/admin/items/create` → redirige vers `admin.items.list?create=1`, `itemRoutes.js:67-80` → `ItemCreateComponent.vue`) : ~15+ champs dont taxe, type, statut, canaux, allergènes, station cuisine.

Obligatoire côté serveur (`app/Http/Requests/ItemRequest.php:42-89`) :
`name` (:43-48), `item_category_id` (:52, doit exister + non supprimée), `item_type` (:54), `price` (:55, règle `IniAmount`), `is_featured` (:56), `status` (:82), `order` (:83).
Nullable : `tax_id` (:53), `is_upsell/chef_pick/new/available/spicy/vegetarian/pork_free/halal/gluten_free` (:58-66), `channels` (:68-69), `allergen_flags` (:70-74), `barcode` (:78), `kds_station` (:79), `image` (:88).

Via Quick Create, tout ce qui est nullable est rempli **automatiquement en JS**, invisible au commerçant (`buildQuickProductPayload()`, `:479-496`) : `item_type` toujours `VEG` (:488, quel que soit le produit), `is_featured=YES`, `tax_id=defaultTaxId` (voir §3), `status=ACTIVE` (:491). Le commerçant ne sait pas — et n'a pas à savoir — ce que sont `item_type`/`is_featured` puisqu'il ne les voit jamais ; mais il ne peut pas non plus les corriger sans ouvrir le tiroir complet après coup.
Via le tiroir complet, le dropdown Taxe est initialisé à `null` (`ItemCreateComponent.vue:339,361`) et envoyé tel quel si non touché (`:377`) — aucune aide contextuelle vue dans ce fichier pour expliquer ce qu'il faut y mettre.

## 3. La taxe — POINT LE PLUS IMPORTANT

**Un article naît soit à 0 % de TVA (Quick Create), soit sans taxe du tout — `tax_id = NULL` (tiroir complet oublié) — jamais à 10 % malgré la config.**

- `ItemRequest.php:53` : `'tax_id' => ['nullable', 'numeric', 'not_in:0']` — PAS obligatoire.
- `app/Services/ItemService.php:306` : `Item::create($request->validated() + ['slug' => ...])` — aucune valeur par défaut appliquée côté serveur ; ce qui arrive dans la requête est stocké tel quel, y compris l'absence de `tax_id`.
- **Origine exacte du 0 % (Quick Create, chemin réellement utilisé)** :
  - `CatalogStudioComponent.vue:259-263` — la liste des taxes est chargée avec `taxesSearch: { paginate:0, order_column:"id", order_type:"asc" }`, **sans filtre de statut ni de légitimité**, sur la table entière (53 lignes).
  - `CatalogStudioComponent.vue:300-303` — `defaultTaxId()` = `Number(this.taxes[0].id)` = le PREMIER élément de cette liste triée par id croissant.
  - `CatalogStudioComponent.vue:490` — `fd.append("tax_id", this.defaultTaxId ? String(this.defaultTaxId) : "")`.
  - Preuve base vérifiée (`SELECT id,name,code,tax_rate,type,status FROM taxes ORDER BY id LIMIT 10`) : `id=1, name="No-VAT", code="VAT-0", tax_rate=0.000000, status=5 (actif)` — c'est la ligne au plus petit id parmi 53 (dont 26 « AUDIT-KIOSK-MULTI », 21 « TVA nn% » incohérentes). `defaultTaxId` la choisit uniquement parce que c'est la première ligne jamais insérée dans une table jamais nettoyée — **pas** parce qu'un quelconque champ « taxe par défaut du restaurant » existe.
- `config/menu.php:80` (`'default_tax_id' => 3`, la ligne VAT-10 %) **n'est jamais lu par le chemin de création réel**. Vérifié par `grep -rn "default_tax_id"` sur tout le repo : les seuls consommateurs sont `database/seeders/MenuSeeder.php:406` (peuplement de la démo Le Cayenne) et des tests dédiés à ce seeder. `ItemController`, `ItemRequest`, `ItemService`, `CatalogStudioComponent`, `ItemCreateComponent` ne le lisent jamais. Changer ce chiffre en config ne changerait rien pour un vrai commerçant.
- **Conséquence fiscale vérifiée en aval** (fichier gelé, lecture seule) — `app/Services/Pricing/PricingService.php:241-245` :
  ```
  $taxId = (int) ($dbItem->tax_id ?? 0);
  $taxObj = $taxes[$taxId] ?? null;
  $taxRate = $taxObj ? (float) $taxObj->tax_rate : 0.0;
  ```
  Si `tax_id` est `NULL`, `(int) null = 0`, aucune taxe d'id 0 n'existe → `$taxRate = 0.0` **silencieusement, sans erreur ni log**, valeur ensuite persistée dans `order_items` (SSOT du prix, NF525).
- Preuve DB d'un `tax_id` NULL réel (vérifié `SELECT id,name,tax_id,status FROM items WHERE id=162`) : `id=162, "Article Uber (non mappé)", tax_id=NULL, status=10` — le mécanisme n'est pas théorique (item inactif ici, mais l'état est bien atteignable).
- Aucun message d'erreur, aucun avertissement, code 201 : l'article est créé avec succès et devient immédiatement vendable (§7) à 0 % ou sans taxe.

## 4. L'image

- Création/édition d'article (`ItemRequest.php:88`) : `'image' => ['nullable','image','mimes:jpg,jpeg,png','max:2048', NoDangerousFileExtension]` — **facultative**, 2 Mo max, jpg/jpeg/png seulement (pas de webp/svg).
- Upload de photo après création (`routes/api.php:900` `POST /items/{item}/photo` → `ItemPhotoController::store` → `app/Http/Requests/ItemPhotoUploadRequest.php:19-30`) : champ `photo` **REQUIRED**, mimes jpg/jpeg/png/webp, 4 Mo max, messages FR dédiés dont `photo.required` = « La photo du produit est obligatoire. » (`:27`). C'est cette route (endpoint séparé, item déjà existant) qui produit le message vu en recon Z1 — pas le formulaire de création lui-même, qui lui n'exige rien.
- Sans image à la création : l'article est créé normalement. `app/Models/Item.php:88-113` (`getThumbAttribute()`) bascule alors sur `config/menu_images.php` (mapping par slug) puis un fichier générique (`item-default.svg` / `images/item/thumb.png`). L'article reste vendable, seule la vignette change.

## 5. Variantes / extras / suppléments / attributs

- Variations (`routes/api.php:873-878`), extras (`:880-884`, `:891-892`) et suppléments/addons (`:896-898`) sont toutes imbriquées sous `{item}` — un article doit déjà exister.
- Exception : `ItemService::store()` (`app/Services/ItemService.php:302-330`) accepte des blobs JSON `variations`/`extras` **dans la même requête** de création d'article (`ItemRequest.php:84-85`, règle `json`) — donc variations/extras peuvent être soumis en même temps que l'article, jamais avant. Les **addons** n'ont aucun chemin équivalent dans `store()` : uniquement après coup (`update()` ou route dédiée).
- Les **Attributs** (groupes de choix, ex. « Taille ») sont indépendants de tout article — routes top-level (`routes/api.php:525-530`) — et doivent exister AVANT toute variation, puisque `item_variations.item_attribute_id` est une clé étrangère obligatoire non nullable (`database/migrations/2022_11_17_110621_create_item_variations_table.php:19`, `constrained()` sans `nullable()`). Or l'écran pour créer un Attribut n'apparaît nulle part dans le Quick Create Studio ni dans le tiroir article vérifiés ici — c'est un module séparé (`settings/ItemAttribute/*`), non atteint par navigation dans cette passe (navigateur MCP indisponible cette session).

## 6. Import Excel

- Route : `POST /admin/item/import/file` (`routes/api.php:870`) → `ItemController::import()` (`app/Http/Controllers/Admin/ItemController.php:218-226`) → `Excel::import(new ItemImport(...))` → **`return response('', 202)`** : aucun corps de réponse.
- `app/Imports/ItemImport.php` utilise `SkipsOnFailure`+`SkipsFailures` (`:21-22`) qui collecte les lignes en échec en interne, mais le contrôleur ne lit ni ne retourne jamais `$import->failures()` — **le commerçant n'a aucun moyen de savoir combien de lignes ont été créées, combien rejetées, ni pourquoi.**
- Colonnes validées (`ItemImport.php:44-58`) : `name` (required), `category` (required, texte libre), `tax` (nullable, numérique = un TAUX, pas un id), `item_type` (required), `price` (required), `featured` (required), `status` (required), `description`/`caution` (nullable).
- Résolution catégorie (`getCategoryId`, `:79-86`) : `WHERE LOWER(name) LIKE '%categorie%'` — correspondance partielle. Si rien ne matche, `model()` (`:27-40`) retourne `null` **silencieusement** — la ligne disparaît sans motif affiché nulle part (contraire au critère du GOAL « 42 créées, 3 refusées avec ligne + motif »).
- Résolution taxe (`getTaxId`, `:71-77`) : `Tax::where('tax_rate', $tax_rate)->first()` — recherche par taux numérique, **sans `orderBy` ni filtre de statut**, sur 53 lignes dont 47 polluées avec des taux dupliqués. Résultat non déterministe : une ligne à « 10 » peut recevoir n'importe quelle taxe à 10 % existante (légitime ou parasite), au hasard de l'ordre MySQL. Si aucun taux ne correspond, `tax_id = null` (même défaut qu'au §3).
- Gabarit (`GET /admin/item/download-sample` → `public/file/itemImportSample.xlsx`, ouvert et lu directement via PhpSpreadsheet) : en-têtes **100 % anglais** (« Name, Category, Price, Item Type, Tax, Status, Featured, Caution, Description »), exemples génériques (« Pasta », « Biriyani », catégories « Beverages », « Side Orders ») qui n'existent dans aucune catégorie réelle de ce commerce (vérifiées `config/menu.php:52-64` : Frites, Suppléments, Desserts, Boissons, Menu enfant…). Suivre ce gabarit à la lettre produit des lignes vouées à l'échec de résolution de catégorie.

## 7. Quand un article devient VENDABLE (borne + caisse)

- Caisse (POS, `/admin/item`) et API publique borne (`/api/frontend/item`) passent tous deux par `ItemService::simpleList()` :
  - `app/Services/ItemService.php:152` : `$query->where('status', \App\Enums\Status::ACTIVE)` forcé (`Status::ACTIVE = 5`, `app/Enums/Status.php:7`).
  - `ItemService.php:158` → `applyChannelsFilter()` (`:184-198`) : un article avec `channels = NULL` reste visible sur **toutes** les surfaces (comportement V1 par défaut) ; sinon il doit contenir la surface demandée (`kiosk`/`pos`/`web`).
- Menu borne dédié `GET /api/frontend/menu` via `app/Services/Kiosk/KioskMenuService.php` :
  - `:69` : catégories filtrées `status = Status::ACTIVE` + visibles sur le canal kiosk.
  - `:98` : items filtrés `status = Status::ACTIVE` ET `item_category_id` dans les catégories actives+visibles.
- `is_available` (colonne item + override `item_branch_availability`) n'exclut pas de la liste — il marque juste l'article indisponible dans la charge utile (`KioskMenuService.php:367`) ; « vendable » au sens strict = statut ACTIF + catégorie ACTIVE + canal correspondant.
- Défauts vérifiés en base (`SHOW COLUMNS FROM items`) : `status` défaut `5` (ACTIF), `channels` défaut `NULL` (partout), `is_available` défaut `1` (vrai), `tax_id` défaut `NULL`. Le Quick Create Studio envoie en plus explicitement `status=ACTIVE` (`:491`). **Un article créé via le Quick Create est donc instantanément vendable à la caisse et à la borne — sans TVA correcte (§3) et sans que le commerçant ait rien décidé sur canaux/station.**

---

## Les 5 points où un commerçant se bloque (ou est trompé sans le savoir)

1. **La taxe silencieuse à 0 %/NULL** — `CatalogStudioComponent.vue:259-263,300-303,490` + `ItemRequest.php:53` + `ItemService.php:306` + preuve fiscale `PricingService.php:241-245`. Le plus grave : aucun avertissement, l'article est vendu immédiatement à un taux erroné, `config/menu.php:80` (10 %) n'a aucun effet réel.
2. **Le tiroir complet expose ~15+ champs sans lexique** (taxe en dropdown vide `ItemCreateComponent.vue:339,361,377`, station cuisine, canaux) — le commerçant ne sait pas quoi y mettre et rien ne l'oblige à les remplir.
3. **`kds_station` sans règle `in:`** (`ItemRequest.php:79`, `'nullable','string','max:32'`) alors que la colonne est un ENUM MySQL strict (`database/migrations/2026_04_20_230000_add_kds_station_to_items.php:16-18`, valeurs `bar/cuisine_chaude/cuisine_froide/none`) — une valeur mal formée dans le tiroir complet provoque une troncature SQL brute au lieu d'un message compréhensible (mesuré en Z1 ; non rejoué ici, aucune requête POST autorisée dans cette mission).
4. **Import Excel opaque** — gabarit anglais avec des catégories inexistantes (`itemImportSample.xlsx`, vérifié), résolution catégorie par `LIKE` flou et résolution taxe par taux dupliqué sans tri (`ItemImport.php:71-86`), et surtout **aucun rapport retourné** (`ItemController.php:218-226` → `response('', 202)`) : le commerçant ne saura jamais si ses 45 lignes sont vraiment entrées.
5. **Attributs obligatoires pour toute variante, créés sur un écran séparé introuvable depuis le Quick Create** — routes top-level indépendantes de l'article (`routes/api.php:525-530`) mais clé étrangère obligatoire (`create_item_variations_table.php:19`) : un commerçant qui veut juste ajouter « Taille S/M/L » ne trouve pas où créer « Taille » en premier depuis le parcours principal.

**Non vérifié dans cette passe** (nécessiterait un navigateur/POST, hors périmètre lecture seule) : comportement runtime exact du `?create=1` (mécanisme présent en code, `ItemListComponent.vue:446-451`, mais non rejoué) ; acceptation réelle d'un canal invalide (la règle `ItemRequest.php:69` `in:kiosk,pos,web` est bien présente dans le code actuel — la mesure Z1 d'un 201 sur canal inconnu n'a pas pu être reproduite ici sans émettre de requête).
