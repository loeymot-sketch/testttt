# BORNE r1 — Lentille PSYCHOLOGIE CLIENT (Attract + catalogue + navigation)

Subsystème 2.a. DB live `foodking_e2e`, serveur :8766. READ-ONLY, 0 mutation.
Parcours joué : idle → choix mode → /kiosk/categories → sidebar → grille produits.
Catégories kiosk projetées LIVE = 9 (Sandwichs, Galette, Burgers, Tacos, Bols,
Menu enfant, **Frites**, Desserts, Boissons) via `KioskMenuService::build` (tinker).

---

## FINDINGS

[P2] resources/js/helpers/kioskFormatPrice.js:37 — Prix borne affichés `€1,90` (symbole à GAUCHE) au lieu du français `1,90 €` — sur CHAQUE prix catalogue + total panier
  repro: ouvrir http://127.0.0.1:8766/kiosk/idle → "À emporter" → /kiosk/categories ; DOM `[data-testid^="kiosk-product-price-"]` = "€1,00" / "€1,50" / "€1,90" ; `[data-testid=kiosk-categories-cart-total]` = "€0,00".
  evidence: Playwright DOM live (snapshot e96="€1,90", e82="€1,50", e68="€1,00") ; mysql `settings.site_currency_position.payload = {"$value":10}` ; `CurrencyPosition::RIGHT=10` (app/Enums/CurrencyPosition.php:8). Chaîne : `kioskPriceMixin.formatPrice` (:82) → `getPriceOptionsFromStore` (:66) renvoie `position: 10` (entier, jamais mappé) → `formatKioskPrice` (:37) teste `options.position === 'right'` → `10 === 'right'` = FALSE → fallback symbole-gauche `${symbol}${formatted}` (:39). Le PHP côté serveur compare bien l'entier (`AppLibrary.php:301` : `$position == CurrencyPosition::LEFT`), le JS compare un littéral string → bug de mapping.
  lentille: client — un client FR lit `€1,90` comme une notation étrangère/anglo ; le commerçant a configuré RIGHT (`1,90 €`, convention FR) mais la borne l'ignore. Erosion de confiance + perception "pas vraiment français". Discipline §2 liste `€7.90 vs 7,90 €` comme REJET ; ici variante demi-fausse (virgule OK, symbole mal placé).
  reco: NON-frozen. Dans `getPriceOptionsFromStore` mapper l'enum : `position: Number(lists.site_currency_position) === 5 ? 'left' : 'right'` (CurrencyPosition LEFT=5/RIGHT=10) ; OU dans `formatKioskPrice` accepter `position === 5 || position === 'left'` pour la gauche et tout le reste (dont 10) → droite. TDD : assert `formatKioskPrice(1.9, {currencySymbol:'€', position:10}) === '1,90 €'`.

[P2] resources/js/store/modules/kioskMenu.js:85 — Catégorie "Frites" exposée sur la borne (cliquable) en VIOLATION du mandat owner « accompagnements seulement via les steps wizard, jamais standalone » — l'ID masqué est codé en dur et périmé
  repro: /kiosk/categories → la sidebar contient le bouton `kiosk-categories-sidebar-item-7` "Frites" (prouvé DOM live : `frites_present:true`, 9 catégories sidebar). Clic → 6 produits standalone (Petite/Grande Frites + variantes Cheddar 2,50→6,00 €).
  evidence: `KIOSK_HIDDEN_CATEGORY_IDS = new Set([315])` (kioskMenu.js:85) ; mais en DB live Frites = **id 7** (`item_categories` : id=7 slug=frites status=5 channels=NULL), l'id 315 n'existe pas. La projection kiosk renvoie Frites avec 6 items (tinker `KioskMenuService::build`). Le commentaire owner (kioskMenu.js:72-84) dit explicitement « Cat 315 (Frites) reste masquée — ses items sont des addons d'autres produits ». Hardcode rendu inopérant par le reseed du menu owner (defect anchor #4 "fragile au renumber").
  lentille: client — il clique "Frites" en pensant à un accompagnement, ajoute une frite seule au panier sans savoir à quel produit elle s'attache (la confusion exacte que l'owner voulait éviter). Double-emploi avec l'étape frites du wizard menu → sur-facturation possible / panier incohérent.
  reco: NON-frozen. Cibler la catégorie Frites par `slug==='frites'` (stable) plutôt que par id numérique, OU ajouter un flag DB (`hidden_on_kiosk_sidebar`) sur `item_categories`. Idem nettoyer le fallback périmé `catId === 318` dans KioskCategoriesComponent.vue:612 (Suppléments réel = id 8, INACTIVE). TDD : `sidebarCategories` getter exclut la cat slug=frites.

[P2] app/Services/Kiosk/KioskMenuService.php:66 — Aucune garde `whereHas('items')` : une catégorie kiosk-visible vidée de ses produits actifs devient un CUL-DE-SAC muet (sidebar cliquable → grille vide → "0 produit" → AUCUN message)
  repro (latent, non déclenché en l'état) : la requête catégories (`ItemCategory::where(status, ACTIVE)`) + filtre `isVisibleOn('kiosk')` n'exige PAS la présence d'items. Côté UI, l'empty-state n'existe QUE pour `categories.length === 0` (KioskCategoriesComponent.vue:76). Pour une catégorie sélectionnée vide, `catalogProducts` (:383) renvoie `[]`, la grille `v-for` (:147) ne rend rien, le sous-titre affiche "0 produit" (:139-142), et il N'Y A AUCUN message (`$t('kiosk.catalog.no_products')` n'existe pas — clé ABSENTE de fr.json, seul `no_categories` existe).
  evidence: grep — `whereHas('items'` absent de `app/Services/` (kiosk inclus). `sidebarCategories` (kioskMenu.js:69-94) n'exclut que l'id 315 hardcodé, jamais les catégories vides. Reproductibilité confirmée par construction : DB live a 9 catégories toutes ≥2 items (mysql COUNT) DONC pas de cul-de-sac AUJOURD'HUI, mais zéro garde runtime — l'admin qui passe tous les items d'une cat en INACTIVE (status=10) tout en gardant la cat active+kiosk déclenche le dead-end. Jumeau du POS : `MenuProjectionService::forChannel` (:69) a EXACTEMENT le même manque service-level.
  lentille: client — entre dans une catégorie alléchante, voit un écran blanc sans explication ni produit, ne sait pas quoi faire (pas de "revenez plus tard" / pas de redirection) → frustration, abandon. Pire qu'une cat absente car elle l'a appâté.
  reco: NON-frozen, 2 couches. (1) UI : ajouter dans KioskCategoriesComponent un empty-state par catégorie (`v-if="!loading && selectedCategoryId && catalogProducts.length === 0"`) + clé i18n `kiosk.catalog.no_products` ("Aucun produit dans cette catégorie pour le moment"). (2) Filtrer les catégories sans item actif kiosk de `sidebarCategories` (getter store, hors service frozen-adjacent). TDD : créer `tests/Feature/Kiosk/KioskEmptyCategoryHiddenTest.php` (cat active+kiosk sans item → absente du payload ou marquée) + spec JS empty-state.

---

## VÉRIFIÉ-CLEAN / RÉFUTÉ (anti-bruit)

- **Order-type forcé idle** : NON un bug. `ensureOrderTypeSelected()` (KioskCategoriesComponent.vue:438) redirige proprement vers idle si non choisi ; idle n'affiche qu'1 carte ("À emporter", dine-in désactivé V1, prouvé snapshot). Le client ne peut pas se retrouver bloqué sans issue — il DOIT choisir, ce qui est correct.
- **Drapeau langue placebo (changeLanguage no-op :263)** : NON reproductible LIVE → NON reporté comme défaut. `kiosk_languages_enabled` ABSENT des settings → backend défaut `['fr']` (SettingResource.php:135) → `enabledLanguages=['fr']` → `v-if="enabledLanguages.length > 1"` FALSE → le sélecteur de langue NE S'AFFICHE PAS sur la borne live (confirmé snapshot idle : aucun bouton FR/EN). Le no-op `changeLanguage` n'est atteignable que si un admin réactive ≥2 langues ; risque conditionnel uniquement, pas live.
- **Global empty / load error** : géré (spinner :64, retry :83, message no_categories :89) — issue de secours présente si le menu ne charge pas.

## NOTES MINEURES (sous le seuil P2, non bloquantes)
- Layout : le bottom-bar "Panier et paiement" intercepte le clic sur les dernières lignes de la sidebar (Playwright : `kiosk-bottom-abandon ... intercepts pointer events` en tentant Frites). Cosmétique/UX léger (P3), à confirmer en visuel sur viewport borne réel.
- 1 erreur console au boot idle/categories (non capturée en détail ; à investiguer round suivant — pas dans la lentille prix/navigation).
