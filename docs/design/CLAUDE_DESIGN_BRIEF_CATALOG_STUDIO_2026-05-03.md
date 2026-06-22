# Claude Design — Brief: FoodKing Catalog Studio (Catégories · Produits · Wizards · Stock)

| Champ | Valeur |
|---|---|
| Date | 2026-05-03 |
| Auteur | Claude (orchestrateur FoodKing) |
| Cible | Outil **Claude Design** (Anthropic) |
| Surface design demandée | **Admin Dashboard / Catalog Studio** (web responsive desktop-first) |
| Style attendu | **Shopify-like, plug-and-play, ultra simple** (ajouter / modifier / supprimer en quelques clics) |
| Hors-scope | Caisse runtime, Borne client, KDS — déjà designés ailleurs |

Ce document a deux usages :
1. **Lister les fichiers** à attacher à Claude Design comme **contexte de référence** (data model + design system + composants existants).
2. Fournir **le prompt exact** à coller dans Claude Design pour qu’il génère le design complet et exploitable.

---

## 1. Files à attacher à Claude Design (paquet de contexte)

> Règle : on **n’envoie pas le repo entier**. On envoie un sous-ensemble dense, suffisant pour qu’il comprenne le **modèle de données**, le **design system** déjà en place, et les **composants existants** qu’il doit faire évoluer (pas remplacer).
>
> Ordre = ordre d’importance. Si l’UI Claude Design limite le nombre de fichiers, garder au minimum les 6 premiers blocs.

### A. Brief + doctrine produit (à toujours inclure)
- `docs/design/CLAUDE_DESIGN_BRIEF_CATALOG_STUDIO_2026-05-03.md` *(ce fichier — contient le prompt §3)*
- `CLAUDE.md` *(rôle, principes, ce qui est non-négociable)*
- `AGENTS.md` *(routing multi-agents, frozen zones, gates — pour qu’il n’aille pas casser un invariant)*

### B. Design system existant (à respecter, pas à réinventer)
- `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md` *(tokens sémantiques, conventions, anti-patterns)*
- `resources/css/foundations/cv1-tokens.css` *(tokens CSS officiels admin)*
- `borne (Remix)/kiosk-ds/tokens.css` *(tokens borne / kiosk — pour cohérence visuelle marque)*
- `docs/a11y/A11Y_CHECKLIST_CV1_WCAG_AA.md` *(contraintes accessibilité)*

### C. Modèle de données réel (catégories · produits · wizard · stock)
- `database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php`
- `database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php`
- `database/migrations/2022_11_17_110541_create_item_attributes_table.php`
- `database/migrations/2022_11_17_110650_create_item_extras_table.php`
- `database/migrations/2022_11_17_120627_create_item_addons_table.php`
- `app/Models/ItemWizardStep.php`
- `app/Models/ItemWizardProfile.php` *(si présent — sinon ignorer)*
- `app/Http/Requests/ComposerProfileRequest.php`
- `app/Http/Requests/ComposerStepRequest.php`
- `app/Http/Resources/ComposerStepResource.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerTemplateService.php`
- `app/Services/Composer/ComposerProfileProjection.php`

### D. Composants Vue existants (point de départ visuel)
- `resources/js/components/admin/items/CatalogStudioComponent.vue` *(la page actuelle — à upgrader visuellement)*
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue` *(toggle stock par produit)*
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` *(éditeur wizard 3-colonnes)*
- `resources/js/components/admin/items/composer/StepEditorComponent.vue` *(édition d’une étape de wizard)*
- `resources/js/components/admin/items/composer/ComposerStepListSidebar.vue`
- `resources/js/components/admin/items/composer/ComposerStepFormPanel.vue`
- `resources/js/components/admin/items/composer/ComposerTemplatePickerModal.vue`
- `resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue` *(création produit guidée 9 étapes)*
- `resources/js/components/admin/items/ItemPreviewComponent.vue` *(aperçu POS + Kiosk d’un produit)*
- `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` *(état stock + auto-86)*

### E. Surfaces consommatrices (POS monolithique 1 page · Kiosk multi-pages)
- `public/js/pos-wizard.js` *(runtime POS wizard mono-page)*
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` *(wizard borne multi-pages)*
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` *(exemple d’étape borne)*

### F. i18n — pour qu’il sache que l’app est multi-langues (FR primaire)
- `resources/js/languages/fr.json` *(ou un extrait `studio.*` si trop volumineux)*

### G. Captures d’écran utiles à joindre (si dispo)
- `reports/screenshots/kiosk-wizard-live-composition-2026-05-01.png`
- `reports/screenshots/pos-cashier-runtime-2026-05-01.png`
- *(Toute capture récente du Catalog Studio actuel — pour qu’il voie l’état initial)*

---

## 2. Modèle mental à transmettre (résumé qu’on intègre dans le prompt)

### 2.1 L’arbre central
Une seule SSOT côté backend qui est ensuite **projetée** vers POS et Kiosk :

```
Catalog
├── Catégories (ItemCategory) ────── ordre, visible_on, branch overrides
│   └── Produits (Item) ──────────── prix HT/TTC SSOT backend, photo, statut
│       ├── Variations (ItemVariation)
│       ├── Extras (ItemExtra)               ← groupes (sauces, crudités…)
│       ├── Addons (ItemAddon)               ← rôles: drink/side/dessert/menu_component/upsell
│       ├── Attributs (ItemAttribute)        ← ex. viandes du tacos
│       └── Wizard Profile (ItemWizardProfile, versionné, publié/brouillon)
│           └── Wizard Steps (ItemWizardStep)  ← visible_on=[pos,kiosk,web], stockable_choices, addon_role
└── Stock & Disponibilité (parallèle, pas dans l’arbre catalogue mais lié)
    ├── ItemBranchAvailability (toggle 86 / unavailable_reason)
    ├── StockLevel + StockMovement (idempotency_key)
    └── Auto-86 préventif (php artisan stock:scan-rupture)
```

### 2.2 Wizard runtime — deux surfaces, **une seule** définition
- **POS (caisse)** : **monolithique, une seule page**, dense, optimisée pour vitesse caissier.
- **Kiosk (borne client)** : **multi-pages**, accompagnement, gros boutons, hiérarchie claire.
- Les deux consomment le **même** `ItemWizardProfile` + `ItemWizardStep[]`. La différence est portée par `visible_on=[pos|kiosk|web]` sur chaque étape.

### 2.3 Promesse UX (équivalent Shopify)
- *Tu mets ça, tu fais ça, tu rajoutes, tu supprimes.*
- Un seul écran (Catalog Studio) couvre **catégories + produits + wizard + stock parallèle**.
- Toute action critique a : **état clair**, **annulation**, **publication explicite**, **diff visible** (brouillon vs publié pour le wizard).

---

## 3. PROMPT à coller dans Claude Design

> Copie tout le bloc ci-dessous tel quel dans Claude Design, après avoir attaché les fichiers du §1.
> Le ton est volontairement directif : Claude Design produit un meilleur design quand on lui donne des contraintes précises, des écrans cibles nommés, et un *what good looks like*.

```text
You are designing the FoodKing **Catalog Studio**: a single, unified admin dashboard
where a non-technical restaurant manager can manage Categories, Products, Product
Wizards (POS + Kiosk), and parallel Stock — Shopify-grade simplicity, plug-and-play.

## Mission

Replace the current scattered admin (separate pages for items, categories, item
attributes, item extras, item addons, composer editor, stock dashboard) with ONE
cohesive workspace. Goal: a manager can in under 60 seconds:
- create a new category,
- add a product to it (name, price, photo),
- attach a wizard (with predefined templates: simple / sandwich / tacos / assiette
  / snacking / menu / custom),
- toggle availability per branch,
- preview how the product will render on POS (single-page) and Kiosk (multi-page),
- publish.

The design must feel like Shopify or Linear: dense but calm, instantly readable,
no jargon, every destructive action confirmed, every async state visible.

## Hard constraints (non-negotiable)

1. Visual language must extend the existing FoodKing CV1 design system. Use the
   tokens defined in `resources/css/foundations/cv1-tokens.css` and the rules in
   `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md`. Do not invent a new palette.
   Brand colors come from `borne (Remix)/kiosk-ds/tokens.css` (FoodKing red
   #E8001C and warm neutrals) — apply them with restraint on the admin (admin is
   neutral; brand color is reserved for primary CTA, kiosk preview).
2. WCAG AA minimum, AAA on critical paths (publish / delete / availability).
   Focus rings are 3px, contrast ≥ 4.5:1, motion is 200ms with reduced-motion
   neutralization.
3. Backend is the source of truth for prices. The UI shows prices, never
   computes them. Tax label must say TTC (France VAT) — never recompute HT
   from a TTC value on the client.
4. `branch_id` is a hard data boundary. Every product/availability view must show
   which branch scope is active and never mix data across branches.
5. Wizard steps have a fixed schema:
   - step_key (slug)
   - label (display)
   - source_type ∈ { item_attribute, extra_group, addon }
   - source_ref (string) AND source_item_attribute_id (typed FK, nullable)
   - min_select / max_select (with constraint min ≤ max)
   - allow_repeat (bool)
   - visible_on (array subset of [pos, kiosk, web])
   - stockable_choices (bool)
   - position (int, drag-and-drop reorder)
   - is_active (bool)
   - addon_role (nullable enum: drink/side/dessert/menu_component/upsell)
6. Two consumer surfaces share the SAME wizard profile:
   - POS = single-page wizard (cashier speed)
   - Kiosk = multi-page wizard (customer journey)
   Each step picks where it appears via visible_on.
7. Templates are first-class: choosing the "tacos" template prefills steps
   (viande, sauces, crudités, accompagnement, boisson). Manager can then tweak
   without writing JSON.
8. Stock lives in PARALLEL: the Catalog Studio shows availability inline on each
   product card (a toggle + reason), and offers a side panel "Stock health"
   that summarizes auto-86 events, low-stock alerts, last scan timestamp. Stock
   movements are NOT edited here — only viewed and one-click resolved.

## Screens to design (deliver each as a polished mockup + component spec)

### Screen 1 — Catalog Studio Home (the single page)
Layout: 3 zones.
- Left rail (260–300px): Category tree.
  - Search categories.
  - Drag-and-drop reorder.
  - "+ New category" inline.
  - Each row: name, count of products, ⋮ menu (rename / hide / delete).
- Center (flex): Product grid for the selected category.
  - Toolbar: search by product, filter by status (active/draft/86'd), filter by
    branch, sort.
  - Product card (192×240 approx): photo, name, category chip, price, status
    pill, availability toggle inline, "Configure wizard" button, ⋮ menu.
  - "+ New product" tile (always first), opens an inline quick-create panel
    (name + price + photo upload + template dropdown).
- Right rail (340–380px, collapsible): contextual inspector.
  - When nothing selected: shows "Stock health" summary (last scan, auto-86
    count, low-stock list, "Run scan" button).
  - When a product is selected: tabbed inspector (Details / Wizard / Stock /
    Channels). Tabs are scannable, never modal-only.

Top header: workspace title, branch selector, "Publish all changes" sticky CTA
when there are unsaved drafts.

### Screen 2 — Product Quick Create (inline, no modal jail)
A 2-step inline form embedded in the center column, never a full-page redirect.
Step 1: Identity (name, category, photo drop-zone, short description).
Step 2: Pricing & template (price TTC, VAT preset, template picker with visual
cards for simple / sandwich / tacos / assiette / snacking / menu / custom).
On save: creates the product as draft, opens it in the inspector "Wizard" tab.

### Screen 3 — Wizard Editor (the heart of the experience)
A single page, 3 columns:
- Steps list (left): drag-and-drop, each step shows label, source_type chip,
  visible_on chips (POS / Kiosk), addon_role chip if any. Click reorders, ⋮
  duplicates / deletes, "+ Add step" at bottom, "Choose template" at top.
- Step editor (center): big readable form. Source type is a 3-way segmented
  control (Item attribute / Extra group / Addon) — selecting one swaps the
  picker (a real picker, no free text). Min/max are sliders with a paired
  numeric input. Allow repeat is a switch. Visible_on are 2 toggle pills (POS,
  Kiosk). Stockable choices is a switch with a tooltip explaining auto-86.
  Addon role is a styled radio group with icons.
- Live preview (right): two phone/tablet frames stacked. Top = POS (single-page
  preview, dense). Bottom = Kiosk (multi-page preview with pagination dots).
  The preview refreshes 500ms after the last edit. Show the version badge
  (Draft / Published v3) and a "Publish" button that opens a diff modal.

### Screen 4 — Publish Diff Modal
Side-by-side: published vN vs draft vN+1. Lines added (green), removed (red),
changed (amber). Confirmation requires ticking a "I checked the diff" checkbox.
Dispatch event ComposerProfileChanged is fired only after publish (don't show
this in UI, but the design must convey "publishing is the moment of truth").

### Screen 5 — Stock Inline & Health Side Panel
- Inline on each product card: availability toggle + small badge with the
  reason if unavailable (out_of_stock, paused_by_manager, scheduled_off).
- Side panel "Stock health": three sections.
  1. Auto-86 events (last 24h) — list with product, branch, reason, "Resolve"
     button.
  2. Low-stock alerts — items at or below threshold; quick-jump to the product.
  3. Last scan summary — timestamp, count scanned, count flagged, "Run scan
     now" button (admin only).

### Screen 6 — Empty / Loading / Error states
For every screen above, design the empty state, the loading skeleton, and the
error state. Errors must offer a clear recovery action ("Retry", "Open ticket",
"Switch branch").

## Components to deliver (with props + states)

- CategoryTreeItem: name, count, drag handle, contextual menu, nested level.
- ProductCard: photo slot, title, category chip, price, status pill, toggle,
  primary action, secondary menu.
- WizardStepRow: drag handle, label, source_type chip, visible_on chips,
  addon_role chip, hover/selected/error states.
- SourcePicker: segmented control + searchable dropdown with create-on-the-fly
  option ("Add new attribute…").
- VisibleOnSelector: two pill toggles (POS / Kiosk), each with tooltip
  explaining what changes.
- StockToggle: switch + reason chip + last-changed timestamp.
- PublishBadge: Draft / Published v{n} / Conflict (when version mismatch).
- DiffViewer: side-by-side, monospace where it matters, color-coded changes.
- HealthCard: title, metric, trend, primary action.

For each component, deliver:
- desktop default state
- hover, focus-visible, active, disabled, loading, error
- responsive behavior (down to 1280px width minimum; 1440 is the design
  baseline)
- a11y notes (aria roles, keyboard order, screen reader labels)

## Information architecture rules

- One workspace, one URL: `/admin/items/studio`. No more bouncing between
  Items, Item Categories, Item Extras, Item Addons.
- Existing legacy pages remain reachable from a single "Advanced settings"
  link in the left rail (so power users aren't blocked), but the Catalog
  Studio is the default landing.
- Every destructive action confirms in-context (no system alert), and is
  undoable for 5 seconds via a toast.
- Every async action shows progress and outcome (toast with severity =
  info / warning / blocker, mapped to the existing cv1-toast tokens).

## What "great" looks like

- A new manager onboards in 5 minutes with no training.
- Creating a tacos product with a 5-step wizard takes under 2 minutes.
- The whole studio fits in one screen on a 1440×900 laptop without horizontal
  scroll.
- Nothing important hides behind a hover.
- Brand red appears 1–2 times per screen, max — it's a punctuation, not
  wallpaper.
- The Kiosk preview actually looks like the kiosk, the POS preview actually
  looks like the POS — real fidelity, not a generic "phone frame".

## Deliverables

1. Hi-fidelity mockups for screens 1–6 (light theme; dark theme later).
2. A component sheet (each component above with all states).
3. Tokens delta — if you need to add new tokens, propose them as additions
   to `cv1-tokens.css` (do NOT redefine existing ones).
4. A short README that maps each screen to the existing Vue component it
   should upgrade, so engineering can wire it without ambiguity:
   - Screen 1 → `CatalogStudioComponent.vue`
   - Screen 2 → quick-create inline (currently inside CatalogStudio)
   - Screen 3 → `ProductComposerEditorComponent.vue` + children
   - Screen 4 → new `ComposerPublishDiffModal.vue`
   - Screen 5 → `StockRuptureDashboardComponent.vue` (as side panel) + inline
     `AvailabilityToggleComponent.vue`
   - Screen 6 → states inside each component above
5. Accessibility notes per screen (focus order, landmarks, reduced motion).

## What NOT to do

- Don't replace POS or Kiosk runtime designs — they exist and are validated.
- Don't introduce a separate "settings" maze — keep one page.
- Don't redesign the language/i18n; assume FR primary, with EN/AR/BN/DE
  available.
- Don't propose a system that requires writing JSON in the UI.
- Don't propose modals for primary tasks — modals are for confirmations and
  diff only.
- Don't use shadows or gradients heavily; CV1 is flat-with-restraint.
- Don't move price calculation to the client.

When you produce the design, label every screen and every component with the
exact filename it maps to in the engineering deliverable list above, so the
implementation team can apply your design directly on the existing Vue
codebase without guessing.
```

---

## 4. Workflow recommandé pour toi

1. **Ouvrir Claude Design**, créer un nouveau projet *FoodKing — Catalog Studio*.
2. **Attacher les fichiers** listés au §1 (au minimum les blocs A, B, C, D — E et F si tu peux).
3. **Coller le prompt** du §3.
4. Laisser Claude Design itérer ; si une 1re passe est trop générique, lui répondre :
   > “Iter again, but tighten on Screen 3 (Wizard Editor) — show a real tacos product with 5 steps prefilled, real source values from `ItemAttribute`, and the live preview on the right showing both POS (single page) and Kiosk (multi-page).”
5. Quand le design est validé, **me renvoyer dans Cursor** :
   - les exports / specs / tokens additionnels,
   - les README mappant chaque écran → composant Vue.
6. Je l’intègre **sans dévier** : on garde le data model, on garde le routing, on garde les invariants — j’upgrade uniquement la couche visuelle et la couche interaction.

---

## 5. Checklist “tout est prêt à envoyer”

- [ ] Brief sauvegardé : `docs/design/CLAUDE_DESIGN_BRIEF_CATALOG_STUDIO_2026-05-03.md`
- [ ] Fichiers §1.A–D copiés / uploadés dans Claude Design
- [ ] Tokens CV1 + tokens kiosk attachés
- [ ] (optionnel) Captures d’écran §1.G jointes
- [ ] Prompt §3 collé tel quel
- [ ] Demande explicite : **mapping screen → fichier Vue** dans le livrable
