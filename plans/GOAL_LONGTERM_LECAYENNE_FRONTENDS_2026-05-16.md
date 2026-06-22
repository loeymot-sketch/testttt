# GOAL PLAN — Le Cayenne Frontends Excellence Long-Term
**Date** : 2026-05-16
**Mode** : `/goal` autonomous self-paced execution until convergence
**Owner** : carte blanche, no clarifying questions, max reasoning
**Méthodologie** : superpower-gstack (Superpowers + GStack 7-step + adversarial RED)

---

## §0 — North Star (immuable sauf owner gate)

Atteindre une **excellence absolue** sur les **deux frontends Le Cayenne** :
- **Surface A — App Mobile** (`foodking-web/web/testttt/mobile/`)
- **Surface B — Site Web** (`/Users/1millnonstop/Downloads/web/`)

avec une **parité data 1:1 contre le système central** (POS + Borne kiosque + KDS + OSS + Admin + DB), validation visuelle systématique de chaque page, tests E2E couvrant visuel + data layer, et préparation d'une **base connectable** pour wireup backend ultérieur — mais **sans wireup aujourd'hui** (instruction owner : tout reste **STANDALONE**).

### Définition de DONE (convergence)
- **Toutes** les pages des deux surfaces (P0 + P1) atteignent **GO V1** vérifié par :
  1. Data parity ✓ (mêmes catégories, items, viandes, sauces, suppléments, prix, wizards que système central)
  2. Visual mandate ✓ (screenshot capturé, Read+analysé, 0 raw label, 0 layout cassé)
  3. UX flow ✓ (toutes les transitions navigables sans blocage)
  4. Responsive ✓ (web seul : mobile + tablet + desktop + wide breakpoints)
  5. A11y ✓ (WCAG 2.1 AA — focus, ARIA, contrast ≥4.5:1)
  6. Perf ✓ (FCP < 2s, scroll 60fps, animations smooth)
  7. Tests ✓ (E2E spec couvre data + visuel + behavior, GREEN ≥ 2 runs stables)
  8. Frozen-zone ✓ (0 ligne diff dans les 12 fichiers protégés du système central)
- **Sync** : base connectable préparée (composer_profile hardcoded mirror DB shape, API contracts documentés dans `docs/INTEGRATION_CONTRACTS.md`).
- **Adversarial RED** post-cycle : 0 P0 résiduel.
- **BRAIN + Graphiti + Memory** à jour pour chaque cycle.

### Non-goals (deferred backlog, hors /goal)
- Wireup réel API/MCP/Sanctum (Phase 6 ultérieure quand owner décidera).
- Stripe customer-facing PaymentIntent (Phase 6+).
- Native build Capacitor / iOS / Android (Phase 11+).
- Supabase migration (deprioritisé).
- Modification frozen zones (KioskWizard / pos-wizard.js / Fiscal / BranchScope / PricingService / OrderStateMachine).

---

## §1 — Two Surfaces Overview

| Surface | Path | Type | État entrée goal |
|---|---|---|---|
| **A — App Mobile** | `foodking-web/web/testttt/mobile/` | React 18 + Babel-standalone PWA, port 8081 | 12/12 E2E green post-cycle 2026-05-16, Bols+Frites composer ✓, data parity ✓ (cycle just done) |
| **B — Site Web** | `/Users/1millnonstop/Downloads/web/` | React 18 + Babel-standalone SPA, port TBD (default 8082) | NEW project, MENU FICTIF (Box Nashville/Cheese Smash) → P0 BLOCKER data parity |

**Les deux surfaces sont COMPLÈTEMENT séparées entre elles** (et séparées du système central kiosk/POS/KDS). Aucun partage de code. Chacune a sa propre data layer hardcoded V0 standalone.

### Brand canonique (à ré-appliquer partout)
- **Restaurant** : Le Cayenne
- **Adresse** : 14 rue de la République, 62210 Hénin-Beaumont
- **Téléphone** : +33 6 51 30 00 00
- **Chef** : Abdoullah
- **Catchphrase** : "Du peuple, pour le peuple"
- **Hours** : 11h — 00h
- **Locale** : fr, EUR
- **Programme fidélité** : Pepper Club — 1€ = 1pt — paliers Novice → Pepper → Master → Légende

### Palette canonique
- `--orange` #FF5A1F (CTA principal)
- `--yellow` #FFD93D (highlight signature)
- `--ink` #0A0A0A (texte primaire / fonds sombres)
- `--cream` #FAF7F2 (paper de fond)
- `--green` #1FA653 (success / loyalty earn)
- `--red` #D72638 (destructive / spicy)
- `--gray-1..5` (échelle neutres)
- Contrast AA : `--orange-text` #C2410C (4.86:1)
- Cycle B a11y heritage : `--gray-3` #6F6A60 (4.7:1)

### Typographie canonique
- `--font-display` : Anton (headlines)
- `--font-sans` : Inter (body)
- `--font-mono` : JetBrains Mono (prix, micro-meta)

---

## §2 — Data SSOT (single source of truth)

**Système central post menu-reset 2026-05-13 + heal-light V2 2026-05-14** est canonique :

### Catégories (11 actives)
| # | slug | name | wizard_template | has_menu |
|---|---|---|---|---|
| 1 | sandwich-cayenne | Sandwich Cayenne | sandwich | yes |
| 2 | galette | Galette | sandwich | yes |
| 3 | sandwich-classique | Sandwich Classique | sandwich | yes |
| 4 | burgers | Burgers (NEW heal-light V2) | sandwich | yes |
| 5 | tacos | Tacos | tacos | yes |
| 6 | bols-gourmands | Bols Gourmands | custom (3-step composer) | no |
| 7 | frites | Frites | custom (1-step composer) | no |
| 8 | supplements | Suppléments | simple | no |
| 9 | desserts | Desserts | simple | no |
| 10 | boissons | Boissons | simple | no |
| 11 | menu-enfant | Menu enfant (NEW heal-light V2) | simple | no |

### Items (41 visibles)
Sandwich Cayenne ×2 (7.50€/9.50€), Galette ×2 (6.50€/7.00€), Sandwich Classique ×2 (7.00€/9.00€), Burgers ×2 (6.90€/8.90€), Tacos ×2 (6.90€/7.90€), Bols ×8 (8.90€), Frites ×2 (2.50€/4.00€), Suppléments ×9 (0.90€), Desserts ×3 (3.80€), Boissons ×8 (1.50€/1.00€ eau), Menu enfant ×1 (6.00€).

### Pools partagés
- **Viandes** (4) : Poulet mariné, Poulet curry, Poulet tandoori, Poulet crispy.
- **Sauces** (11) : Mayo, Ketchup, Algérienne, Samouraï, Curry, Andalouse, Harissa, Hannibal, Blanche, Sauce fromagère maison, Spicy.
- **Crudités** (4) : Salade, Tomate, Oignon, Cornichon.
- **Suppléments génériques** (9 @ 0.90€) : Cheddar, Raclette, Emmental, Œuf, Boursin, Légumes sautés, Jambon, Oignon frais, Champignons.
- **Suppléments bols** (4) : Oignon frais 0.90€, Jambon 0.90€, Champignons 0.90€, Boule gratinée 2.00€.
- **Formules menu** (3) : Menu (Frites+Boisson) 2.50€, Frites seules 2.00€, Boisson seule 2.00€.
- **Frites styles** (3) : Nature 0€, Cheddar +1€, Cheddar+Oignons +2€.
- **Drinks formule** (8) : Coca, Coca Zero, Fanta, Sprite, Oasis, Orangina, Eau (1.00€), Capri-Sun.

### Wizards canoniques par template
- **sandwich** (4 cats : Cayenne / Galette / Classique / Burgers) : viandes (selon item.viande_count) + sauce + crudités + suppléments + menu (cascade) + recap.
- **tacos** (1 cat : Tacos) : viandes + suppléments + menu (cascade) + recap.
- **custom — Bols** (1 cat : Bols Gourmands, 8 items) : composer 3-step = sauce (default fixed per slug) + bol_supplements (4 options + gratiné +2€) + bol_drink (optional, 8 drinks pool, prix catalogue) + recap.
- **custom — Frites** (1 cat : Frites, 2 items) : composer 1-step = frites_style (Nature default pre-selected) + recap.
- **simple** (4 cats : Supp / Desserts / Boissons / Menu enfant) : direct-add (qty stepper).

### Pricing règles
- Sauces : 1 gratuite, sup 0.50€ par sauce additionnelle.
- Bol drink : prix unitaire catalogue (1.50€ standard, 1.00€ eau).
- Formules : Menu 2.50€, Frites seules 2.00€, Boisson seule 2.00€.
- Frites styles : Nature 0€, Cheddar +1.00€, Cheddar+Oignons +2.00€.

### Source de vérité (NE jamais inventer)
- DB seed commands : `app/Console/Commands/MenuResetLeCayenneCommand.php` + `app/Console/Commands/MenuHealLightV2Command.php`.
- Mobile data layer post-cycle 2026-05-16 : `mobile/data/menu.js` (réutilisable comme template pour le site).
- Anti-pattern : `config/menu.php` = STALE doc (15 sauces, €1 supps pré-reset) — ne JAMAIS s'en servir.

---

## §3 — Horizontal axes (9 axes par page, cross-cutting)

| # | Axe | Acceptance criteria | Applies to |
|---|---|---|---|
| **H1** | Data parity SSOT | Items / sauces / viandes / supps / prix / wizards = système central exact (cf. §2) | A + B |
| **H2** | Visual design | Flat/minimal, palette canonique, photos vraies, branding cohérent, allergen badges (FIC 1169/2011) | A + B |
| **H3** | Responsive | Mobile 390 + Tablet 768 + Desktop 1280 + Wide 1920 breakpoints, touch ≥44px, safe-area-inset-bottom | B (web only ; A est mobile-only par design) |
| **H4** | UX flow | Toutes transitions navigables sans blocage, hints disabled CTA, empty/loading/error states | A + B |
| **H5** | Performance | FCP < 2s, scroll 60fps, animations cubic-bezier(0.22,1,0.36,1), prefers-reduced-motion | A + B |
| **H6** | A11y WCAG 2.1 AA | role / ARIA / tabIndex / onKeyDown / focus mgmt / contrast ≥4.5:1 / focus-visible / aria-live | A + B |
| **H7** | Tests E2E | Playwright spec couvre data + visuel + behavior, snapshot par page, GREEN sur 2 runs stables | A + B |
| **H8** | Sync / connectable | composer_profile hardcoded mirror DB shape, API contracts documentés, data flow tracé | A + B |
| **H9** | Documentation | README à jour, BRAIN reflète l'état, memory + Graphiti cycle | A + B |

---

## §4 — Vertical decomposition Surface A — App Mobile (14 pages)

**Path** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/`
**Boot** : `php -S 127.0.0.1:8081 -t mobile/`
**Test** : `npx playwright test --config=tests/mobile-e2e/playwright.config.js tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js`

### Pages & priorité

| ID | Page | File | Priority | État entrée |
|---|---|---|---|---|
| A-P01 | Splash | mobile/screens-onboarding.jsx ScreenSplash | P1 | ✓ existing |
| A-P02 | Onboarding 1-4 | mobile/screens-onboarding.jsx ScreenOnb1..Onb4 | P1 | ✓ existing, hero V2 |
| A-P03 | Login | mobile/screens-onboarding.jsx ScreenLogin | P1 | ✓ existing, mock |
| A-P04 | OTP | mobile/screens-onboarding.jsx ScreenOTP | P1 | ✓ existing, mock |
| A-P05 | Home | mobile/screens-main.jsx ScreenHome | **P0** | ✓ existing, 11 choix badge |
| A-P06 | Menu (browse) | mobile/screens-main.jsx ScreenMenu | **P0** | ✓ existing, 11 cats + 41 items |
| A-P07 | Item Detail Direct-Add | mobile/screens-item-steps.jsx ScreenItemDirectAdd | **P0** | ✓ existing |
| A-P08 | Item Wizard (8 templates) | mobile/screens-item-steps.jsx ScreenItemWizard | **P0** | ✓ Bols + Frites custom composer DONE |
| A-P09 | Cart | mobile/screens-main.jsx ScreenCart | **P0** | ✓ existing, composition_summary |
| A-P10 | Pay Choice | mobile/screens-modals.jsx ModalPayChoice | **P0** | ✓ stub CB/Cash/Stripe |
| A-P11 | Confirmation | mobile/screens-main.jsx ScreenConfirm | **P0** | ✓ existing, QR + confetti +25pts |
| A-P12 | Orders (active + historique) | mobile/screens-main.jsx ScreenOrders | P1 | ✓ existing |
| A-P13 | Order Detail | mobile/screens-main.jsx ScreenOrderDetail | P1 | ✓ existing |
| A-P14 | Profile | mobile/screens-main.jsx ScreenProfile | P1 | ✓ existing |
| A-P15 | Loyalty (HERO+POINTS+ACTIONS+TABS+REWARDS+HISTORY+INFOS) | mobile/screens-main.jsx ScreenLoyalty | P1 | ✓ existing, rdl-* refactor |
| A-P16 | RGPD opt-out flow | mobile/screens-modals.jsx ModalOptOutConfirm | P1 | ✓ existing, S-001 closure |
| A-P17 | Wallet stubs (Apple/Google) | mobile/screens-modals.jsx ModalWalletV0Notice | P2 | ✓ stub V0 |
| A-P18 | Wizard Redeem (loyalty) | mobile/components/WizardRedeem.jsx | P1 | ✓ existing, 3-step idempotency |

### Per-page acceptance criteria (vertical × horizontal matrix)

Pour CHAQUE page A-P01..P18, l'agent doit valider les 9 axes H1-H9 :
- **H1 Data** : query window.LC.menu (ou data locale), confirme parité §2.
- **H2 Visual** : Playwright capture → Read → analyse layout / palette / photos / branding / allergens.
- **H3 Responsive** : N/A pour mobile (mobile-only 390 fixed).
- **H4 UX** : navigation forward + back + empty/error states.
- **H5 Perf** : FPS sample sur scroll, no jank.
- **H6 A11y** : axe.json inject, 0 critical / 0 serious.
- **H7 Tests** : spec couvre data + visuel + behavior pour cette page, GREEN.
- **H8 Sync** : composer_profile / cart shape / item ids prêt pour wireup.
- **H9 Doc** : commentaire JSX + mention dans CONNECTION_PLAN.md si pertinent.

### Mobile-specific TODO (post-realignment cycle)
- A-P11 Confirmation : verify QR mock = `FK:<loyalty_code>` format (D-A per audit), TTL countdown.
- A-P15 Loyalty : verify Pepper Club paliers reflètent canonique (Novice 0 → Pepper 500 → Master 1500 → Légende 5000) — confirm with owner if missing.
- A-P05 Home : add "daily special" + "app CTA" sections from web mirror si bénéfique.
- A-P07/P08 : add missing photos signature (cayenne-hero, big-cayenne-hero, etc.) si manquantes.

---

## §5 — Vertical decomposition Surface B — Site Web (17 pages + 6 modals/drawers)

**Path** : `/Users/1millnonstop/Downloads/web/`
**Boot** : `python3 -m http.server 8082 --directory /Users/1millnonstop/Downloads/web` (ou `npx serve`)
**Test config** : NEW `tests/web-e2e/playwright.config.js` à créer (baseURL :8082, viewports mobile + tablet + desktop + wide)
**Spec à créer** : `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js`

### Routes principales (state-driven, pas react-router)

| ID | Route / Page | File | Priority | État entrée |
|---|---|---|---|---|
| B-P01 | Home | web/screens.jsx WebHome | **P0** | ⚠️ menu FICTIF |
| B-P02 | Menu (browse + cats sidebar + search) | web/screens.jsx WebMenu | **P0** | ⚠️ menu FICTIF |
| B-P03 | Item Detail Modal (nutri + allergens) | web/screens-v3.jsx ItemDetailModal | **P0** | ⚠️ menu FICTIF |
| B-P04 | Wizard Flow (9 steps) | web/wizard-v2.jsx WizardFlow | **P0** | ⚠️ wizards FICTIFS, à aligner sur 4 templates canoniques (sandwich/tacos/custom-bols/custom-frites/simple) |
| B-P05 | Cart Drawer (side panel) | web/flows.jsx CartDrawer | **P0** | existing UI, data FICTIVE |
| B-P06 | Checkout Page (pickup time + promo + notes) | web/funnel.jsx CheckoutPage | **P0** | existing |
| B-P07 | Payment Page (CB / Apple Pay / Google Pay / Caisse) | web/funnel.jsx PaymentPage | **P0** | existing, V0 mock |
| B-P08 | Confirmation Page (QR ticket + confetti) | web/funnel.jsx ConfirmationPage | **P0** | existing |
| B-P09 | Tracking Page (live status with progress) | web/funnel.jsx TrackingPage | **P0** | existing |
| B-P10 | Orders Page (history + filter + reorder) | web/orders.jsx OrdersPage | P1 | existing, mock data |
| B-P11 | Account Flow modal (login/signup/social/OTP/forgot) | web/account-v2.jsx AccountFlow | **P0** | existing, mock |
| B-P12 | Loyalty Page (Pepper Club dashboard) | web/screens.jsx WebLoyalty | P1 | existing |
| B-P13 | Loyalty Profile Tab (editor + notif + cards + prefs) | web/loyalty-v2.jsx LoyaltyProfileTab | P1 | existing |
| B-P14 | About Page (l'enseigne) | web/screens.jsx WebAbout | P1 | existing |
| B-P15 | Press section | web/screens-v3.jsx Press | P2 | existing |
| B-P16 | Comparison section | web/screens-v3.jsx Compare | P2 | existing |
| B-P17 | FAQ | web/screens-v3.jsx FAQ | P2 | existing |
| B-P18 | Leaderboard | web/screens-v3.jsx Leaderboard | P2 | existing |
| B-P19 | Challenge | web/screens-v3.jsx Challenge | P2 | existing |
| B-P20 | Team | web/screens-v3.jsx Team | P2 | existing |
| B-P21 | WebNav (sticky + mobile burger) | web/components.jsx WebNav | **P0** | existing |
| B-P22 | WebFooter (brand + nav + contact) | web/components.jsx WebFooter | P1 | existing |
| B-P23 | WebModal generic shell | web/components.jsx WebModal | P1 | existing |

### Web-specific TODO

#### B-DATA — refit menu FICTIF → canonical Le Cayenne (P0 BLOCKER for B-P01..P11)
- Replace `W_CATS` in `web/screens.jsx` (currently smash/tacos/bowls/buckets/wraps/box) with the 11 canonical cats.
- Replace `W_ITEMS` (currently Box Nashville/Cheese Smash/Le Gourmet/Chèvre Miel/etc.) with the 41 canonical items.
- Replace `W_DIET` filters appropriately.
- Add canonical pools : MEATS (4), SAUCES (11), CRUDITES (4), SUPPLEMENTS (9), SUPPLEMENTS_BOLS (4), FORMULES (3), FRITES_STYLES (3), FORMULE_DRINKS (8).
- Add `priceFor()` + `priceForDrinkAddon()` mirror mobile.
- Add composer_profile hardcoded for Bols + Frites mirror mobile.
- Adapt `WizardFlow` (currently 9-step generic) → render based on item.composer_profile or category.wizard_template (4 templates).
- Reuse mobile data layer code where possible (copy `mobile/data/menu.js` IIFE → `web/data/menu.js` adapted).

#### B-ASSET — wire real photos
- Copy product photos from `mobile/assets/menu/` (170 PNG already aligned) OR from `/Users/1millnonstop/Downloads/image produit/` (owner originals) into `web/assets/menu/`.
- Wire `imgFor(slug)` + `heroFor(slug)` helpers (like mobile).
- Hero photos for signature items (Sandwich Cayenne, Big Cayenne, Tacos hero, etc.).
- Cat header icons + tile images for menu sidebar.

#### B-RESPONSIVE — mobile + tablet + desktop + wide (H3 axis)
- Existing `styles-mobile.css` is last in cascade → keep for < 480.
- Add explicit breakpoints check : 480 / 768 / 1024 / 1280 / 1920.
- Verify each page renders in all 4 sizes via Playwright multi-viewport.

#### B-PEPPER-CLUB — loyalty alignement
- Verify Pepper Club paliers : Novice 0 / Pepper 500 / Master 1500 / Légende 5000 (or owner-canonical values).
- 1€ = 1pt earn rate (mobile uses 10:1, owner gate to align — DECISION DEFERRED to owner).
- Reward catalogue stub MOCK V0 (no backend).

#### B-NEW SECTIONS si manquantes
- Daily special widget (`styles-v5.css` mentions daily-special class).
- App CTA section ("Download our mobile app", lien stub vers App Store / Play Store).
- Search hero (`styles-v5.css`).
- Hours section ("Ouvert 11h-00h, Hénin-Beaumont").
- Press / Comparison / FAQ / Leaderboard / Challenge / Team (P2, already exist, verify content + photos).

#### B-DELIVERABLES additional
- New `web/data/menu.js` (data layer mirror mobile).
- New `web/data/loyalty.js` (Pepper Club mock).
- New `web/data/orders.js` (mock past orders aligned canonical menu).
- New `web/data/user.js` (mock user profile).
- New `web/api/storage.js` (localStorage helpers, namespace `lecayenne-web.`).
- New `tests/web-e2e/playwright.config.js` (port 8082, multi-viewport).
- New `tests/e2e/test-e2e-website-realignment-*.spec.js` (E2E per page).
- New `web/README.md` updated with canonical menu + run instructions.

---

## §6 — Frozen-zone discipline (absolu, non négociable)

**0 ligne diff** sur ces fichiers pendant tout le /goal :

### Frontend kiosk
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`

### POS wizard (Vanilla JS frozen)
- `public/js/pos-wizard.js`
- `public/css/pos-wizard.css`
- `resources/views/admin-pos-v4.blade.php`

### Backend NF525-critical
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/Fiscal/AuditLogService.php`

### Backend multi-tenant + payment
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `app/Services/Pricing/PricingService.php`
- `app/Domain/Order/OrderStateMachine.php`

**Si l'agent a besoin de toucher l'un de ces fichiers** : STOP immédiat, escalate owner pour `/lock-plan`. Sinon : `git status --short -- <file>` doit retourner vide pour chacun à la fin de chaque wave.

---

## §7 — Wave sequencing — orchestration parallèle + séquentielle

### Wave 0 — Orient (max 30min)
- Read `PROJECT_BRAIN.md` §2 §3 §4 §7 §8.
- Read `memory/MEMORY.md` + recent entries.
- Read this plan.
- Search Graphiti `foodking` group for "mobile" + "website".
- Verify both servers can boot (port 8081 mobile + port 8082 web).
- Identify le NEXT P0 unfinished work item.

### Wave 1 — Surface B Data SSOT refit (BLOCKER, sequential, 1 Implementer)
- B-DATA refit `web/screens.jsx` `W_CATS` + `W_ITEMS` + pools to canonical 11 cats / 41 items.
- Add `web/data/menu.js` IIFE mirror mobile.
- Verify in browser : Home + Menu render canonical content.

### Wave 2 — Surface B Assets wire (sequential, 1 Implementer)
- B-ASSET copy photos from `mobile/assets/menu/` to `web/assets/menu/`.
- Wire `imgFor()` + `heroFor()` helpers.
- Verify in browser : items show real photos.

### Wave 3 — Surface B Wizard adaptation (sequential, 1 Implementer)
- Adapt `web/wizard-v2.jsx` to render 4 canonical templates (sandwich / tacos / custom-bols / custom-frites / simple).
- Mirror mobile `computeActiveSteps` + composer_profile pattern.
- Verify in browser : each wizard works for each cat.

### Wave 4 — Surface B Page-by-page parallel refinement (parallel by independent page, sequential within page)
For each of B-P01..P23, spawn parallel Implementer subagents (max 3 concurrent, no shared file conflict) :
- Apply 9 horizontal axes H1-H9.
- Visual capture multi-viewport (mobile / tablet / desktop / wide).
- Read+analyse screenshots.

### Wave 5 — Surface A Page-by-page polish (parallel by independent page)
For each of A-P01..P18, refine where needed :
- Most pages already DONE (12/12 E2E green from 2026-05-16 cycle).
- Focus on : A-P11 Confirmation polish, A-P15 Loyalty Pepper Club paliers, A-P07/P08 missing photos.

### Wave 6 — E2E test convergence (sequential, 1 author)
- Author `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js` (web only ; mobile already has).
- Multi-viewport assertions (mobile 390 + tablet 768 + desktop 1280 + wide 1920).
- GREEN ≥ 2 runs stables.

### Wave 7 — Adversarial RED (parallel, 2 sub-agents)
- 1 sub-agent hostile on Mobile (find P0 missed by 12 green tests).
- 1 sub-agent hostile on Web (find P0 missed by web tests).
- Reconcile findings, heal max 3 cycles per finding.

### Wave 8 — Final ship verdict (sequential)
- BRAIN §3 LAST DONE updated.
- Memory + Graphiti push.
- `reports/audit/longterm-goal-2026-05-XX/FINAL_VERDICT.md`.
- GO V1 unconditional declared OR list remaining backlog with severity.

### Healing rule
- Max 3 healing cycles consécutifs sur le même problème sans escalation.
- Au-delà → escalate à user avec analyse cause racine (per CLAUDE.md §5 step 7).

---

## §8 — Convergence criteria global + per page

### Per page (GO V1)
| Axe | Check |
|---|---|
| H1 Data | All items / sauces / viandes / supps / prix == §2 canonical |
| H2 Visual | Screenshot Read+analysé, 0 raw label, layout intact, photos vraies, branding |
| H3 Resp. | (web) 4 viewports rendent OK |
| H4 UX | navigation forward+back, empty/loading/error gérés |
| H5 Perf | FCP < 2s OR justifié |
| H6 A11y | axe.json 0 critical / 0 serious |
| H7 Tests | spec couvre, GREEN ≥ 2 runs |
| H8 Sync | code structuré pour wireup mécanique futur |
| H9 Doc | commentaire JSX + README |

### Global (GO Long-Term)
- 100% pages P0 GO V1 ✓
- ≥ 80% pages P1 GO V1 ✓ (P1 restantes documentées backlog)
- P2 pages = backlog explicite (non bloquant)
- Frozen-zone diff = 0 ligne ✓
- Adversarial RED final 0 P0 résiduel ✓
- BRAIN + Graphiti + Memory à jour ✓
- E2E suites GREEN sur 2 runs stables consécutifs ✓
- Documentation INTEGRATION_CONTRACTS.md écrit ✓

---

## §9 — Visual mandate (CLAUDE.md §6 obligatoire)

Pour CHAQUE modification touchant `.jsx` / `.vue` / `.css` / route / payload UI :
1. Identifier les surfaces touchées (smart, pas "tout").
2. Playwright capture (port 8081 mobile, port 8082 web, multi-viewport pour web).
3. Save dans `/tmp/foodking-iter-<n>/` ou `tests/captures/<ts>/` ou `tests/e2e/__screenshots__/test-e2e-<surface>-<cycle>/`.
4. **Read chaque screenshot via Read tool** (Claude voit l'image).
5. Analyse : raw labels / layout / empty state / error state / a11y visible / branding / photos.
6. Si problème → heal (max 3 loops, puis escalate).

Production surfaces à capturer :
- **Mobile** (1 viewport, 390×844 iPhone 13) :
  - `/index.html` (Home)
  - `/index.html` → Menu tab
  - `/index.html` → Menu → bowl-frites-curry wizard 3-step
  - `/index.html` → Menu → petite-frites wizard 1-step
  - `/index.html` → Menu → sandwich-cayenne-classique wizard
  - `/index.html` → Cart
  - `/index.html` → Confirmation
  - `/index.html` → Loyalty
  - `/index.html` → Orders
- **Web** (4 viewports : 390 / 768 / 1280 / 1920) :
  - `/` Home
  - `/` → Menu route
  - `/` → ItemDetailModal
  - `/` → WizardFlow (one per template)
  - `/` → CartDrawer
  - `/` → Checkout
  - `/` → Payment
  - `/` → Confirmation
  - `/` → Tracking
  - `/` → AccountFlow modal
  - `/` → Loyalty
  - `/` → About

---

## §10 — Test strategy

### Mobile (existing spec)
- `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js` (12 tests GREEN).
- Re-run baseline pour s'assurer 0 régression à chaque cycle.

### Web (NEW spec to author)
- `tests/web-e2e/playwright.config.js` (NEW, port 8082, multi-viewport projects).
- `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js` (NEW, ~15-20 tests par page) :
  - Data parity (window.LC OR direct W_ITEMS export) : 11 cats / 41 items / pools.
  - Pricing parity : bowl base 8.90€ etc.
  - Per-page : Home / Menu / Item / Wizard / Cart / Checkout / Pay / Confirm / Track / Orders / Account / Loyalty / About.
  - Multi-viewport : mobile / tablet / desktop / wide chaque page.
  - Wizard parity 4 templates × items.
  - Cart round-trip storage.
  - a11y axe inject.

### Cross-cutting
- Frozen-zone diff sentinel : `git diff --stat -- <each frozen file>` doit retourner vide.
- Adversarial RED post-cycle : 1 sub-agent hostile per surface.

---

## §11 — `/goal` autonomous execution prompt

Pour lancer le goal autonome, l'owner lance `/goal` (ou `/loop` self-paced) avec le brief suivant qui pointe sur ce document :

```
/goal Lance le Long-Term Goal Le Cayenne Frontends Excellence
(plans/GOAL_LONGTERM_LECAYENNE_FRONTENDS_2026-05-16.md).

Objectif : 100% pages P0 + ≥80% pages P1 atteignent GO V1 sur les deux
surfaces (App Mobile + Site Web), data parity 1:1 contre système central
(11 cats / 41 items / 11 sauces / 9 supps @ 0.90€ / 4 viandes / 4 supps_bols /
composers Bols 3-step + Frites 1-step / 4 templates wizard), 0 frozen-zone touch,
visual mandate respecté chaque page, E2E suites green sur 2 runs stables.

Discipline obligatoire :
- superpower-gstack skill à chaque cycle non-trivial
- CLAUDE.md §5 LOOP 8 steps respect
- frozen-zone §6 absolu
- visual mandate §9 obligatoire pour toute modif UI
- adversarial RED §7 wave 7 mandatory avant ship
- mobile reste STANDALONE (no API wireup) — owner instruction explicite
- site web reste STANDALONE (no API wireup) — owner instruction explicite
- préparer base connectable Phase 6 (composer_profile mirror DB shape,
  API contracts documentés dans docs/INTEGRATION_CONTRACTS.md)

Healing rule : max 3 cycles sur même problème, puis escalate owner.
Memory : update BRAIN §3+§4 + Graphiti + memory file à chaque wave terminée.

Wave sequencing : Wave 0 orient → Wave 1 Surface B data refit BLOCKER →
Wave 2 assets → Wave 3 wizards → Wave 4 web pages parallel → Wave 5 mobile polish →
Wave 6 E2E web spec author → Wave 7 RED 2 sub-agents → Wave 8 ship verdict.

STOP rules :
- Si touche frozen-zone nécessaire → STOP escalate /lock-plan
- Si 3 heals consécutifs échouent → STOP escalate analyse cause
- Si data SSOT divergence avec système central → STOP escalate decision
- Si owner instruction explicite contradiction → STOP escalate

DONE rule : GO V1 unconditional déclaré dans reports/audit/longterm-goal-*/FINAL_VERDICT.md
+ BRAIN §3 LAST DONE updated + Graphiti episode pushé + memory file écrit.

Lance l'armée GStack + Superpowers + Adversarial. Max reasoning. Pas de
clarifying questions sans nécessité. Carte blanche autonomy.
```

### Cadence recommandée
- /goal self-paced : agent décide quand reboucler (toutes les 15-30 min selon densité de travail).
- /loop avec intervalle fixe : `/loop 20m /goal <brief>` pour cadence prévisible.
- Pour tâches long-running (Wave 1+2+3 sequential) : agent peut bloquer 1-2h sans rebouclage.

---

## §12 — Risk register

| ID | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | Agent invente menu pour le site web (smash/burgers/wraps fictifs survive) | CRITICAL | Wave 1 BLOCKER avant tout le reste, data parity check H1 dans chaque test |
| R2 | Photos manquantes → image-slot placeholders visibles | HIGH | Wave 2 assets BLOCKER, fallback à `item-default.svg` mais flag visuel |
| R3 | Frozen-zone breach accidentel (touche KioskWizard ou pos-wizard) | CRITICAL | Wave 0 + chaque cycle : `git status --short -- <frozen>` doit être vide |
| R4 | Wizard divergence mobile ↔ web (4 templates pas mirror exact) | HIGH | Wave 3 dispatch 1 Implementer qui copie le pattern mobile mécaniquement |
| R5 | Responsive breakage (web mobile/tablet/desktop/wide) | MEDIUM | Wave 6 spec multi-viewport projects, test sur 4 sizes obligatoire |
| R6 | A11y régression (axe critical) | MEDIUM | H6 axe.json inject chaque page, 0 critical / 0 serious required |
| R7 | Perf dégradation (FCP > 2s, scroll jank) | MEDIUM | H5 sample FPS, optimiser images (resize, lazy load) |
| R8 | Adversarial RED late P0 → 3-loop healing exhausted | MEDIUM | Wave 7 séparée, escalate après 3 cycles |
| R9 | BRAIN drift (état réel ≠ BRAIN claim) | MEDIUM | Wave 8 BRAIN sync obligatoire, Graphiti push |
| R10 | Owner change scope mid-goal | LOW | Plan structuré pour absorption (sub-plans modulaires), STOP rule on contradiction |
| R11 | Pepper Club paliers / earn rate divergence mobile (10:1) vs web (1:1) | MEDIUM | Owner gate Wave 1 OR documenter divergence acceptée V0 |
| R12 | Web wizard 9-step generic ne matche pas 4 templates canoniques | HIGH | Wave 3 refactor structuré, reuse mobile pattern |

---

## §13 — Backlog deferred (post-goal Phase 6 / V1.x)

### Phase 6 wireup backend (when owner décide)
- B6-01 Sanctum `customer:order` ability + endpoints `/api/frontend/menu/customer`.
- B6-02 NF525 fiscal allocation pour mobile + web source orders.
- B6-03 SMS provider (Twilio / MessageBird) + login/OTP réel.
- B6-04 Stripe customer-facing PaymentIntent (Apple Pay / Google Pay natif).
- B6-05 Realtime push (Pusher) pour TrackingPage status updates.
- B6-06 Loyalty backend (loyalty_rewards table + loyalty_physical_cards + AwardLoyaltyPointsOnDelivery wireup).
- B6-07 Cart desync server-side sync (table `cart_drafts` ou device-local accepté).
- B6-08 Channels filter `mobile_app` / `web` seeding sur items.

### V1.x polish (post-goal mineur)
- B-V1-01 Sauce default by slug instead of name (rename-resistant).
- B-V1-02 Drink addon pricing from FORMULE_DRINKS catalogue (not hardcoded).
- B-V1-03 Console error capture during wizard UI navigation.
- B-V1-04 Bol composer 4-step (with base step) if owner reverses 8-items split.
- B-V1-05 Mobile native build Capacitor.
- B-V1-06 Web SSR (Next.js / Vite SSR) pour SEO si pertinent.
- B-V1-07 ESLint v10 setup + Vue plugin (workspace global, non bloquant).

### P2 pages refinement
- B-P15..P20 site web (Press / Compare / FAQ / Leaderboard / Challenge / Team) — Wave 4+ optional.

---

## §14 — Owner-gate decisions (à trancher AVANT Wave 1 si possible)

### D1 — Pepper Club earn rate
- Mobile actuel : 10 pts / 1€ (per audit 2026-05-10).
- Site web actuel : 1 pt / 1€ (per README.md line 116).
- **Recommandation** : aligner à 1€ = 1pt (web) — plus humain, owner peut confirmer.

### D2 — Pepper Club paliers (Novice → Pepper → Master → Légende)
- Pas de valeurs canoniques. Suggestion : Novice 0 / Pepper 500 / Master 1500 / Légende 5000 (paliers exponentiels, atteignables 6 mois à 1 an).
- **Owner peut donner valeurs exactes ou accepter suggestion.**

### D3 — Site web port + hostname
- Default : `127.0.0.1:8082`. Owner peut changer si conflit local.

### D4 — Photos source
- Option A : copier `mobile/assets/menu/` (170 PNG déjà alignés post-Phase 6.A).
- Option B : ré-extraire de `/Users/1millnonstop/Downloads/image produit/` (originaux owner).
- **Recommandation** : Option A (déjà optimisés pour web, pas de duplicate work).

### D5 — Site web shopping flow
- Pickup only (web → user récupère sur place) ? Delivery ? Both ?
- Mobile actuel : pickup-only ("PAYER À LA CAISSE" + "PAYER MAINTENANT").
- **Recommandation** : web mirror mobile = pickup-only V0, delivery deferred Phase 6+.

### D6 — Cart promo code source
- Mobile a `WELCOME10` + `CAYENNE` mocks.
- **Recommandation** : web réutilise mêmes codes V0, V1 = backend.

(Si owner ne tranche pas avant Wave 1, agent suit les **Recommandations** ci-dessus et marque en backlog.)

---

## §15 — Final summary

### Ce qui sera livré quand /goal converge GO V1
- **Mobile app** : 18 pages × 9 axes validés, E2E 12+ tests GREEN stables, base prête wireup.
- **Site web** : 23 routes/pages × 9 axes validés, multi-viewport responsive, E2E 15-20+ tests GREEN stables, base prête wireup.
- **Data parity** : 100% mêmes 11 cats / 41 items / pools que système central, vérifié par tests automatisés.
- **Frozen zones** : 0 ligne diff sur 12 fichiers protégés.
- **Visual evidence** : ~80-120 screenshots Read+analysed accross both surfaces × viewports.
- **Adversarial RED** : 0 P0 résiduel, 2 sub-agents disputed.
- **Documentation** : `docs/INTEGRATION_CONTRACTS.md` écrit pour Phase 6 wireup mécanique.
- **BRAIN + Graphiti + Memory** : à jour, état réel reflété.
- **`reports/audit/longterm-goal-2026-05-XX/FINAL_VERDICT.md`** : verdict GO V1 unconditional avec evidence pack.

### Estimate effort
- Wave 1+2+3 (data refit + assets + wizards web) : ~1 jour-agent.
- Wave 4 (web page-by-page) : ~2-3 jours-agent (parallel).
- Wave 5 (mobile polish) : ~0.5 jour-agent.
- Wave 6 (E2E spec) : ~0.5 jour-agent.
- Wave 7 (RED dispute + heal) : ~0.5 jour-agent.
- Wave 8 (ship) : ~0.5 jour-agent.
- **Total** : ~5-6 jours-agent wall-clock (compressible avec parallel subagent dispatch sur Wave 4).

### Lancement
1. Owner trancher D1-D6 (ou accepter Recommandations).
2. Owner lancer `/goal` avec brief §11.
3. Agent boucle jusqu'à GO V1.
4. Owner reçoit notification convergence.
5. Owner review evidence pack.
6. Owner commit + ship si OK.

— Fin du plan goal —
