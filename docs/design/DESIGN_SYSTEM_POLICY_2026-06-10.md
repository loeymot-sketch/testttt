# Design System — POLICY (a11y · contraste · i18n · NF525)

> **UX-5 (ultraplan 2026-06-10).** Couche *policy* du design-system. Les **tokens / fondations**
> vivent dans [`DESIGN_SYSTEM_FOUNDATIONS_CV1.md`](DESIGN_SYSTEM_FOUNDATIONS_CV1.md) ; ce
> document fixe les **règles transversales** que toute surface admin/POS/kiosk/KDS/OSS doit
> respecter. SSOT pour les revues a11y/contraste et la non-régression i18n.

## 1. Palette de marque (mandat owner, CLAUDE.md §3bis)
- **Admin / POS / Kiosk** : Cayenne — primary `#F4501E` (orange), accent `#FFB800` (jaune),
  dark `#1A1A1A`. Kiosk = light mode 100% (dark désactivé, mandat owner).
- **Mobile standalone** : NOIR / ORANGE / JAUNE / BLANC — **NE PAS appliquer `#F4501E`**.
- La couleur de marque est **immuable sans gate owner**. Voir §2 pour la tension AA.

## 2. Contraste (WCAG 2.1 AA — politique)
- **Texte normal ≥ 4.5:1**, texte large ≥ 3:1, composants/icônes ≥ 3:1.
- **Tension marque vs AA (connue, gate owner)** : `#F4501E` sur blanc = **3.49:1** (< AA texte
  normal). C'est la couleur de marque mandatée → **ne pas la modifier unilatéralement**. La voie
  propre = token **additif** `--fk-brand-text` (vert dans l'ultraplan UX-6) qui assombrit *le texte*
  sans toucher la surface de marque. Décision owner.
- **Verts/CTA NON-marque = corrigeables sans gate.** Précédent 2026-06-10 : le vert de publication
  composer `#1ab759` sur blanc (**2.63:1**, fail) a été assombri en **`#15803d`** (≈4.5:1 AA) sur les
  *fonds à texte blanc* — les bordures/textes verts `#138445` sur blanc (déjà conformes) restent.
  Règle : **un vert/bleu/gris fonctionnel qui échoue AA se corrige ; seul l'orange de marque est gaté.**

## 3. Accessibilité — composants (politique)
- **Boutons icône-seule** (fermeture modale `fa-xmark`, édition, suppression, upload) **DOIVENT**
  porter un `aria-label` (+ `title`). Une tooltip masquée en CSS **ne compte pas** comme nom
  accessible (axe `button-name`). Pattern de référence : `SmIconDeleteComponent` (`:aria-label`).
  Sweep 2026-06-10 : 11 drawers admin (`*CreateComponent`) + edit-icon + close-btn item → nommés.
- **Inputs** : tout `<input>` a un `<label for>` associé (ou `aria-label`). Précédent : `#image`
  (upload article) → `<label for="image">`.
- **Dette résiduelle connue** (sweep séparé, non bloquant V1) : `fa-xmark` icône-seule encore
  présent dans d'autres modales admit hors périmètre ; `role="menu"` du profile-menu admin-shell
  sans `menuitem` enfants (`aria-required-children`).
- **Gate CI** : `AxeCoreCriticalGateSentinel` doit couvrir les modales admin (ultraplan UX-2) ;
  `button-name` + `label` critical = bloquant.

## 4. i18n (parité 5 langues)
- Namespaces partagés (ex. `studio.*`) : **parité de clés** fr ⟷ en/de/bn/ar imposée par
  `studioFrontendI18nParity`. Ajouter une clé fr = l'ajouter aux 5 (miroir fr acceptable si non
  traduit — V1 LOCAL est FR).
- **Pas de clé i18n dynamique en littéral-préfixe** : `$t('ns.' + var)` expose un préfixe nu à la
  sentinelle de fuite (faux-positif). Construire la clé dans une **variable** puis `$t(key)` +
  fallback lisible. Idem : **ne pas écrire le motif `$t('ns.…')` dans un commentaire** (la
  sentinelle le capture).
- **0 label brut** en UI : pas de `label.x` / `kiosk.foo` / token snake_case (`item_attribute`)
  visible — humaniser via libellé i18n + fallback `replace(/_/g,' ')`.

## 5. NF525 — invariant design (non négociable)
- **Aucun prix sur une étape de composition** (wizard/builder). Le `composer_profile` est
  *price-free* par construction (sentinelle `MenuProjectionComposerProfileTest`). Le prix est joint
  par `id` depuis le catalogue, calculé **100% backend** (`PricingService`, SSOT).
- Un diff/preview/aperçu de wizard **n'affiche jamais** de prix de step (ex. `ComposerPublishDiffModal`
  `COMPARED_FIELDS` ne contient aucun champ prix). Le prix de l'**item** (aperçu produit) est OK ;
  le prix d'une **option/étape** est interdit.

## 6. Zones frozen (rappel design)
Les wizards `KioskWizardComponent` (borne) et `pos-wizard.js`/`pos-wizard.css` (caisse) sont
**frozen §7** : amélioration UI **via la projection/données** ou **owner-gate + LOCK**, jamais par
édition directe. Les composants `kiosk/steps/*.vue` sont **non-frozen** (densité/affordance OK).

---
*Établi 2026-06-10 (ultraplan UX-5). Consolide les décisions des lots VADV-1..5, UX-1..6, CMS-UX-2,
BU-01/02 et le verdict wizard-best. Référence tokens : `DESIGN_SYSTEM_FOUNDATIONS_CV1.md`.*
