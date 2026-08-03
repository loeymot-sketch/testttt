# Standalone Apps Cartography — Le Cayenne / FoodKing

**Audit date:** 2026-05-29
**Specialist:** STANDALONE-APPS (read-only ultra-audit)
**Method:** Glob/Read PRIMARY. Every cited path was opened or directory-listed this session.

> Two BRAIN/MEMORY geometry claims were WRONG and are corrected below: the mobile
> tree does NOT exist at the documented path; the web site is NOT a Vite+Vue SPA.
> (Bash intermittently returned stale cached frames; every fact below was
> confirmed via a Read or a fresh-tagged command.)

---

## Memory corrections (geometry was stale)

1. **No mobile app at `/Users/1millnonstop/Downloads/mobile/`** — directory does
   not exist (`ls` -> "No such file or directory", verified 4x). No Expo/RN tree,
   no `App.js`, no `src/data/menu.js`. A broad `find` over `Downloads/` for
   `app.json`/`mobile` surfaced only unrelated projects (pregnancy-app, theme
   exports). The web menu header still references a sibling `mobile/data/menu.js`
   + `mobile/assets/menu/` (its parity source), and `MenuHealLightV2Command.php:780`
   names `mobile/data/menu.js` — so a mobile tree existed at authoring time. It is
   **gone or moved** now.
2. **Web site is NOT Vite+Vue.** It is a **build-less React 18 + Babel-standalone
   static prototype** (CDN React/ReactDOM/Babel via unpkg, `.jsx` transpiled
   in-browser via `<script type="text/babel">`). No `package.json`, no `src/`, no
   `node_modules`, no Vue. Verified by reading `index.html` + `README.md`.

---

## Mobile app

- **Status: NOT FOUND.** `/Users/1millnonstop/Downloads/mobile/` does not exist
  (FILE-VERIFIED). Cannot cartograph — no tree present. All other fields N/A.
- The documented Expo black/orange/yellow mobile app is **stale or relocated**;
  re-locating it is a re-run task.

---

## Web site

- **Path (FILE-VERIFIED):** `/Users/1millnonstop/Downloads/web/`
- **Stack (FILE-VERIFIED, index.html L20-35 + README L3):** React 18.3.1 + ReactDOM
  + `@babel/standalone` 7.29 from **unpkg CDN**; all UI files are in-browser
  transpiled `.jsx` (components/screens/screens-v3/loyalty-v2/orders/flows/
  wizard-v2/account-v2/funnel). **No build system, no package.json.** Own git repo
  (`web/.git`). State-driven routing (no react-router); `App()` holds
  route/cart/isAuth/ctx.
- **Entry point (FILE-VERIFIED):** `/Users/1millnonstop/Downloads/web/index.html`
  (README also names `Le Cayenne — Website.html`, absent — minor doc drift).
- **Menu data file (FILE-VERIFIED, READ IN FULL, 493 lines):**
  `/Users/1millnonstop/Downloads/web/data/menu.js` — **single source** (no
  `src/data/menu.js`). IIFE exposing `window.LC.menu` + back-compat
  `window.W_CATS / W_ITEMS / W_DIET`. Loaded first (index.html L25).
- **Product / category count (FILE-VERIFIED):** **11 categories** (L174-186),
  **41 items** (L300-411; header L298 "41 visibles"): 2+2+2+2+2 (sandwich Cayenne/
  galette/classique/burgers/tacos) + 8 bols + 2 frites + 9 supplements + 3 desserts
  + 8 boissons + 1 menu enfant = 41 (cross-checks). Pools: 4 meats, 11 sauces,
  4 crudites, 9 supplements, 3 formules, 3 frites styles, 8 drinks. Allergens per
  FIC 1169/2011. Local `priceFor()` calc (L416) + Pepper Club tiers (L434).
- **Palette (FILE-VERIFIED, styles.css + README L49):** `--orange #FF5A1F`,
  `--yellow #FFD93D`, `--ink #0A0A0A`, `--cream #FAF7F2`, `--green #1FA653`,
  `--red #D72638`. **CORRECT vs spec** — black/orange/yellow app palette, NOT the
  Cayenne kiosk red `#F4501E` (that hex appears nowhere in styles.css). Palette
  anti-hallucination check: PASS.
- **Wired vs standalone (FILE-VERIFIED):** **STANDALONE.** No axios/fetch/API base
  URLs anywhere — only CDN `<script>` tags. menu.js L6 declares "Web reste
  STANDALONE (no API/MCP wireup)". README L98-103 lists *future* wireup endpoints
  (aspirational). Cart seeds a mock item; checkout/payment/tracking are mock UI.
- **Wizard parity (FILE-VERIFIED):** Full. `wizard_template` per category; bols
  have a 3-step `composer_profile` (sauce / bol_supplements / drink, L258-275, with
  a safe-fallback heal L245); frites a style-step profile (L277). README L22: 9-step
  WizardFlow with validations + allergens.
- **Maturity: 6/10.** Internally rich + coherent (41-item canonical model,
  allergens, price calc, loyalty, full funnel UI, correct brand + palette,
  WCAG-AA claim) but a **build-less CDN-React demo prototype**: 6 competing CSS
  versions, mock cart/checkout, self-declared no-backend. Polished marketing
  surface, not a deployable transactional app.

---

## SSOT parity verdict — PARITY at the V2 layer, BUT V3 broke it (2 real drifts)

The central SSOT is a **chain**: `MenuResetLeCayenneCommand` (base) ->
`MenuHealLightV2Command` -> `MenuHealLightV2Round2Patch` -> `MenuHealLightV3Command`
-> `MenuHealLightV31Burger`. I read base, V2, and V3 in full. The web header cites
"menu-reset + heal-light V2", so the web menu is a **post-V2** snapshot.

**Base seed vs web** looks like drift, but it is just the pre-heal state — V2
reconciles every one (FILE-VERIFIED, both files read in full):

| Item | Base (MenuReset) | After V2 | Web (data/menu.js) | Post-V2 |
|---|---|---|---|---|
| Sandwich Cayenne | 7.00 (L457) | 7.50 (V2 L340) | 7.50 (L301) | MATCH |
| Sandwich Classique | 6.50 (L493) | 7.00 (V2 L341) | 7.00 (L322) | MATCH |
| "Tacos" -> Tacos M | 8.50 (L521) | 6.90 "Tacos M" (V2 L342,356) | 6.90 "Tacos M" (L343) | MATCH |
| "Big Tacos" -> Tacos L | 9.50 (L531) | 7.90 "Tacos L" (V2 L343,357) | 7.90 "Tacos L" (L347) | MATCH |
| Big Cayenne | (none) | 9.50 new (V2 L413) | 9.50 (L305) | MATCH |
| Big Classique | (none) | 9.00 new (V2 L429) | 9.00 (L325) | MATCH |
| Chicken Burger | (none) | 6.90 new (V2 L460) | 6.90 (L332) | MATCH |
| Big Chicken | (none) | 8.90 new (V2 L476) | 8.90 (L336) | MATCH |
| Menu Nuggets | (none) | 6.00 new (V2 L503) | 6.00 (L403) | MATCH |
| Bols family | 5 @10.50/12.50 (L548-553) | 5->8 Bowl Frites/Riz @8.90 (V2 L514-578) | 8 @8.90 (L354-361) | MATCH |
| Galette / Frites | 6.50/7.00/2.50/4.00 | unchanged | same | MATCH |

So **post-V2 the web menu matches the central DB exactly** (11 cats + every main
item + 8-bowl family + prices). The web header's parity claim was TRUE as of V2.

**BUT V3 (`MenuHealLightV3Command`, read in full) then changed the DB and the web
side never followed — two REAL, current drifts:**

1. **Item 490 "Big Chicken" -> renamed "Chicken Burger Special"** (V3 L97-99,
   335-355). Web `data/menu.js:336` still ships **"Big Chicken"**. NAME DRIFT: the
   public web site advertises a product name no longer in the live catalogue.
2. **Bowl sauce set diverged.** V3 restricted bowl step-1 sauces (attr 330) to
   **Spicy + Sauce fromagere maison** only (V3 L104-105, 410-427) and consolidated
   the 4-step bowl composer to 3-step. Web bowls (`data/menu.js:258-275`) are
   3-step (count now coincidentally matches) but still expose **all 11 sauces** at
   step 1. CHOICE-SET DRIFT (web 11 vs live DB 2).

**Verdict: PARITY proven at the V2 layer; DRIFT proven at V3.** The web standalone
is frozen at the post-V2 snapshot and missed the V3 heal (Big Chicken rename +
bowl-sauce restriction). Real catalogue drift, low-impact for a non-transactional
marketing site. Residual [UNVERIFIED]: V2-Round2 + V3.1-Burger (not read) may add
more deltas; Supplements/Desserts/Boissons not line-diffed (web supp 0.90 /
desserts 3.80 / drinks 1.00-1.50 consistent with V2 `SUPP_NEW_PRICE=0.90`).

---

## V1-scope recommendation — DEFERRED (V1.0.x / future)

Rationale (BRAIN mandate: V1 = owner's personal POS+Kiosk+KDS+OSS+Admin+Stock/
Sync+Livreur):
1. Web is **self-declared standalone, no backend wireup, mock checkout** — cannot
   place real NF525 fiscal orders, so zero V1 production-correctness weight.
2. **Build-less CDN-React demo** in its own git repo outside the Laravel app — a
   marketing/design prototype, not a deployable surface.
3. The companion **mobile app is missing** from its documented path, so the
   "2 standalone frontends" program is indeterminate and must not gate V1.

Backlog (V1.0.x): re-locate or retire the mobile tree; re-snapshot web `menu.js`
from post-V3 DB; add an automated central<->web parity gate; decide a real
toolchain/backend integration before treating web as a live surface.

---

## Findings (adversarial)

- **[P1] /Users/1millnonstop/Downloads/mobile (absent) — documented standalone
  geometry is stale.** Mobile tree gone from documented path; web is React-CDN,
  not Vite/Vue as memory states. Planning on these paths/stacks = false geometry.
  **Fix:** correct PROJECT_BRAIN/MEMORY (real web stack + missing-mobile fact);
  locate or formally retire the mobile tree.
- **[P1] SSOT drift: web stuck at post-V2 snapshot, missed V3 heal.** Web prices
  all match the central DB after MenuHealLightV2, but two V3 changes were never
  mirrored to `data/menu.js`: (1) item 490 renamed "Big Chicken" -> "Chicken
  Burger Special" (`MenuHealLightV3Command.php:97-99,335-355`) while web still
  ships "Big Chicken" (`data/menu.js:336`); (2) bowl step-1 sauces restricted to
  Spicy + Sauce fromagere maison in DB (V3 L104-105,410-427) vs web exposing all
  11 sauces (`data/menu.js:264`). Header asserts parity with **zero enforcement**.
  **Fix:** (a) re-snapshot `data/menu.js` from post-V3/V3.1 DB (rename +
  bowl-sauce set); (b) add a CI parity script diffing item names+prices+bowl-sauce
  set DB<->web so the next heal cannot silently desync.
- **[P2] /Users/1millnonstop/Downloads/web — 6 competing CSS versions + dup JSX**
  (`styles.css`+`styles-v2..v5`+`styles-mobile`; `screens.jsx`+`screens-v3.jsx`;
  `flows.jsx` "legacy ... superseded by v2" per README L20). All loaded together
  (index.html L10-15) -> cascade-collision / dead-code hazard. **Fix:** collapse
  to one stylesheet + one screen set.
- **[P2] /Users/1millnonstop/Downloads/web/data/menu.js:354-427 — hardcoded prices
  + client price calculator.** Bols 8.90, supp 0.90, formule +2.50, `priceFor()`
  computes client-side. Safe while standalone, but on any future wireup this MUST
  defer to backend `PricingService` (NF525 SSOT), never drive checkout totals.
  **Fix:** on wireup, make menu.js display-only.
- **[P2] /Users/1millnonstop/Downloads/web/index.html:20-22 — CDN React via unpkg,
  `*.development.js` builds in a "site officiel".** External-CDN dependency + dev
  React = perf/availability/supply-chain risk if ever deployed. **Fix:** real
  bundle before any production use.
- **[P3] README references `Le Cayenne — Website.html` entry, but tree ships
  `index.html`.** Minor doc drift. **Fix:** align README.

### Re-run items (close residual)
1. Read `MenuHealLightV2Round2PatchCommand.php` + `MenuHealLightV31BurgerCommand.php`
   to finish the heal chain; line-diff central Supplements/Desserts/Boissons rows.
2. Re-locate mobile tree:
   `find /Users/1millnonstop/Downloads -maxdepth 5 -name app.json -not -path '*/node_modules/*'`.
