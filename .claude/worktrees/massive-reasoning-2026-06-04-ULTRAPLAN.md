# ULTRA-PLAN — Actions from the Massive Reasoning E2E (Caisse + Borne)
**Date:** 2026-06-04 · derived from 40 real orders (20 POS + 20 kiosk) + surfaces/lifecycle captures, agent analysis, and adversarial dispute.
**Branch:** `heal/cms-pr1-quickwins-2026-05-18` @ `beae2d64e` · report: `reports/test-e2e/massive-reasoning-2026-06-04/REPORT.md`

> STATUS: FINAL — caisse re-analysed twice (v2 batched-dispute recovered 19 v1-lost findings); borne/surfaces/lifecycle/refund all landed. Counts below are deduped + adversarially verified.

## 0. Verdict
- **POS (caisse) + Borne (kiosk) order pipelines = FUNCTIONALLY GREEN**: 20/20 POS paid (cash+card) + 20/20 kiosk journeys with Plan-B counter routing; correct products/prices; NF525 chain intact, gap-free. **No P0 functional break, no pricing error.**
- **Refund ("rembourser un client") = interface STRONG + captured; RBAC correct; live mutation owner-gated (not executed).**
- **33 distinct confirmed UI/UX defects** — **4 P1 / 9 P2 / 20 P3** — adversarially verified and **deduped across surfaces** (per-surface: caisse 18 [42 raw→38 conf→18 distinct], borne 6, surfaces 8+1 shared, lifecycle 1). None blocking; all presentation/polish:
  - **4× P1**: (1) two "Sandwich …" category tabs truncate identically (cashier can't distinguish Cayenne/Classique); (2) FR NF525 receipt prints English legal terms "VAT"/"Article Description" (must be TVA/Désignation); (3) receipt **"Opérateur : Client passage"** — the *customer* placeholder printed in the *cashier* field; (4) POS first-page title "Commande rapide" overlapped by the redirect-button row.
  - **9× P2**: receipt 12h-AM time (also in refund modal); "1 Articles" plural; receipt drops the Crudités line; fries-modifier label divergence; currency "7.00€" vs "7,00 €"; English "Upsell item" on FR cards; kiosk sidebar icons all = one burger; KDS "+42" badge overlap; KDS "ATTENTE" header clip.
  - **20× P3**: placeholder phone "+33600000000" on the ticket; VAT-rate-format inconsistency; space-before-comma; name-casing cart-vs-receipt; cash-receipt "À emporter" wrap; footer 3-line wrap; cart-TTC-vs-receipt-HT label gap; kiosk CTA contrast; euro-prefix; + locale/typography/truncation nits across both surfaces.
- **The adversarial layer is the trust gate**: it refuted/withdrew **6** over-stated or measurement-artifact claims (pepper-emoji that's a pencil icon; "gender error" that's correct mixed-list grammar; "Écran client broken" un-capturable on single-tab; my own tracker/KDS "empty" early-capture+wrong-selector error) **and self-corrected one false-refute** (v1 wrongly killed "1 Articles"; v2 confirmed it across 3 zoom batches).
- **Lifecycle/sync = GREEN, verified** (caisse→tracker→KDS, borne→encash→KDS).

## 1. How these were derived (evidence chain — trust basis)
- Real Playwright drives: cashier persona (POS V5) + client persona (kiosk), one order at a time, `workers:1`, screenshots at every step (00-start → wizard → cart → payment → after), per-order JSON, DB cross-check.
- Each candidate defect was raised by a UI-critic agent **and** then re-verified by an independent **adversarial** agent that defaults to refute. Only screenshot-unambiguous defects survived. The dispute layer killed 3 over-stated/hallucinated claims (see REPORT §9) — this is the quality gate, not decoration.
- Hard guardrail honored throughout: prices are PRE-update (owner's 2026-06-04 list not applied) → no "wrong price" findings manufactured.

## 2. Prioritized actions

### P1 — fix before any demo/use
**A1 · Category bar: two indistinguishable "Sandwich …" tabs**
- *What:* On the POS first-page category bar, "Sandwich Cayenne" and "Sandwich Classique" both render as the clipped string "Sandwich …" — the truncation removes the only differentiating word. A cashier cannot tell them apart.
- *Where:* POS V5 category pill component (`.pos-v5-category.pos-v4-category-pill`) + its CSS width/ellipsis. Live, data-driven (NOT the frozen Vanilla-JS wizard).
- *Fix options:* (a) raise pill min-width / allow 2-line wrap; (b) shorten labels to the distinctive token ("Cayenne", "Classique"); (c) abbreviate the common prefix. Prefer (a)+(b).
- *Accept:* every category pill is uniquely readable at the POS default viewport; no two pills share identical visible text.

**A2 · Fiscal receipt mixes English on FR locale (NF525-sensitive)**
- *What:* The client ticket shows "VAT (10.00 %)", "TOTAL TAXES", "Article Description", and "05:41 AM" (12-hour) next to French labels. On a French NF525 receipt the legal term is **TVA** and time is **24-hour**.
- *Where:* the POS receipt/ticket template + i18n + date formatting (receipt render path; check the ticket Blade/Vue + the `currencyFormat`/date helpers). **Not** a price/amount change → NF525-safe to fix (presentation only), but VERIFY the change touches no frozen Fiscal service.
- *Fix:* localize the receipt strings to FR ("TVA", "Description", "Quantité"/"Qté", "Sous-total", "Total TVA"), force 24-hour time (`HH:mm`), French number format.
- *Accept:* receipt is 100% French, 24-hour clock, "TVA" everywhere; regression run of one cash + one card sale shows a clean FR ticket; NF525 chain `fiscal:verify-chain --all` still OK; zero frozen-zone diff.

**A2b · Fiscal receipt "Opérateur : Client passage" — wrong field bound (NEW, NF525 cashier-attribution; ROOT CAUSE CONFIRMED)**
- *What:* every anonymous POS sale (the normal walk-in case) prints **"Opérateur : Client passage"** — the *customer* in the *operator* field. **Confirmed a real product bug, not an E2E artifact.**
- *Root cause (traced):* `app/Services/Receipt/ReceiptDataService.php:70` computes `'operator_name' => optional($order->user)->name`. Per Order.php's own H.1 comment, `orders.user_id` deliberately stores the **CUSTOMER** (Walking Customer id=2 = "Client passage"); the **cashier** is in **`creator_id`** (verified id=3 "Caissier Le Cayenne" on orders 4113/4182/4201, populated from `Auth::id()` since 2026-05-24). The receipt reads `user` (customer) instead of `creator` (cashier).
- *Fix (1 line, not a frozen Fiscal service — it's a data builder/view):* `'operator_name' => optional($order->creator)->name ?? optional($order->user)->name` (fallback keeps legacy pre-H.1 orders working). Also check `ReceiptComponent.vue:72,287` consume `operator_name` (they do).
- *Accept:* receipt "Opérateur" shows the cashier ("Caissier Le Cayenne"); one cash + one card regression ticket verified; NF525 chain `fiscal:verify-chain` still OK; zero frozen-zone diff.

### P2 — polish
**A9 · Receipt + refund-modal timestamp prints 12-hour "05:41 AM" (NEW)** — force FR 24-hour (`HH:mm`) in the shared date helper. Appears on every receipt **and** the refund modal recap → fix once. *Accept:* no AM/PM anywhere on FR fiscal surfaces.

**A10 · Cart count pill "1 Articles" plural for a single item (NEW; v1-refuted→v2-confirmed)** — pluralize on count (`1 Article` / `N Articles`) via i18n `trans_choice`. *Accept:* singular at count 1.

**A11 · Receipt drops the "Crudités" composition line shown in the cart (NEW)** — the fiscal ticket omits the crudités the cart shows (order-09: cart has "Crudités: Salade, Tomate, Oignon, Cornichon", receipt has none), leaving a dangling "Poulet mariné ,". *Where:* receipt composition-render filters/omits the crudités attribute group. *Fix:* render all composition groups on the receipt (or deliberately + consistently hide free groups on BOTH surfaces). *Accept:* cart and receipt show the same composition lines.

**A12 · Fries modifier label diverges cart "1× Nature" vs receipt "Style frites: Nature" (NEW)** — unify the modifier label across cart and receipt. *Accept:* one representation.

**A3 · Currency format inconsistency "7.00€" vs "7,00 €"**
- *What:* the cart/total sidebar renders "7.00€" (decimal point, no space) while the product grid + queues render "7,00 €" (FR comma + space) — on the same screen, at the numbers the cashier/customer read.
- *Where:* the cart/total formatting helper for the live ticket sidebar (one code path uses the wrong format).
- *Fix:* route the cart/total through the same FR `currencyFormat(value, digits, symbol, position)` used by the grid (comma + non-breaking space + trailing €).
- *Accept:* all monetary values on the POS screen use identical FR formatting.

**A4 · Category-bar label truncation (generic)**
- *What:* "Toutes les …", "Bols Gourm…", "Dess…" ellipsized. Subsumed by A1's layout fix.
- *Accept:* covered by A1.

### P3 — consistency nits (low urgency)
- **A5** Big items: align cart "Viandes: …" with receipt "Viande 1 / Viande 2" (pick one representation across surfaces).
- **A6** Bowl: fix doubled "Sauce: Sauce fromagère maison" and unify cart label "Sauce:" vs receipt "Sauce bol:".
- **A7** Bowl grid card: trim description so it doesn't end on a dangling "·…".
- **A13 (NEW)** Receipt VAT-rate format inconsistent on the same ticket: "VAT (10.00 %)" (line) vs "VAT (10%)" (tax detail) — pick one format.
- **A14 (NEW)** Receipt composition lines have a stray space before the comma: "Poulet mariné ," → FR typography has no space before a comma; fix the attribute-join separator (also removes the orphan comma left when crudités is absent — see A11).
- **A15 (NEW)** Restaurant phone on the ticket is the placeholder "+33600000000" → set the real Le Cayenne phone in restaurant/branch config (data fix, not code).
- **A16 (NEW)** Product/bowl name cased differently cart vs receipt ("curry"/"Curry", "tandoori"/"Tandoori") → normalize casing at one source.
- **A17 (NEW)** Cash receipts wrap "Type de commande: À emporter" mid-phrase ("À" / "emporter") while card receipts fit it on one line → widen the value column / prevent the mid-phrase break (NBSP).
- **A18 (NEW)** Receipt footer "Propulsé par Le Cayenne" wraps across 3 right-aligned lines → widen the footer column or shorten.
- **A19 (NEW, labelling only — NOT a price bug)** Same line shows TTC in cart ("7,00€") but unlabelled HT on receipt ("6,36 €"); totals reconcile (SOUS-TOTAL HT + TVA = TTC). Add an "HT" qualifier on receipt line prices (or show TTC per line) so the cashier doesn't read two unit prices. *Accept:* line-price basis is labelled/consistent across cart and receipt.

## 3. Borne (kiosk) actions (6 confirmed)
**B1 · P2 — Kiosk category sidebar icons all show the same burger placeholder**
- *What:* every left-rail category (BOISSONS, FRITES, BOLS GOURMANDS, DESSERTS, …) renders the identical burger-with-lettuce image; only text labels differ. Confirmed a **data/asset wiring defect** (product-card + wizard-step images load fine on the same frames) across 5+ orders.
- *Where:* kiosk category-rail image binding (category `image`/`icon` field → asset). Likely a missing per-category image → global fallback.
- *Fix:* wire each category to a representative image (or a distinct per-category icon set); ensure the fallback is category-neutral, not "burger".
- *Accept:* each kiosk category shows a distinct, representative icon; no two categories share the burger placeholder.

**B2 · P3 — "Valider ma commande" CTA fails WCAG AA contrast** (frozen kiosk surface → owner gate)
- *What:* primary cart CTA is pale salmon (~#F89273) with white text → measured ~1.93–2.25:1 (fails AA 3:1/4.5:1); reads lighter/less-active than the secondary "À emporter" button above.
- *Fix (owner-gated, KioskWizard/cart is frozen):* darken the fill to brand orange (#F4501E, ~3.49:1) or darken text. *Accept:* primary CTA ≥ 4.5:1 (or ≥3:1 large).

**B3 · P3** — euro prefix "€7,00" → FR suffix "7,00 €" (kiosk currency format helper).
**B4 · P3** — kiosk wizard step-strip labels wrap the "?" to an orphan line → NBSP before "?" or widen the column.
**B5 · P3** — compact cart drawer clips line names ("Grande Fri…") → widen/2-line the drawer row.
**B6 · P3 (low-confidence)** — "Session rafraîchie automatiquement" toast overlaps cart; first confirm it's not an E2E-pacing artifact on a real idle session, then fix toast placement/z-index if real.

## 4. Surfaces (first-page / Suivi / Écran client / KDS / OSS) actions (9 confirmed)
**S1 · P1 — POS first-page header collision** (also fixes S7 eyebrow wrap)
- *What:* the cashier-card title "Commande rapide" is overlapped/clipped by the redirect-button row ("Co"…"rapide" split, "mmande" hidden behind "Suivi commandes"); the "CAISSE LE CAYENNE" eyebrow force-wraps to 3 lines. Static layout/z-index/flex collision at rest on the primary landing.
- *Where:* POS V5 first-page header layout (the title column is under-sized / the button row overlaps it).
- *Fix:* give the title column enough width / proper flex-wrap so the button row sits beside or below the title, never over it.
- *Accept:* "Commande rapide" fully readable; no overlap with the button row at the POS default viewport.

**S2 · P2 — English "Upsell item" subtitle on FR cards** — localize ("Article complémentaire") or hide the internal flag from the operator card. *Accept:* no English on the FR POS.

**S3 · P2 — KDS "+42 en attente" badge overlaps "CAP · MAX" + banner** *(KDS is owner-frozen → flag, do not auto-edit)* — reposition the floating badge so it doesn't cover the capacity indicator/banner.
**S4 · P2 — KDS "ATTENTE" timer-column header clipped on every card** *(KDS frozen → flag)* — widen/wrap the header.
**S5 · P3 — KDS 3-digit-minute timer "149:5…" clipped** *(KDS frozen → flag)* — size the timer slot for 3 digits (note: 149-min is seeded test data; clipping is the defect).
**S6 · P3 — FR capitalization "Tableau De Bord" → "Tableau de bord"** (KDS + OSS topbar i18n).
**S7 · P3 — Suivi-commandes search placeholder cut mid-phrase** "Rechercher par N° ou" → complete it ("…ou nom de client").

**S8 (NEW, P3 observation — low confidence) · Historique "Client" column** — `/admin/historique` ("commandes validées & livrées", owner-named) is functionally clean (FR table, correct "7,00 €", Filtrer + pagination; N° Fiscal "—" is CORRECT for unencashed kiosk orders). The one thing to eyeball: the **Client** column shows "Admin Le Cayenne" on borne rows — verify customer attribution on kiosk orders. Captured `historique/00-historique.png`.

**Follow-up (not a confirmed defect):** "Écran client" redirect — re-test on a real dual-screen/second-monitor setup; the single-tab E2E couldn't determine if it opens a separate customer display (refuted finding F-02).

## 5. Lifecycle / sync ("sortie de la commande") — GREEN, 1 polish item
- **Verified working** (REPORT §7): caisse order → tracker EN PRÉPARATION + KDS "EN COURS·CAISSE"; borne order → tracker À ENCAISSER + KDS "NOUVELLE·BORNE·EN ATTENTE ENCAISSEMENT" → cashier "Encaisser" (Plan B). Tracker shows 52 today; KDS board full (50 + "+42 en attente").
- The initial "tracker/KDS empty" alarm was a **measurement artifact** (my early capture + wrong KDS selector) — withdrawn. No sync defect.
- **A8 (P3) — tracker open-latency**: the Suivi-commandes kanban is briefly all-zeros on open before the poll/WS lands. Add a loading skeleton/spinner so a cashier never misreads it as "no orders". *Accept:* opening the tracker never shows a false empty state.

### Refund ("rembourser un client") — interface CAPTURED + ANALYZED ✅, live execution owner-gated
- **Where it actually lives (corrected):** the refund CTA is on the **order-detail page** (`/admin/pos-orders/show/{id}`, `data-testid="pos-order-refund-open"`), **not** on the tracker kanban. It is gated by `pos-refund` permission = **Admin + Branch Manager only** (POS Operator excluded by design — "mass-refund vector mitigation" in `RolePermissionTableSeeder`). The first automated pass found "no button" simply because it ran as POS Operator → **RBAC working, not a bug**.
- **Interface verdict = STRONG (no functional defect):** modal "💸 Rembourser cette commande" → NF525 badge, irreversibility warning, reason field with **min-5-char validation journaled in the audit chain**, correct disabled→enabled confirm (salmon-disabled → solid brand-orange enabled), FR currency "17,90 €". Captured: `__screenshots__/massive-reasoning/refund/`, dump `refund/refund-capture.json`, spec `tests/e2e/mr-refund-capture.spec.js`.
- **1 defect: R1 (P3)** — the recap date is 12h "05:48 AM" → **same systemic bug as A9**, fix once at the date helper.
- **Owner-gated next step (NOT auto-run):** a live refund E2E (open E2E order → confirm → assert status RETURNED + counter cash entry + NF525 chain still OK). The spec already supports it behind `MR_REFUND_EXECUTE=1`; **run only with explicit owner approval** (CLAUDE.md §10 — fiscal mutation, even though the NF525 counter-entry is append-only/non-destructive).

## 6. Driver / test-infra note (for the next E2E campaign)
- A reusable, hardened POS driver now exists: `tests/e2e/helpers/mr-pos-driver.js` (category→exact-tile, real payment input, re-login recovery, grid scroll). Kiosk driver `mr-kiosk-driver.js` (step-wizard completer + counter checkout) is good for single items; multi-item checkout + fries "+" step need more work for 20/20 automated coverage.
- Capture specs: `mr-caisse-capture.spec.js` (20/20 ✓), `mr-borne-capture.spec.js`, `mr-surfaces-lifecycle.spec.js`, `mr-lifecycle-recheck.spec.js`, `mr-refund-capture.spec.js` (refund UI, Admin, cancel/owner-gated-execute), `mr-historique-capture.spec.js` (commandes validées & livrées).
- Caisse re-analysis: batched-dispute workflow (4 analyzers × 5 orders → 4 batch-verifiers) recovered the 19 findings v1 lost to StructuredOutput flakiness — **batched dispute > per-finding dispute** for reliability (lesson for next campaign).

## 7. NF525 / cleanup ledger
- Campaign created **50 orders, id 4164–4213** (POS capture 4182–4201 €163.10 11cash/9card; kiosk 4205–4213 €70; gate/probe 4164–4181). Fiscal seq consumed ~2002–2040, **gap-free**, chain **CHAIN OK** before+after. Refund pass executed **no** fiscal mutation (cancelled). Sweep recommendation in REPORT §2.

## 8. Owner gates / open questions
- **A2 / A2b receipt fixes:** confirm the receipt template is NOT inside a frozen Fiscal service before editing (should be a view/i18n change). For A2b, first verify the audit-log `operator_id` records the real cashier (template-binding fix) vs. operator not recorded (escalate).
- **Live refund E2E:** owner approval to run one real `refundWithCounterEntry` on an E2E order (status→RETURNED + counter cash entry; NF525 counter-entry is append-only). Spec ready behind `MR_REFUND_EXECUTE=1`.
- **F-CAISSE-LOGOUT:** is the mid-shift POS auto-logout real on a human session, or only an E2E-pacing artifact? (spot-check).
- **Severity reconciliation note:** v2 analyzer agents rated the receipt EN-locale + 12h-time items P2; the original plan rated the EN-receipt P1. Kept as P1 here on NF525 legal-document grounds (wrong legal term "VAT"→"TVA"). Owner may down-rank to P2 — no functional/money impact either way.
