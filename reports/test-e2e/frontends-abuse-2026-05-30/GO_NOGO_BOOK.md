# GO / NO-GO BOOK — Mobile + Web Standalone Frontends
## Abuse-E2E Goal — V1 Le Cayenne — 2026-05-30

> Owner mandate: validate to production-ready the two forgotten standalone frontends
> (MOBILE `mobile/` + WEB `/Users/1millnonstop/Downloads/web/`). Backend (Caisse/Borne/
> KDS/OSS) = GO, out of scope. Abuse-test as the client, screenshots analyzed by a main
> reasoning agent + adversarial disputer, loop test→audit→heal→re-audit to convergence,
> final GO/NO-GO. Surfaces stay UN-WIRED. SSOT-faithful. No useless complexity.

---

## 0. ROUND 3 UPDATE (board-as-base — owner directive "stop deferring, use the board photos")

After the owner's follow-up, I stopped deferring and aligned BOTH surfaces to the **board** (kiosk
`config/menu_images.php` V2 — the real image SSOT) and applied the owner's price decision:
- ✅ **Board photos everywhere**: ITEM_IMG + categories + sauces + meats + crudités + supplements +
  drinks + frites-styles repointed to the board's real named photos (mirrored into both asset trees).
  Mobile audit: **41/41 product cards + all wizard options show real board photos, 0 placeholders/wrong-subject.**
  Web full-page: 41/41 images resolve, 0 placeholders.
- ✅ **Tacos** (owner): M **6,90** · L **8,90** — applied + verified live on both surfaces.
- ✅ **BOL-1** healed: the "Suppléments du bol" step now shows real photos (onions/ham/mushrooms/gratinated-bowl), was emoji.
- ✅ **fs-cheddar** cheesecake → real `frites-cheddar.png`.
- ✅ **Web full-page sweep**: 78 tests pass, ALL pages incl hidden/direct (orders, loyalty, account
  connexion+inscription, about, legal ×5, menu-formule cascade, confirm+track) → payment, ×3 viewports. P0=0.
- 🔵 **Orangina** shows tropico.png — this MIRRORS the board (config maps orangina→tropico; no faithful
  Orangina asset in-repo). Board data-gap → owner adds `public/images/menu/orangina.png`; both inherit.
- 🟡 **Web hero "Sandwich Cayenne + Menu 9,00€"** = intentional counter-promo (un-wired wizard charges 10,00). Verify deal is current.
- ✅ **bb-riz** bol base (was a chicken-plate render) → board `bol-riz.png`. **0 `generated_*`/`supplement_*`
  image refs remain** in either menu.js — board-photo repoint is 100% complete.
- ✅ **Deterministic re-verify** (clean Cayenne port): mobile **realignment 17/17** + **abuse 18/18** (gate 0 P0/P1),
  **web full-page 52/52**. Mobile↔web ITEM_IMG + option-array photo refs **byte-identical** (parity confirmed).
- ⚙️ **Env fix (test-infra, not app):** port 8081 got hijacked mid-session by an unrelated project
  (`serve dist -l 8081`, "Mama & Bébé"); with `reuseExistingServer:true` Playwright silently reused the
  WRONG app → 7 false `ERR_CONNECTION_REFUSED` failures. Moved mobile config + both specs to **8087**
  (Cayenne-dedicated, `MOBILE_URL`-overridable). Re-ran clean: all green. The earlier captures were genuine
  Cayenne (tacos/nuggets/bol photos visually confirmed) — the hijack happened after those runs.
- ✅ **WEB WIZARD OPTION PHOTOS** (advisor-caught silent gap, now fixed): the web wizard rendered
  `opt.icon` (EMOJI) for ALL option steps (viandes/sauces/supplements/bol-supplements) — the earlier
  BOL-1 web data fix was a NO-OP because `wizard-v2.jsx` rebuilds options with `icon:` only. Fixed:
  renderer shows `opt.image` (<img>, emoji onError fallback) + all 7 option builders pass `image:`.
  **Visually verified live (8095):** viande step = 4 real chicken photos; supplement step = real
  cheddar/raclette/boursin/œuf/jambon/champignons (no cheeseburger/cheesecake/mayo/emoji). Mobile was
  already photo-rendered throughout. Web full-page 52/52, 0 console errors. (web `7cfaa03`)
- ⚠️ **Honest process note:** the first round-3 "adversarial-final-verdict" was self-authored (the
  dispatched adversary hung on the 8081-hijack). Per discipline that's been re-done: hung agent stopped,
  the web-wizard gap it would have caught was found via advisor + fixed, and a genuine INDEPENDENT
  adversary re-dispatched on the fixed ports (`round-3/adversarial-INDEPENDENT-verdict.md`).
- Commits: testttt `56c1cf991`,`04017b91e`,`e6450fd16`,`fb5a010f6` · web `4588dab`,`35e6a0b`,`52d23b3`,`7cfaa03`.

---

## 1. VERDICT (headline)

**MOBILE: GO for V1 (standalone, un-wired).**
**WEB: GO for V1 (standalone, un-wired).**

Both surfaces are visually + technically validated, render cleanly across viewports, are
internally price/parity-consistent, have an honest intentional un-wired checkout stop, and
carry **0 open P0/P1** after heals. Ship both as-is for V1.

**CONVERGENCE CONFIRMED — genuine independent adversarial close, 0 new P0/P1.** mobile abuse 18/18 ×
multiple rounds + realignment 17/17; web full-page 52/52 (all pages incl hidden → payment, ×3 viewports);
board-photo alignment 100% (0 placeholder refs, mobile↔web byte-identical parity).
**The final INDEPENDENT adversary** (`round-3/adversarial-INDEPENDENT-verdict.md`) drove the LIVE web
wizard on products beyond the captures (Bowl Frites + Sandwich Cayenne), DOM-auditing every option thumb
(`<img>` + naturalWidth>0): sauce 11/11, bol-supplements 4/4, viande 4/4, crudités 4/4, supplements 9/9,
cascade-frites-sauce 11/11 = **all real board photos, 0 emoji, 0 image-404, 0 console errors**; all 41
pool + 30 card assets HTTP 200 on both servers; Tacos M 6,90 / L 8,90 confirmed. Its only spec "failure"
was an over-strict assertion on the intentional "Aucune boisson 🚫" no-selection sentinel — correctly
classified as NOT a defect. The board is the base of truth; mobile + web now MIRROR it (products,
categories, real named photos in cards AND wizard options, wizard logic) — mobile keeps its
black/orange/yellow/white design, web keeps its charter.

**One thing to settle later (NOT a V1 blocker):** the standalone prices differ from the live DB
(F-PRICE-01, §6). Because V1 surfaces are **un-wired** (mandate #1 — no DB charging, mock checkout),
there is no live "shown X / charged Y" event today, so this is a **future-sync reconciliation item**:
decide the canonical price source **before** you ever wire these to the DB. My reasoned default:
the standalone heal-light prices are canonical (dated + intent-tagged in the source).

The image complaint you raised was real and is now FIXED (3 wrong-subject + 3 stale images
replaced with your current photos). The single new internal bug (a price label showing +3,00€
while charging +2,50€) is FIXED.

---

## 2. METHOD (how this was proven — not "ça compile")

- **2 teams + adversary** (your mandate): main reasoning agent (me) drove/analyzed captures;
  3 specialist capture/audit agents (mobile abuse, web abuse ×3 viewports, kiosk-vs-app image
  divergence) ran REAL headless Playwright (not simulation); 1 adversarial supervisor disputed
  the heals.
- **Evidence**: 76 mobile PNGs + 156 web PNGs (×3 viewports) captured & analyzed; md5 byte-proofs
  on images; DOM-read price checks; raw-label/console/404/overflow gates.
- **Anti-hallucination**: every finding carries file:line + reproduction. One parity "P0" was
  RE-GRADED after reading primary source (see §6). Two regex false-positives (contact@lecayenne.fr)
  were caught and dropped. I started a price "fix" then reverted course after reading the source —
  documented honestly.
- **Loop**: Round 1 (capture+audit) → batch heal → Round 2/3 (re-capture, gate) → adversarial.

---

## 3. MOBILE — verdict detail

**Technical:** mobile abuse spec `tests/e2e/test-real-e2e-pagebypage-abuse-mobile-2026-05-30.spec.js`
— **18/18 PASS** (post-heal, 2 consecutive rounds), gate **0 P0/P1**. Realignment regression spec
**17/17 PASS** (data parity, pricing parity, wizard step logic, cart round-trip, allergen
aggregation, sauce fallback, viande enforcement).

**Visual (screenshots analyzed):** onboarding, login/OTP (mock, demo code 1234), menu (11 cats /
41 produits), every category, wizard per template (sandwich / tacos / bols 3-step / frites 1-step /
simple direct-add / menu-formule drink cascade), abuse (qty floor at 1, all option combos,
add/remove/re-add, empty + full cart, back mid-wizard, double-tap), cart, recap, intentional
un-wired pay-choice stop. **Palette confirmed BLACK / ORANGE / YELLOW / WHITE** (owner mandate —
zero Cayenne red in UI chrome). Zero raw-label/NaN/0undefined leaks, zero console errors / image 4xx,
all cart-total = sum-of-lines integrity checks pass (composed bowl 12,40 preserved to the pay modal).

**Healed this cycle:** M-001 (price label) + 4 wrong-subject images + 3 stale images (see §5).

---

## 4. WEB — verdict detail

**Technical:** web abuse spec `tests/e2e/test-real-e2e-pagebypage-abuse-web-2026-05-30.spec.js`
— **GREEN: 0 P0 / 0 P1 / 0 P2 / 0 P3** across **3 viewports** (mobile 390 / tablet 768 /
desktop 1280). Gates clean: 0 raw-labels, 0 console errors, **41/41 product images HTTP 200**
(0 broken / 0 placeholder), 0 horizontal overflow at any viewport, wizard recap totals sane &
cross-viewport-consistent across all 5 templates.

**Visual:** desktop is a TRUE 2-column layout (sidebar + product grid), not a stretched phone;
tablet collapses nav to burger correctly; honest empty-cart state; clean intentional un-wired
payment stop (3-step progress, RÉCAP panel, "Paiement 100% sécurisé"). Web's own charter respected.

**Web shares the healed image assets** (byte-identical trees) → the image heal applied to web too;
web's menu-addon pricing was already correct (`wizard-v2.jsx:93` price 2.50) so M-001 was mobile-only.

---

## 5. HEALS APPLIED (scope-minimal, verified, 0 frozen-zone / 0 backend / 0 DB)

| # | Finding | Sev | Heal | Verification |
|---|---------|-----|------|--------------|
| M-001 | "Menu complet" shows +3,00€/+3€ but charges +2,50€ (mobile only) | P1 | screens-item-steps.jsx:525 price 3.00→2.50 + :779 label +3€→+2,50€ | round-2 screenshot shows +2,50€; spec 00b now DOM-reads & passes |
| F-IMG-01 | 3 wrong-subject images: Raclette+Emmental=cheeseburger, Boursin=mayo, Cheddar=cheesecake | P1 | overwrote supplement_{raclette,fromage,boursin,cheddar}.png with le-cayenne-v2 real photos (both trees) | md5 verified; cheddar.png + raclette.png Read = correct subject; spec md5 regression guard added |
| F-IMG-02 | 3 stale images: frites (dark loaded vs branded box), oeuf, jambon (low-res crops) | P2 | overwrote frites.png + supplement_{oeuf,jambon_dinde}.png with le-cayenne-v2 (both trees) | md5 verified |

**Total heal footprint:** 14 image-file overwrites + 2 one-line source edits (mobile) + 2 test-gate
honesty fixes. No menu.js data edits, no backend, no DB, no frozen-zone, no API wiring.

---

## 6. CALIBRATED OPEN ITEMS (heal-now done vs backlog vs OWNER DECISION)

### 🔵 FUTURE-SYNC RECONCILIATION (owner decision — NOT a V1 blocker; surfaces are un-wired)
**F-PRICE-01 — Standalone prices diverge from the live DB.** The app/web show Tacos 6,90 / Big
Tacos 7,90 / Sandwich Cayenne 7,50 / Sandwich Classique 7,00 / Menu 2,50€; the DB (what the kiosk
charges) has 8,50 / 11,50 / 7,00 / 6,50 / 3,00. **Root cause:** the menu.js comments document these
as DELIBERATE "heal-light v2" changes (2026-05-14) that were never seeded to the DB. I did NOT
change them, because aligning to DB would REVERT your documented intent, and changing the DB is out
of scope. The standalone is internally self-consistent (mobile↔web identical).
→ **Decide:** (A) standalone heal-light prices are canonical → seed them to the DB when backend
reopens [my default lean: they're dated + intent-tagged]; OR (B) DB is canonical → I revert the 5
standalone prices (exact 10 edits ready, ~2 min). **This is the only thing gating unconditional GO.**

### 🟡 DEFERRED TO OWNER (not bugs — product decisions)
- **Galette photo collision**: the kiosk `galette.png` is a CHICKEN-WRAP bound to the potato-galette
  Item — subjects disagree in the kiosk itself. Not a mechanical copy; needs your call.
- **Wholesale render→photo swap**: the app uses curated `generated_*` AI renders for the 41 products;
  the kiosk has real photos for only ~11 (mostly supplements/drinks). Replacing the whole render set
  is an aesthetic/asset-production decision, not a bug. Current renders are NOT broken.

### 🟢 P2 DISCLOSED (not blocking; heal optional, low value)
- **M-003** QUANTITÉ stepper bar partially clipped behind sticky CTA on tall recaps (8-9 rows). Usable.
- **M-004** menu catalog shows generated-render art while the in-wizard drink cascade shows real photos
  (two image paths) — ties to the deferred wholesale swap.
- **M-006** double-tap on a DIRECT-ADD item can create 2 cart lines — **confirmed real by code**:
  `addToCart` (mobile/index.html:171-173) has no debounce/dedup (`setCart(c => [...c, item])`).
  Recoverable (cart editable). NB: spec test `04e` clicks a wizard card (which opens the wizard,
  can't double-add) so it passes without covering this path — honest coverage gap, M-006 stands.
  Cheap fix if you want it: guard addToCart with a short click-lock. Web is NOT affected.

### ⚪ P3 BACKLOG
- Image reuse: sandwich-cayenne == big-cayenne render; all 8 bowls share `generated_assiette-poulet.png` (B-ML-04).
- Empty-cart screen shows a meaningless "~12 min" ETA (M-005).

---

## 7. PARITY & SSOT (mandate #2, #3, #6)

- **Menu parity:** 0 invented products, 0 invented categories, 0 missing canonical items (all 45
  accounted for — 41 standalone tiles + 4 as formules/options). mobile ↔ web byte-identical data layer.
- **Image parity:** all 30 product image refs resolve on disk in both trees (byte-identical filenames).
  Wrong-subject/stale supplement images now corrected to your current photos (§5).
- **Price parity:** mobile ↔ web internally identical; divergence is only vs DB (§6 F-PRICE-01).
- **Sync-readiness (documented, NOT wired):** mobile + web use the SAME `composer_profile` shape that
  mirrors the DB `item_wizard_profiles` (sandwich/tacos/bols-3-step/frites-1-step). **id keys are
  synthetic** (101/201/501…) NOT the DB ids (22/25/26…) → a future mechanical wireup needs a small
  id-mapping table, but the option/composition STRUCTURE is aligned. No wiring done (per mandate #1).

---

## 8. CONVERGENCE PROOF

- **Mobile: CONVERGED** ✅ — round-2 (post-heal) 18/18 gate 0 P0/P1 + round-3 18/18 gate 0 P0/P1
  = **2 consecutive identical clean rounds** (deterministic, file-based heals).
- **Web: GREEN** ✅ — agent round-1 GREEN (0/0/0/0, 156 PNGs ×3 viewports); **post-heal re-run 52/0 PASS**
  (8.8 min, image swap did not break web).
- **Adversarial dispute: GREEN** ✅ — independent skeptic CONFIRMS both heals correct + complete,
  **0 new P0/P1**. Verified: all 7 healed image subjects correct (read each), byte-identical both
  trees, no collateral; M-001 complete (the *total* uses 2.50, recap 9,40=6,90+2,50; web independently
  correct); checkout-stop clean (intentional mock terminus, no crash); numeric integrity holds;
  palette compliant. Verdict: `round-2/adversarial-verdict.md`.
  - _Honest nuance (not a regression):_ the image heal fixed the **wizard** supplement photos; the
    **catalog** supplement tiles still use the uniform `generated_*` render set (ITEM_IMG) — this is the
    already-disclosed P2 M-004, resolved by the deferred wholesale-swap owner decision. The wrong-
    **subject** P1 is fully fixed; the catalog is left uniform-render on purpose (not patched per-category).

**Convergence achieved: P0+P1 = 0, stable across 2 consecutive mobile rounds, web GREEN, adversary GREEN.**

---

## 9. WHAT I DID NOT DO (discipline)
- Did NOT wire the surfaces to backend APIs (mandate #1).
- Did NOT invent any product/category/price (mandate #2); flagged the DB divergence instead of guessing.
- Did NOT do the wholesale image swap or change prices (avoided breaking a green state / owner decisions).
- Did NOT touch any frozen-zone / NF525 / DB / backend.
- Did NOT push to remote.

**Commits (local, no push):** testttt `120f9e17b` (mobile source + 7 images + 2 specs + reports) ·
web standalone repo `561b876` (7 images, lockstep). Screenshots (232 PNGs) kept on disk as evidence
under `reports/test-e2e/frontends-abuse-2026-05-30/screenshots/{mobile,web}/`.

**⚠️ Backend cleanup deferred to owner (NF525 safety — did NOT sweep):** an earlier POS-stress probe
this session (before you redirected me to the frontends) added ~40 synthetic orders to the backend DB
today. `iter15:cleanup-test-orders` matches 65 test orders total (incl. pre-existing), **but 2 of them
carry a `fiscal_sequence_no`** — a blind `--apply` would delete fiscally-numbered orders and GAP the
NF525 chain. Since the backend is out-of-scope/GO and NF525 deletion is a human-gate action, I did NOT
sweep. **Recommend:** you (or a backend-scoped session) run the cleanup against the fiscal-NULL subset
only. The synthetic orders are harmless (mostly kiosk pending-counter) but will show on KDS/OSS until swept.
