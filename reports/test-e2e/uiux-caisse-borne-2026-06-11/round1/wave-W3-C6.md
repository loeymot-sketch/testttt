# Wave W3 — SCOUT C6 : Borne · Wizard composition + Design System kit

**Date** 2026-06-11 · **App** http://127.0.0.1:8768 (APP_ENV=e2e → DB jetable `foodking_e2e`, vérifié via PID 38797) · **Viewport** 1080×1920 fr-FR Chrome.
**Scripts jetables** `tests/e2e/_w3-c6-{recon,recon2,wizard-flows,menu-step-probe,menu-step-probe2,edges,loyalty-kb,loyalty-kb2,frites-probe,register-blank,reg-probe}.mjs` · **25 screenshots** dans `shots-c6/` (tous lus/analysés).
**READ-ONLY code respecté** — 1 mutation DB jetable (rupture Cheddar item 12) posée puis **restaurée**.

## Quel composant wizard rend réellement ?
**`KioskWizardComponent.vue` (FROZEN) est l'unique renderer.** Preuves :
- `KioskPosWizardComponent.vue` = wrapper pass-through 18 lignes (`<KioskWizardComponent v-bind="$attrs"/>`), donc le flag `kioskUsePosWizard: true` (servi, `master.blade.php:184`) ne change PAS le moteur.
- En pratique le wizard s'ouvre **inline en overlay** depuis `KioskCategoriesComponent.vue:292` (URL reste `/kiosk/categories?cat=N` pendant toute la composition) — la route `kiosk.wizard` (`kioskRoutes.js:178`) n'est pas le chemin nominal client.
- Steps = `kiosk/steps/*.vue` (NON-frozen, policy §6) : Viande/Sauce/Garnitures/Supplements/Menu/FritesStyle/GenericChoices observés.

## Parcours exécutés (produits DB réels)
- **Tacos (26)** : viande(1/1, bloquant ✓) → sauce(10 choix) → QUEL MENU (modes + boisson incluse + sauce frites) → récap → panier **€11,50** ✓
- **Sandwich Cayenne (22)** : viande → sauce → crudités(opt) → suppléments(9) → récap → panier €7,00 ✓
- **Bowl Frites Poulet curry (42)** : sauce(1/2) → suppléments → menu → (abandon volontaire) ✓
- **Petite Frites (33)** : « Choix du style » (profil 6) ✓ · **Frites Seules (2)** : wizard 2 étapes suppléments→récap ✓ · **Menu Nuggets (40)** : ajout direct SANS wizard.
- Edge : abandon mi-parcours, rupture, fidélité/clavier, panneau a11y, theme toggle.

---

## FINDINGS

### P1 (1)

**C6-01 — Fidélité « S'inscrire » = écran 100% blanc irrécupérable (toute locale).**
Repro : panier non-vide → « Mon compte » → `/kiosk/loyalty` → tap `.kiosk-loyalty-register-btn` → **`document.body.innerText === ""`**, 0 input, persiste >6 s, aucun retour possible (header inclus démonté). Console : **`SyntaxError: Invalid linked format`** (vue-i18n). **Root cause vérifiée** : `kiosk.loyalty_screen.placeholder_email = "vous@exemple.fr"` — `@` brut = préfixe *linked message* vue-i18n → le rendu de l'étape `register` (`KioskLoyaltyComponent.vue:82-133`) jette, Vue démonte tout le sous-arbre. Présent dans **les 5 locales** (`resources/js/languages/{fr,en,de,ar,bn}.json`). Conséquence : inscription fidélité borne **morte** + clavier AZERTY `KsVirtualKeyboard` injoignable en situ (layout AZERTY vérifié dans le code : rangées a-z-e-r-t-y / q-s-d-f…m + digits, `KsVirtualKeyboard.vue:108-124`). Heal non-frozen trivial : `vous{'@'}exemple.fr`. Preuves : `25-register-blank-proof.png`, console capturée.

### P2 (5)

**C6-02 — Prix affichés SUR les étapes du wizard** ⚠️ à arbitrer vs policy NF525.
Constaté live : suppléments « €0,90 / €1,00 » par carte (`KioskStepSupplementsComponent.vue:58 formatPrice(supplement.price)` + badge total ligne 7), menu « +€3,00 » (`kiosk-menu-price`, KioskStepMenuComponent), viandes payantes `+formatPrice` (`KioskStepViandeComponent.vue:46`), et **labels DB avec prix en dur** : « Suppléments (0.90€) » (`item_wizard_steps` profils publiés 8-15). La policy §5 dit littéralement « le prix d'une **option/étape** est interdit » et checklist #11 « Aucun prix affiché sur une étape de wizard ». MAIS masquer le prix d'une option **payante** heurte l'info-consommateur (le client doit savoir avant de payer) ; l'affichage vient du catalogue (SSOT backend intact, aucun prix dans composer_profile). → **[ESCALADE-OWNER]** : clarifier la policy (« prix interdit » = prix par étape de la *composition incluse*, vs options payantes ?) avant tout heal. Steps non-frozen ; ne PAS toucher KioskWizardComponent. Preuves : `10-…-suppl-ment.png`, `18-menu-step-drink-selected.png`, DB.

**C6-03 — Format monétaire borne anglo-saxon « €2,00 » (symbole avant) partout.**
Tuiles catalogue (`kiosk-product-price-*`), total bas de page, « Total €8,50 » nav wizard, récap, panier. Attendu FR `2,00 €` (NBSP avant €) — checklist #12, anti-pattern §4 (cousin du POS-ERG-07 connu). Le helper canonique `resources/js/helpers/formatPrice.js` rend déjà « 19,00 € » mais le kiosk garde son mixin `kioskFormatPrice` (currency Vuex). Surfaces non-frozen (Categories/Cart) healables ; les rendus dans KioskWizardComponent = frozen → via mixin partagé.

**C6-04 — Catégorie vide « TACOS SIGNATURE » exposée au client.**
Sidebar affiche la catégorie 21 (0 produit, DB confirmée) → zone produits = « 0 produit » sur fond nu, sans illustration ni CTA (viole checklist #18 empty-state). Heal : filtrer les catégories sans produit côté projection/`KioskCategoriesComponent` (non-frozen). Preuve : recon dump + DB.

**C6-05 — Rupture (86) non propagée aux choix du wizard.**
Item « Cheddar » (12) flaggé `is_available=0` (branch 1) → le supplément CHEDDAR du wizard reste affiché/sélectionnable. Cause structurelle : steps suppléments = `source_type=extra_group` + **`stockable_choices=0`** (`item_wizard_steps` profil 34) → aucun lien stock. Le path OOS existe pourtant (badge « Épuisé » `pos.item_86_d`, `KioskStepViandeComponent.vue:33-53`). Incohérence gérant : 86 un fromage au stock ≠ borne continue de le vendre en supplément. Data/config + projection ; mutation restaurée. Preuve : `22-supplements-cheddar-oos.png` (CHEDDAR toujours présent).

**C6-06 — Drawer a11y : section « THÈME » morte + titre stale.**
`KsA11ySettings` (ouvert via bouton idle 56×56) affiche le titre « THÈME — Bascule entre l'affichage clair et sombre » mais le contrôle `KsThemeToggle` est tué par `resources/css/kiosk-fallback.css:17-18` (`display:none!important`) → section sans contrôle (confusion client). Titre drawer « Accessibilité **& langue** » alors que la langue a été retirée (ADR-007) ; hint « Lecture vocale (FR/EN) » sur borne FR-lock. **Bonne nouvelle mandat owner : light-mode 100% EST appliqué** — le toggle standalone (frozen `KioskAppComponent.vue:21-30`) ET le toggle drawer sont neutralisés par le kill-switch CSS + variables dark forcées light (`app.css`), vérifié computed-style live (`display:none`, w/h=0). Pas de dark-mode atteignable → pas d'escalade ; heal cosmétique dans KsA11ySettings.vue (non-frozen). Preuve : `02-a11y-panel.png`.

### P3 (6)

- **C6-07** Labels 12px sur tuiles boisson/sauce-frites (`kiosk-boisson-name`, computed 12px, tuile 242×190) — sous le plancher 14px fonctionnel, loin du corps ≥20px borne (checklist #8). Step Menu (non-frozen).
- **C6-08** Dérive DS kit : **KsFilterChip et KsHero = 0 consommateur** ; KsChip/KsStepper enregistrés globalement (`ds/index.js`) mais jamais montés (le stepper wizard est bespoke). Poids bundle + risque de drift. Adoption réelle : KsButton×6 (écrans erreur), KsModal×1, KsAllergenBadge×3, KsBadge/KsCartBottomSheet (categories), KsConsentModal/KsVirtualKeyboard (loyalty), KsA11ySettings (idle).
- **C6-09** Idle à dominante sombre (gros aplat brun central, `01-idle.png`) vs policy « jamais d'écran à dominante sombre » — probable branding « Bold » assumé → confirmation owner, pas de heal.
- **C6-10** « Mon compte » sans panier → atterrit sur PANIER VIDE (`/kiosk/cart`, garde `requireCart`) — label trompeur (l'empty-state panier lui-même est conforme : illustration + CTA, `24/`).
- **C6-11** Toast technique « Session rafraîchie automatiquement » visible client en pleine composition (`18-…png`) — jargon, à reformuler/masquer.
- **C6-12** Bruit console permanent : 401 (machine creds) à chaque page + warnings répétés `[kiosk-wizard.composer] step skipped viande_2` (profil cat-1 : step `viande_2` sans choix sur les items non-Big).

### Conformités notables (vérifiées)
Min/max bloquants avec hints FR clairs (« Sélectionnez 1 viande pour continuer », « Sélectionnez une boisson… ») ✓ · Retour/Abandonner visibles à chaque étape, modale d'abandon **symétrique** 350×50/350×50 ✓ · panier cohérent après abandon (0→0 ; aucun article fantôme) ✓ · stepper visuel icônes + barres + ‹1 2 3 4› ✓ · touch ≥48px partout (cartes 242-504px, nav 52px — sous la reco 80px borne, note) · numpad fidélité 170×64 ✓ · composition live (« VOTRE COMPOSITION » chips) ✓ · récap riche (Inclus/Gratuite, qty, note 190c) ✓ · rien dans le DOM wizard ne vient du composer_profile avec prix (SSOT OK).

**Artefacts DB jetable** (non-findings) : extras `ExtraX/ExtraY` actifs (ids 251/252), promo `BORNEAUDIT5`, images produits manquantes (tuiles blanches cat 1).

---

## Synthèse
**Comptes : 1 P1 · 5 P2 · 6 P3.**
**Top 3 :**
1. **C6-01 (P1)** — « S'inscrire » fidélité = écran blanc total, root-cause `@` non échappé dans `placeholder_email` (5 locales), heal 1-ligne non-frozen.
2. **C6-02 (P2, ESCALADE-OWNER)** — prix d'options affichés sur les étapes wizard (steps + labels DB « (0.90€) ») : contradiction littérale avec la policy 0-prix-sur-étape ↔ info-consommateur ; arbitrage requis avant heal.
3. **C6-03 (P2)** — format monétaire `€2,00` au lieu de `2,00 €` sur toute la borne (helper FR canonique existe déjà).

**Renderer wizard : `KioskWizardComponent.vue` (frozen), monté inline par `KioskCategoriesComponent` ; `KioskPosWizardComponent` n'est qu'un alias.** Light-mode 100% confirmé appliqué (kill-switch CSS). ✅
