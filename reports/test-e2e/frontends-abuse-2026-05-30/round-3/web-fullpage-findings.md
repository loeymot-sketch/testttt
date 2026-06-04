# Web standalone — FULL-PAGE sweep findings (round 3)

**Date:** 2026-05-30
**Target:** STANDALONE WEB site (V1 Le Cayenne) — React SPA at `/Users/1millnonstop/Downloads/web/`, served on `http://127.0.0.1:8095/`
**Mandate:** "the website must be aligned with ALL the pages until the payment page — all pages, even the direct or hidden ones."
**Method:** Playwright full-page captures (`fullPage:true`) at 3 viewports (mobile 390 / tablet 768 / desktop 1280) + multimodal vision (PNGs Read directly) + disk-level subject verification of every board photo.

**Specs:**
- `tests/e2e/test-real-e2e-fullpage-web-round3-2026-05-30.spec.js` (NEW this round — the surfaces round-1 did not touch)
- `tests/e2e/test-real-e2e-pagebypage-abuse-web-2026-05-30.spec.js` (round-1 — home/menu/wizards/cart/checkout, re-run this session)

## Verdict

**Automated/render sweep: P0 = 0 · crash/blank/overflow/raw-label/404 = 0** across both specs × 3 viewports (39 + 39 = 78 tests, all pass).
**Content findings: P1 = 1 (deferred — cannot close in scope) · P2 = 1.**

- **P1 (deferred):** wrong-subject photo — "Orangina 33cl" renders a **Tropico can** (`tropico.png`). This is a P1 per the rubric ("wrong-subject ... image"). **Cannot be closed in scope:** no faithful Orangina asset exists on disk (`tropico.png`=Tropico, `orangina.png`=generic dark cola, `fanta-orange.png`=Fanta) — no scope-minimal ref-swap resolves it. Shared mobile↔web data-quality gap; owner must source a real Orangina photo, then both frontends update in parity. Not fixed (would otherwise substitute one wrong subject for another).
- **P2:** cross-page price inconsistency — home hero advertises "Sandwich Cayenne + Menu à **9,00 €**" but the wizard charges **10,00 €** (7,50 + Menu complet 2,50), with no path to deliver the advertised deal. Verify intentional promo vs. stale hero price.

Board-photo alignment: **GREEN except the Orangina P1 above** — all 41 product images resolve HTTP 200, every other checked photo depicts the correct subject, **0 generated_\* placeholders** reach any live item.

## Board photo SUBJECT verification (vision, opened the PNGs)

| Asset | Mapped to (item/option) | Subject observed | Verdict |
|---|---|---|---|
| sandwich-cayenne.png | Sandwich Cayenne | chicken sub + Cayenne sauce | OK |
| tacos.png | Tacos M/L | French-style grilled wrapped tacos | OK |
| bol-frites.png | Bowl Frites | loaded fries bowl, Le Cayenne branded tub | OK |
| bol-riz.png | Bowl Riz | rice + grilled chicken + sauce, branded tub | OK |
| burger-cheese.png | Chicken Burger | chicken cheeseburger, brioche bun | OK |
| raclette.png | supp Raclette | raclette cheese slices | OK |
| fromage.png | supp Emmental | grated cheese | OK |
| cheddar.png | supp Cheddar | cheddar slices | OK |
| boursin.png | supp Boursin | herb cream cheese | OK |
| ben-jerrys.png | Glace | Ben & Jerry's tub (Strawberry Cheesecake) | OK |
| viande-marine.png | wizard viande | grilled marinated chicken | OK |
| sauce-harissa.png | wizard sauce | red harissa in ramekin | OK |
| salade.png | crudité Salade | shredded lettuce | OK |
| tropico.png | "Orangina 33cl" option | **Tropico can (wrong subject/brand)** | **P1 (deferred — no faithful asset)** |
| orangina.png (unused) | — | generic glass of dark/iced cola (NOT Orangina) | not a usable fix |

All 51 referenced PNGs exist on disk (0 missing); round-1 image audit: **41 items, 0 broken, 0 emoji-only**.

## Per-page results (round-3 — pages round-1 did not cover)

| Page | Viewports | Board photo OK? | Defects | Severity |
|---|---|---|---|---|
| orders (route 'orders', nav "Commandes") | mobile/tablet/desktop | n/a (guest CTA screen) | none — "Connecte-toi pour retrouver" CTA, footer intact | clean |
| loyalty (guest) | mobile/tablet/desktop | n/a | none — reward rules clear (1€=1pt, 500pts=5€, +25 inscr.) | clean |
| loyalty (authed) | mobile/tablet/desktop | n/a | mock auth routes to About content (markers note) — page renders fully | clean (INFO) |
| about / L'enseigne | mobile/tablet/desktop | signature chili hero OK | none — story sections, sauce grid, FAQ, footer all render | clean |
| AccountFlow — Connexion (login) | desktop (verified) | n/a | none — "SALUT, CHEF", Email + Mot de passe + "OUBLIÉ?", Google/Apple SSO, "Se connecter" | clean |
| AccountFlow — Inscription (register) | mobile/tablet/desktop | n/a | none — "BIENVENUE, CHEF", Prénom/Email/Téléphone, +25 PTS, SSO | clean |
| ItemDetailModal | mobile/tablet/desktop | signature chili hero OK | none — name, nutrition, 7,50 € price, Personnaliser CTA | clean |
| menu-formule CASCADE (full formule → drink/frites/sauce) | mobile/tablet/desktop | sandwich photo in recap OK | none — cascade fires, recap total **10,00 €** valid (7,50 + Menu complet 2,50) | clean (INFO) |
| confirm (hidden post-payment) | mobile/tablet/desktop | n/a | none — "C'EST PARTI!", QR ticket C-7855, total 7,50 € | clean |
| track (hidden post-payment) | mobile/tablet/desktop | n/a | none — "EN PRÉPARATION ~12 MIN", 4-step progress, total 7,50 € | clean |
| checkout | mobile/tablet/desktop | n/a | none — funnel step 1, pickup details | clean |
| payment (the un-wired stop) | mobile/tablet/desktop | n/a | none/intentional — 4 methods, RÉCAP total 7,50 €, clean mock (no crash) | clean |
| legal/mentions.html | mobile/tablet/desktop | n/a | none — full mentions légales + footer | clean |
| legal/cgv.html | mobile/tablet/desktop | n/a | none — 14 numbered CGV articles | clean |
| legal/privacy.html | mobile/tablet/desktop | n/a | none — RGPD policy renders | clean |
| legal/cookies.html | mobile/tablet/desktop | n/a | none — cookie policy renders | clean |
| legal/allergens.html | mobile/tablet/desktop | n/a | none — allergen table renders | clean |

## Automated checks (per capture, all GREEN)
- Raw-label leak sweep (`Label.`, `kiosk.`, `lecayenne.x.y`, `0undefined`, `undefined €`, `NaN`) — **0 hits** on any page.
- Horizontal overflow (scrollWidth > innerWidth) — **0** at any viewport (no stretched-phone desktop; desktop is a real layout).
- Console / pageerror — **0** real errors (favicon noise filtered).
- Image 404/error response sniff — **0**.
- Blank/white-screen guard (<40 visible chars) — **0** (every page rendered).
- Recap total integrity (cascade) — valid, non-NaN, line-sum consistent.

## Prices (owner mandate 2026-05-30)
- Tacos M = **6,90 €**, Tacos L = **8,90 €** — confirmed in `data/menu.js` (items 501/502) and rendered. ✓
- No NaN/undefined prices anywhere. Recap/payment totals equal line sums (within-page).
- **P2 — cross-page price inconsistency.** Home hero (`screens.jsx:173-177`) advertises "Sandwich Cayenne + Menu à **9,00 €**" (struck-through 11,00 €, "Du jour" promo pill). The wizard for the same combo charges 7,50 (sandwich) + 2,50 (Menu complet) = **10,00 €** (verified live at cascade recap, all viewports). No mechanism delivers the advertised 9,00 € deal price. Verify intentional promo (needs a coupon/deal path) vs. stale hero copy.

## Open items (honest)

- **P1 (deferred — cannot close in scope) — "Orangina 33cl" → tropico.png (wrong subject).**
  `data/menu.js:66` and `:167` map the Orangina item to `tropico.png` (a Tropico can — different product). Mobile `mobile/data/menu.js:91,209` has the *identical* mapping (current mobile↔web parity). The unused `orangina.png` on disk depicts a generic glass of dark cola — also NOT a faithful Orangina, and `fanta-orange.png` is Fanta. **No asset on disk depicts an Orangina**, so no scope-minimal ref-swap fixes it; swapping web-only would substitute one wrong subject for another and break parity. **Action:** owner must source a correct Orangina photo; then update `tropico.png`→new asset in BOTH `data/menu.js` and `mobile/data/menu.js` (parity). Affects both frontends equally today; not a web-only regression.
- **INFO — loyalty-authed mock auth.** The standalone mock's account-submit path does not deterministically flip to an authed loyalty view (lands on About content in this run). This is mock plumbing, not a render defect; the loyalty *guest* + authed pages both render without error. No fix (standalone un-wired V1 by mandate).
- **Cosmetic — wizard/detail modals on full-page captures.** `fullPage:true` over a short modal on a long page produces large whitespace below the modal in some PNGs. Capture artifact only; the live modal is centered/correct.

## Files
- Screenshots: `reports/test-e2e/frontends-abuse-2026-05-30/screenshots/web-fullpage/` (55 PNGs, 0 zero-byte; incl. `web-desktop-account-login-CONNEXION.png` = verified login form) + round-1 set in `screenshots/web/`.
- Findings JSON: `round-3/_findings-{mobile,tablet,desktop}.json`.
- No web app source modified. No commits.
