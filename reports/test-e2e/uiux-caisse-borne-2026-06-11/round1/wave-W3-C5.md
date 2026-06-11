# W3-C5 — Audit UI/UX BORNE : entrée + catalogue (round 1)

Date 2026-06-11 · App `:8768` (DB jetable `foodking_e2e`) · Viewport 1080×1920 fr-FR, Chrome+touch.
Scripts jetables : `tests/e2e/_w3-c5-kiosk-entry-catalog.mjs`, `_w3-c5-kiosk-inactivity.mjs`, `_w3-c5-upsell-tap.mjs`.
Screenshots (12) : `shots-c5/01..12`. Grilles : `DESIGN_SYSTEM_POLICY_2026-06-10.md` + `DESIGN_REFERENCES_2026-06-11.md` §3/§4.
READ-ONLY code. Frozen non touchés (`KioskWizardComponent`, `KioskAppComponent`, `KioskUpsellComponent`).

---

## Findings

### [P1] `resources/js/helpers/kioskFormatPrice.js:37,66` — TOUS les prix borne en `€2,00` (symbole à gauche) au lieu du format FR `2,00 €`
- **reproduction:** /kiosk/idle → « À emporter » → toute grille produit ; lire `[data-testid^="kiosk-product-price-"]`, total bottom-bar, bandeau promo. DOM extraits : `€2,00`, `€7,00`, `€0,00`, promo `-€5,00`, wizard `Total €3,00`.
- **evidence:** shots 03/06/07/10/12. Root cause grep-confirmée : `getPriceOptionsFromStore` (l.66) passe `lists.site_currency_position` **brut** = `10` (DB `settings.site_currency_position=10` = `CurrencyPosition::RIGHT`, `app/Enums/CurrencyPosition.php:8`) ; `formatKioskPrice` (l.37) compare `options.position === 'right'` (string) → ne matche jamais → branche symbole-gauche systématique. La config dit RIGHT, l'écran rend LEFT. Anti-pattern §4 (« format prix anglo-saxon ») + checklist §3-12. Affecte aussi l'affichage prix DANS le wizard frozen (le helper, lui, est **non-frozen**).
- **recommendation:** mapper l'enum dans `getPriceOptionsFromStore` : `position: Number(lists.site_currency_position) === 5 ? 'left' : 'right'` (+ espace insécable avant €). 1 fichier non-frozen, impact toute la borne.

### [P1] `KioskInactivityOverlayComponent.vue:38,46` + `ds/KsButton.vue:17` — boutons « Je suis là » / « Abandonner » de l'overlay d'inactivité rendus VIDES (zéro texte, zéro nom accessible)
- **reproduction:** sur /kiosk/categories, rester inactif `idleMs-confirmMs` (défaut 150 s ; test accéléré via persistance `vuex.kioskSettings.idleMs=15000/confirmMs=10000`). Overlay apparaît → deux pastilles 80×110 / 84×110 **sans aucun libellé** (`innerText=""` mesuré).
- **evidence:** shot 10. `KsButton.vue` n'a **pas de prop `label`** (props = variant/size/disabled/loading/fullWidth/icon/type, l.43-67) et rend uniquement `<span class="ks-btn__label"><slot/></span>` (l.17) ; l'overlay passe `:label="$t('kiosk.inactivity.stay') || 'Je suis là'"` sans contenu de slot → bouton vide. Échec axe `button-name` (POLICY §3) + le client ne peut pas distinguer « rester » d'« abandonner » sur l'écran qui efface son panier.
- **recommendation:** dans l'overlay (non-frozen), passer le texte en slot par défaut (`<KsButton …>{{ $t('kiosk.inactivity.stay') || 'Je suis là' }}</KsButton>`) ; option durcissement : prop `label` officielle dans KsButton rendue en fallback du slot.

### [P1] page /kiosk/categories (sidebar) — catégorie « Tacos Signature » publiée avec 0 produit → cul-de-sac écran blanc
- **reproduction:** flux idle → À emporter → taper « TACOS SIGNATURE » dans la sidebar → zone produit = « 0 produit » + vide blanc total (ni illustration, ni CTA retour).
- **evidence:** shot 04. DB : sous-catégorie 21 vivante (`parent_id=5`, `deleted_at NULL`) ; son unique item « Tacos Signature XL » (id 76) **soft-deleted 2026-06-10** → projection le filtre, mais `KioskMenuService.php:65-74` (`$visibleCategories`) ne masque pas les catégories sans item visible. Les catégories E2E-CAT-*, elles, sont absentes uniquement parce que soft-deleted. Checklist §3-18 (état vide illustré) + §3-5 (pas de cul-de-sac) violées.
- **recommendation:** (a) filtrer côté projection les catégories à 0 item visible (KioskMenuService, non-frozen) ; (b) état vide illustré + CTA « Voir une autre catégorie » dans `KioskCategoriesComponent` (non-frozen). Court terme data-ops : republier/retirer la sous-catégorie.

### [P2] items pseudo-upsell (ids 1,2,3) exposés comme produits commandables dans « Sandwich Cayenne », avec description anglaise « Upsell item » et image absente
- **reproduction:** catégorie par défaut « Sandwich Cayenne » → cartes « BOISSON SEULE », « FRITES SEULES » (carrés blancs, `naturalWidth=0`) et « MENU (FRITES + BOISSON) » ; sous-titre affiché au client : **« Upsell item »** (EN brut). Tap sur « Menu (Frites + Boisson) » → wizard s'ouvre, commandable seul à 3,00 €.
- **evidence:** shots 03 + 12. `database/seeders/MenuSeeder.php:505` seed `description => 'Upsell item'` ; DB items 1-3 `item_category_id=1`, `is_upsell=0` (le flag existe mais n'est pas posé), `description='Upsell item'`. Checklist §3-19 / §4 (anglais résiduel) + grille avec tuiles cassées (images vides).
- **recommendation:** data-ops owner : description FR + images, OU poser `is_upsell=1` et exclure ces items de la grille catalogue dans la projection (décision owner : sont-ils des produits vendables seuls ?).

### [P2] `KioskLoginComponent.vue:150` — écran /kiosk/login 100 % dark-mode (mandat light 100 % kiosk)
- **reproduction:** atteignable seulement si l'auto-login échoue (en local, /kiosk/login redirige vers /kiosk/idle — vérifié, shot 02 = idle) ; revue code.
- **evidence:** `.kiosk-login-screen { background: linear-gradient(160deg, #0f0f1a 0%, #1a1a2e 100%) }` (l.150) + carte translucide sombre ; hover bouton `#c0001a` hors palette (l.294) ; textes 0.74-0.78 rem <14 px (l.247, 257, 317). POLICY §1 « Kiosk = light mode 100 % ». Surface quasi-opérateur (setup machine), d'où P2 et non P1.
- **recommendation:** repasser l'écran en light Cayenne (fond clair, texte `#1A1A1A`, CTA `#F4501E`) lors d'un lot cosmétique.

### [P2] `KioskCategoriesComponent.vue:719` — badge « Nouveau » mappé sur `is_featured == 5` (sémantique fausse)
- **reproduction:** cartes « Boisson Seule »/« Frites Seules »/« Menu (Frites + Boisson) » portent « Nouveau » alors que `items.is_new=0` (produits seedés d'origine).
- **evidence:** shot 03 ; `getProductBadge` l.719 `if (product.is_featured == 5) return $t('kiosk.catalog.badge_new')` — alors que le badge dédié `is_new` existe déjà l.730 (`productBadges`). Un produit « mis en avant » est étiqueté « Nouveau » à vie.
- **recommendation:** l.719 → badge « Populaire »/« Mis en avant » (clé i18n dédiée) ou conditionner à `is_new`.

### [P3] /kiosk/idle — dominante sombre de l'écran d'accueil vs checklist §3-10 (« jamais d'écran à dominante sombre »)
- **evidence:** shot 01 : fond brun-noir + glow orange (refonte V2.B « Bold Appétissant », validée 2026-04-27 `KIOSK_DESIGN_VALIDATION`). Tension mandat light-mode ↔ design attract validé — arbitrage owner, pas de fix unilatéral. CTA « À emporter » 400×156 ✓ mais discret (carte blanche moyenne, hint 15 px <20 px borne, `KioskIdleScreenComponent.vue:555`).

### [P3] bottom-bar catalogue — « Abandonner ma commande » (648×70, fort contraste) plus proéminent que « Payer » (432×70, gris disabled) panier vide ; indicateur panier h=47 px <48 px
- **evidence:** shot 03 + mesures DOM (`kiosk-categories-abandon` 648×70 ; `kiosk-categories-cart-indicator` 944×47 disabled). Action destructive dominante (anti-pattern §4) ; cible <48 px (désactivée, donc mineur).
- **recommendation:** dé-emphaser « Abandonner » (ghost/outline) et monter l'indicateur panier à ≥48 px.

### [P3] console au boot borne : 401 + 429 (rate-limit auto-login) répétés
- **evidence:** 4 erreurs réseau capturées au premier chargement (`Failed to load resource: 429/401`). L'UI gère (message FR D-007), mais bruit systématique au cold-start à surveiller en prod.

### [OBS / FROZEN-GATE — aucune édition proposée] wizard « QUEL SUPPLÉMENT ? » affiche des prix d'option (`€1,00`) + total sur une étape de composition
- **evidence:** shot 12 (ouvert depuis « Menu (Frites + Boisson) »). `DESIGN_REFERENCES` §4 liste « prix sur une étape de wizard » comme anti-pattern n°1 et POLICY §5 interdit « le prix d'une option/étape ». Le prix est joint depuis le catalogue (SSOT backend intact — affichage seulement), et `KioskWizardComponent` est FROZEN : remonté pour arbitrage brain/owner, pas de recommandation d'édition.

---

## ✅ Conformes (vérifiés)

- **Guards navigation** : accès direct `/kiosk/categories` et `/kiosk/login` → redirection propre `/kiosk/idle` (auto-login local OK).
- **Flux client** : idle → « À emporter » → `/kiosk/categories?cat=1` fluide (~1 s), transition douce.
- **Promo carrousel** : 1 promo réelle DB (`BORNEAUDIT5`, amount 5 €, active) rendue ; **aucune promo fantôme** ; composant masqué si 0 promo (`v-if`, l.3) ; marquee désactivée reduced-motion.
- **Catalogue** : 12 catégories réelles DB (Sandwich Cayenne, Tacos Signature, Galette, …, Boissons) avec vraies images ; sous-catégorie rendue dans la sidebar ; état sélectionné très net (carte orange, shot 09) ; produits réels DB uniquement (E2E-ADMIN-* et soft-deleted bien filtrés).
- **Grille produits** : tuiles 435 px homogènes, images 800×800 nettes, noms ≤2 lignes, descriptions tronquées 68 c, bouton « + » 64×64 ≥48 ✓, scroll tactile OK jusqu'en bas (shot 08), sidebar items 113×122 ✓, light-mode 100 % sur tout le catalogue ✓, FR partout (sauf « Upsell item » ci-dessus).
- **Badges** : « Personnaliser » sur items à options ; badges allergènes compacts présents sur les produits qui en déclarent (composant `KsAllergenBadge` branché).
- **Inactivité** : overlay « Toujours là ? » déclenché à `idleMs-confirmMs` ✓, décompte FR « …effacée dans 9 secondes » live ✓, pluriel seconde/secondes géré, auto-reset → `/kiosk/idle` ✓, **panier vidé** (persistance 0 item vérifiée) ✓, routes sans timer (idle/waiting/payment/confirmation) correctes (`KioskAppComponent.vue:881`).
- **CatalogChangeToast** (code-review) : câblé dans KioskAppComponent (l.13-19), `label.dismiss` = « Fermer » présent fr.json:462, auto-dismiss 5 s, reduced-motion géré — pas de label brut attendu.
- **A11y entrée** : bouton réglages a11y 56×56 ✓, `role=group` + aria sur choix de mode, sélecteur langue masqué (FR-lock ADR-007) ✓.

## Verdict
**3 P1 / 3 P2 / 3 P3 + 1 observation frozen-gate.** Catalogue borne globalement solide (light-mode, vraies données, touch targets) ; les 3 P1 sont à fort impact client et **tous corrigeables hors zones frozen**.
