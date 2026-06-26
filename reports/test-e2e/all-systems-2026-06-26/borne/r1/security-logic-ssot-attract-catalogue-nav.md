# BORNE r1 — Lentille SÉCURITÉ / LOGIQUE / SSOT — Sub 2.a (Attract + catalogue + navigation)

Cible : `KioskIdleScreenComponent`, `KioskMenuService::build`, `GET /api/frontend/menu`,
`KioskCategoriesComponent`, `kioskMenu.js` (store), `kioskRoutes.js`.
DB live : `foodking_e2e` (branch_id=1). READ-ONLY (SELECT only, tinker sans effet de bord).

## Verdict synthèse
- Cœur **SOLIDE** côté sécurité/SSOT : endpoint menu auth `kiosk:order`, branche dérivée de
  `KioskMachine` (jamais payload), prix exposés **affichage seulement** (ordering = PricingService SSOT).
- **1 vrai trou de logique (jumeau POS non-couvert) : `whereHas('items')` ABSENT côté kiosk** →
  catégorie visible sans produit = cul-de-sac muet pour le client. **Latent** (non-déclenché sur la DB
  actuelle, toutes les cats ont ≥2 items) mais **réel** et **une opération admin** (désactiver les 2 items
  d'une cat / ajouter une cat vide / rupture) le déclenche. P2.
- 2 P3 cosmétiques (sélecteur EN placebo ; hidden-category IDs morts 315/318).

---

## [P2] app/Services/Kiosk/KioskMenuService.php:66-74 — Catégorie kiosk-visible SANS produit = cul-de-sac (jumeau POS non corrigé)

repro:
- `grep -n "whereHas" app/Services/Kiosk/KioskMenuService.php` → **0 résultat** (aucun garde de présence d'items).
- POS twin a le garde : `app/Http/Controllers/Admin/PosCategoryController.php:81` et `:119` `whereHas('items')`.
- tinker READ-ONLY (DB_DATABASE=foodking_e2e) : `(new KioskMenuService)->build(Branch::find(1))` →
  expose les **9** catégories actives kiosk-visibles, **sans aucun filtre sur la présence d'items**.
  Confirmé : `Kiosk guard present? NO` / `POS twin guard present? YES`.
- Front ne rattrape PAS : `kioskMenu.js:69 sidebarCategories` ne filtre QUE
  `KIOSK_HIDDEN_CATEGORY_IDS = {315}` (ID mort ici) ; `kioskMenu.js:284-285` sélectionne par défaut
  `categories.find(c => c.id && c.id!==0)` (1ʳᵉ cat, AUCUN test d'items) ; `KioskCategoriesComponent.vue:383
  catalogProducts` + `:388 filteredProductCount` → si la cat n'a pas d'items, grille vide = écran muet
  (« 0 produit »), pas d'erreur, pas de redirection.

evidence:
- SQL `foodking_e2e` (status ACTIVE=5, channels kiosk, deleted_at NULL) : les 9 cats exposées ont
  toutes ≥2 items actifs → **trap latent, pas firing aujourd'hui**. MAIS Galette/Tacos/Bols/Menu enfant
  n'ont que **2 items chacune** : désactiver ces 2 items (admin, status→INACTIVE) ⇒ cat reste ACTIVE +
  kiosk-visible mais 0 item → cul-de-sac client.
- Le commentaire de heal POS prouve que le scénario est réel pour l'owner :
  `PosCategoryController.php` (bloc else) « After a half-seeded menu reset (e.g. owner shipping V1 with
  only 2 of 11 cats actually populated), the cashier strip showed 9 empty tabs … Filter: hide categories
  with ZERO items » → garde ajouté côté POS, **JAMAIS répliqué côté borne**.
- Couverture : le parity test cité par le plan (`tests/Feature/Menu/PosKioskProjectionParityTest.php`)
  teste `MenuProjectionService::forChannel('kiosk')` — PAS `KioskMenuService::build` (l'endpoint réel) —
  et n'asserte rien sur le masquage des cats vides. `tests/Feature/Kiosk/KioskEmptyCategoryHiddenTest.php`
  = **À CRÉER** (inexistant). Donc 0 test verrouille ce contrat côté kiosk.

lentille: client (se perd sur un onglet visible qui n'affiche rien — abandon probable) + technique (jumeau-gap).

reco (NON-frozen — `KioskMenuService` est shared mais **pas** dans la liste frozen stricte ; viser
non-frozen) : après le calcul de `$visibleItems`, ne projeter que les catégories qui possèdent au moins
un item kiosk-visible projeté (intersection `$visibleItems->pluck('item_category_id')`), en conservant
les parents nécessaires à la hiérarchie. Reproduire la logique de présence du POS (`whereHas('items')`
+ disponibilité branche) plutôt que de filtrer en SQL brut, pour rester aligné sur le twin. TDD :
créer `tests/Feature/Kiosk/KioskEmptyCategoryHiddenTest.php` (cat active kiosk + 0 item actif ⇒ absente
du payload `build()`), pinglant le défaut AVANT le fix. Frozen-diff attendu = 0.

---

## [P3] KioskIdleScreenComponent.vue:23-34 + :263-271 — Sélecteur de langue EN = placebo (FR-lock inerte)

repro:
- `enabledLanguages` data default = `['fr','en']` (`:195`) ; le sélecteur rend un bouton par langue dès
  `enabledLanguages.length > 1` (`:16`). `changeLanguage()` (`:263-271`) est **volontairement inerte**
  (ADR-007, `resources/js/i18n.js:21 KIOSK_LOCALE='fr'`, sentinel `kioskFrLockImmutable.spec.js`).
- DB `foodking_e2e` : aucun setting `kiosk_languages_enabled` (seuls `site_default_language`,
  `site_language_switch` existent) → le front garde le default ⇒ **les deux pills FR + EN s'affichent**.

evidence: un client non-francophone tape « EN » → rien ne se passe (ni locale, ni reload). Confusion UX.
Le handler inerte est *intentionnel* (mandat FR), donc le défaut est l'**affichage** du bouton EN, pas le no-op.

lentille: client (non-francophone trompé). Sévérité V1-LOCAL : cosmétique (FR-lock est un mandat assumé).

reco: ne pas toucher au FR-lock. Soit ne rendre le sélecteur que si une vraie bascule existe (masquer
quand FR-immutable), soit retirer 'en' du default `enabledLanguages`. Frozen kiosk → audit + gate
(`lock-plan`) si édition de `KioskIdleScreenComponent.vue` ; sinon différer.

---

## [P3] KioskCategoriesComponent.vue:599-613 + kioskMenu.js:85 — IDs catégorie codés en dur morts (318 / 315)

repro:
- `KioskCategoriesComponent.vue:612` fallback `catId === 318` (cat « Suppléments ») ; `kioskMenu.js:85`
  `KIOSK_HIDDEN_CATEGORY_IDS = {315}`.
- DB `foodking_e2e` : `SELECT id,name FROM item_categories WHERE id IN (315,318)` → **0 ligne**. La vraie
  cat Suppléments = **id 8** (status INACTIVE=10 aujourd'hui), Frites = **id 7**. Les constantes ne
  matchent plus rien sur cette base.

evidence: si l'owner réactive « Suppléments » (id 8), le garde `catId===318` ne s'applique pas → un
supplément ouvrirait le wizard au lieu de s'ajouter direct (le fallback nom `startsWith('supplement')`
sauve le cas grâce à `category_name`, mais l'ID est mort). Idem masquage Frites (id 315 ≠ 7) : si une cat
« Frites & Accompagnements » réapparaît avec un autre ID, elle ne serait plus masquée.

lentille: technique (fragile au renumber/reset, déjà signalé PIÈGES §4 du plan).

reco: piloter par `slug` (stable) plutôt que par ID numérique codé en dur, ou par un flag DB
(`hidden_on_kiosk`). Non bloquant V1 (le fallback nom couvre le cas courant). Différer V1.0.X.

---

## VÉRIFIÉ-SAIN (réfuté — pas de finding)
- **Sécurité endpoint menu** : `GET /api/frontend/menu` = `auth:sanctum` (`routes/api.php:1450-1452`) +
  contrôleur `MenuController.php:37` `tokenCan('kiosk:order')` (403 sinon) ; `branch_id` lu de
  `KioskMachine` (`:44-56`), **jamais du payload** → pas d'IDOR, pas de fuite inter-branche, pas d'accès
  non-auth. (Note style : la route s'appuie sur le `tokenCan` du contrôleur, pas sur le middleware
  `abilities:kiosk:order` comme `/kiosk-event` — défense correcte, juste asymétrie cosmétique, NON-finding.)
- **SSOT prix** : le menu n'expose `price`/`convert_price` que pour AFFICHAGE ; aucun prix client n'est de
  confiance (commande → `pricing/preview` + PricingService). Aucun calcul de prix dans `KioskMenuService`
  (invariant respecté).
- **Order-type non bloquant** : `KioskCategoriesComponent.vue:416-444 ensureOrderTypeSelected` redirige
  vers idle si pas de type ; idle expose toujours « À emporter » (la tuile « Sur place » est gated
  `dineInEnabled=false` par défaut, `:218-224`) → le client n'est JAMAIS bloqué faute de choix. Sain.
- **Découverte / sous-le-pli** : la grille rend TOUS les `catalogProducts` (`:147`, pas de pagination) ;
  35 items / 9 cats sur cette base → scroll suffit à V1-LOCAL. Pas de search/populaires mais non requis
  à cette échelle. Non-finding V1.
