# Z1 — CATALOGUE & PERSONNALISATION PRODUIT — reconnaissance web réelle (2026-08-26)

> Cible `http://127.0.0.1:8766` (arbre principal, HEAD `43b120c7d` + non commité), base `foodking_e2e`, `APP_ENV=local`.
> Deux passages navigateur + API (03:46-03:56 et 11:52-12:01), tous deux coupés par la limite de session avant la phase
> « composer » ; ce document est consolidé par le chef de projet à partir des résultats bruts (`tmp/recon/Z1/a1_result.json`,
> `z1_a_result.json`, `kiosk_menu_baseline.json`, `pos_items_baseline.json`) et des 50 captures `recon/screens/Z1/`.
> **Non mesuré à l'écran (à rejouer en W1 du GOAL ONB-02/03)** : scénario (b) composer par catégorie de bout en bout,
> (c) import Excel (fichiers `ok.xlsx`, `broken.xlsx`, `missing_col.xlsx` générés mais non soumis), (d) suppression d'article.

## 1. Périmètre parcouru
| URL | État constaté |
|---|---|
| `/admin/items/studio` | 200 — page « Catalogue » (barre catégories + grille + formulaires rapides) — captures `a1-01`, `a1b-01` |
| `/admin/catalog-hub` | 200 — onglets Catalogue / Stock — `a1-02` |
| `/admin/items` | 200 → atterrit sur le Studio ; **`?create=1` n'ouvre PAS le tiroir** (`drawerOpen=0`, deux passages) ; `/admin/items/create` l'ouvre (`drawerOpen=1`) — `a1-04`, `a1-05` |
| `/admin/items/show/:id` | 200 — 7 onglets Informations · Images · Variante · Extra · Supplément · Composition · Aperçu — `a1-30`..`a1-36` |
| `/admin/categories/:id/composer` | 200 (composer par catégorie, sans drapeau) — `a1-11`, `a1b-12` |
| `/admin/items/:id/composer` · `/admin/demo/wizard-launcher` | 200 mais `window.foodkingConfig.features.wizard_per_item_demo=false` : écran de repli — `a1-06`, `a1-07` |
| `/admin/settings/item-categories/list` · `item-attributes/list` · `taxes/list` | 200 par URL directe (cachées du menu) — `a1-08`..`a1-10` |
| Menu borne `GET /api/frontend/menu` (jeton borne) et articles POS `GET /api/admin/item?branch_id=1` | 200 — lignes de base enregistrées (`kiosk_menu_baseline.json` 299 Ko, `pos_items_baseline.json`) |

## 2. CE QUI MARCHE (preuves)
- Catégorie + article créés depuis le Studio en **9-10 clics** ; l'article est immédiatement `status=5`, `is_available=true`, `tax_id=1` (« No-VAT », 0 %), visible dans la grille (`a1-26`).
- Doublon de nom (catégorie et article) → 422 « La valeur du champ name est déjà utilisée. » ; nom de 300 caractères → 422 (max 190) ; catégorie inexistante → 422 ; prix négatif → 422 ; course de deux créations simultanées du même nom → une 201, une 422.
- Onglet Composition : « Final : PricingService backend » — le prix n'est jamais calculé côté interface ; onglet Aperçu compare Caisse / Borne par filiale.
- Suppression d'une catégorie non vide bloquée (`app/Services/ItemCategoryService.php:184-191`, message FR).
- Modale « Attribut d'articles » avec aide contextuelle réelle (min/max/répétition : « Ex : 1 viande, 2 viandes, 4 viandes ») — `05-attribut-modale.png` ; attribut créé `min 0 / max 1 / allow_repeat false`.
- Photo : type `.svg` refusé (422) ; fichier de 27 Mo refusé (413).

## 3. CONSTATS (P0 → P3)
```
[P1] app/Http/Requests/ItemRequest.php:79 (kds_station max:32) — Station de cuisine inconnue = erreur SQL brute au lieu d'une validation
  reproduction : POST /api/admin/item avec kds_station="inconnue" → 422 dont le message est « SQLSTATE[01000]: Warning: 1265 Data truncated for column 'kds_station'… » (z1_a_result.json edges.kdsInconnu)
  preuve       : réponse API ; migration 2026_04_20_230000 : enum('bar','cuisine_chaude','cuisine_froide','none')
  impact commerçant : le message expose la base et ne dit pas quelles stations existent ; aucune liste fermée côté formulaire
  recommandation : règle `in:bar,cuisine_chaude,cuisine_froide,none` (source unique partagée avec le KDS) + message FR

[P1] app/Http/Requests/ItemRequest.php:69 (channels.* in kiosk,pos,web) vs création — Canal inconnu accepté
  reproduction : POST /api/admin/item avec channels=["inconnu"] → 201 (article 241 créé, z1_a_result.json edges.chanInconnu)
  preuve       : réponse 201 ; la règle `in:` existe mais le payload passe (forme du champ ? à vérifier : tableau JSON vs chaîne)
  impact commerçant : un article peut être invisible partout sans erreur
  recommandation : test de caractérisation sur la forme exacte du payload, puis règle effective + valeur par défaut = tous les canaux

[P2] resources/js/components/admin/items/CatalogStudioComponent.vue (formulaire rapide produit) — Virgule décimale refusée, message anglais
  reproduction : Studio → nouveau produit → prix « 8,50 » → toast « This price must be a number. » (a1_result.json product_toasts_comma_price ; capture 03-studio-produit-prix-virgule-refuse.png)
  impact commerçant : un Français tape une virgule ; le message est en anglais
  recommandation : normaliser « , » → « . » côté client, message FR

[P2] ItemRequest (règle prix) — Prix 0 refusé avec « This price negative amount not allow. »
  reproduction : POST price=0 → 422 (edges.price0) ; 0 n'est pas négatif (un produit offert / composant de formule doit pouvoir valoir 0)
  recommandation : autoriser 0, réserver le refus au négatif, message FR

[P2] resources/js/router/modules/itemRoutes.js:62-80 — `?create=1` documenté mais inopérant
  reproduction : /admin/items?create=1 → aucun tiroir (deux passages) ; /admin/items/create → tiroir ouvert
  recommandation : supprimer l'un des deux chemins ou faire fonctionner les deux

[P2] i18n — Toasts de succès en anglais mêlé : « Catégories Deleted Successfully. », « Articles Deleted Successfully. »
  preuve : a1_result.json category_toasts / product_toasts_dot_price
  recommandation : clés `label.*` traduites (voir Z8 / ONB-11)

[P2] ItemPhotoUploadRequest — Message trompeur pour un mauvais type de fichier
  reproduction : photo .svg → 422 « La photo du produit est obligatoire. » (photo_edges.svg) ; 27 Mo → 413 HTML PHP brut (« POST Content-Length … exceeds the limit of 8388608 bytes »)
  recommandation : « Format accepté : JPG/PNG ≤ 2 Mo » avant envoi ; interception 413 côté client

[P2] config/catalog_v15.php:173-177 — Composer par article et « Wizard avancé » = écrans de repli silencieux
  reproduction : /admin/items/98/composer et /admin/demo/wizard-launcher avec le drapeau à false (captures a1-06, a1-07)
  impact commerçant : deux URL existent, aucune n'explique pourquoi elle ne fait rien
  recommandation : trancher (lever le drapeau ou retirer les routes) — ONB-03 §G

[P3] a1b-40 / a1b-41 — Le bouton « wizard » d'un produit dans le Studio ouvre une superposition ~7 s puis une page pleine dans le même onglet ; le bouton « wizard » d'une catégorie ne produit rien de visible (`overlay:0`) — à re-mesurer avec un compte non chargé
```

## 4. ANGLES MORTS d'un nouveau commerçant
1. Les concepts Variation / Extra / Supplément (addon) / Attribut / Ingrédient / Étape de composition ne sont expliqués nulle part sauf dans la modale Attribut ; la fiche produit a 7 onglets pour un burger. 2. Catégories, Attributs, Taxes vivent dans Réglages (cachés) : il les découvre par hasard. 3. La taxe par défaut d'un nouvel article est « No-VAT 0 % » (`tax_id=1`) : un restaurant français vend à 10 % — risque fiscal silencieux. 4. Aucune sémantique « gratuit / inclus / payant » dans le wizard (confirmé lecture de code, non contredit à l'écran). 5. Images de repli par slug Cayenne (`config/menu_images.php`).

## 5. « CAYENNE » EN DUR vu à l'écran
Onglet Aperçu : liste de filiales de démonstration (« Collier and Sons Branch », « Skiles-Johns Branch »… + « Le Cayenne (principal) ») ; templates de composition (`simple/sandwich/tacos/assiette/snacking/menu`) ; menu borne de base entièrement Le Cayenne.

## 6. QUESTIONS PROPRIÉTAIRE
1. Taxe par défaut d'un nouvel article : 10 % (restauration) plutôt que 0 % ? 2. Lever `FEATURE_WIZARD_PER_ITEM_DEMO` pour tous ? 3. Fusionner Studio / Articles / Catégories / Attributs / Taxes en un seul espace « Catalogue » ? 4. Prix 0 autorisé ?

## 7. NETTOYAGE (preuve DB)
Premier passage : cat 134, item 239 ; second passage : cat 135, items 240-242, attribut 47 — tous **supprimés définitivement** par le chef de projet (`cleanup_leftovers*.php`, vérification `items/cats/attrs LIKE 'AUDIT-ONB%' = 0`).

## 8. Captures (`recon/screens/Z1/`)
`a1-01..11` tour des pages · `a1-20..26` création catégorie/produit (dont `a1-22` doublon refusé, `a1-25` virgule refusée) · `a1-30..36` les 7 onglets · `a1-40/41`, `a1b-40..43` boutons wizard du Studio · `01-07` second passage (toast création, formulaire, virgule, attributs, modale attribut, fiche, tiroir variante) · `99-a-fatal.png` : arrêt du script (sélecteur de tiroir absent).
