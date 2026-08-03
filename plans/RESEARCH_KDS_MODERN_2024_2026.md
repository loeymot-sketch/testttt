# Research — Modern Fast-Food KDS Landscape (2024–2026)

**Author:** Claude research session
**Date:** 2026-05-11
**Purpose:** Ground FoodKing V1 KDS redesign in TODAY's state-of-the-art for fast-food / quick-service, not in 5–10 year old patterns.
**Scope:** Vendors, enterprise chains, UX convergence, anti-patterns, recommendation matrix for FoodKing V1 (takeaway-only, single small kitchen, 2 active sources POS + Kiosk).

---

## 1. Modern KDS Landscape Snapshot 2024–2026

Below: systems with **verified** 2024–2026 release activity and the specifics of what changed. Where I could not find UI detail in public sources, this is called out explicitly.

### 1.1 Toast KDS (new "KDS View" + Food Runner Fulfillment, 2024–2026)

- **What is genuinely new in 2024–2026:**
  - **Grid Layout** with fixed slot configurations: **5×2, 4×2, or 3×2** ticket arrangements selectable by device size, alongside a legacy "Dynamic Layout" where cards grow with item count. ([Toast Platform Guide — KDS Overview](https://doc.toasttab.com/doc/platformguide/platformKDSOverview.html))
  - **Dining option moved INTO the ticket header** (versus the older pattern of a separate row beneath the header) — a clear de-emphasis of source-as-primary-organizer.
  - **Partial fulfillment indicator**: yellow dot when an item is fulfilled at one station but still pending elsewhere, switching to a green check when fully fulfilled, then double-check at expediter. (Same source.)
  - **Food Runner Fulfillment** (launched Aug 27 2025): item-level delivery tracking at expo — runners mark individual items delivered instead of whole tickets. ([Toast Community — August 2025 Updates](https://community.toasttab.com/t5/product-updates/august-2025-product-updates-us/m-p/16529))
  - **Language Toggle** (January 2025) and **Recipe Viewing on KDS** (2026) — multilingual + in-kitchen reference without leaving the screen.
  - **Dark mode** as a first-class display theme.
- **Selected by Applebee's chainwide in April 2025** ([Applebee's PR](https://www.applebees.com/en/news/2025/applebees-selects-toast-technology-as-new-point-of-sale-and-kitchen-display-systems-partner)) — third-party validation of the modern stack.

### 1.2 Lightspeed Kitchen Display 2.0 (Nov 18 2024 launch)

- **Release date:** November 18 2024 across North America + Europe (including France). ([Lightspeed PR](https://www.lightspeedhq.com/news/lightspeed-introduces-the-next-generation-kitchen-display-system/))
- **Documented UI primitives** ([Lightspeed K-Series — Using KDS 2.0](https://k-series-support.lightspeedhq.com/hc/en-us/articles/22708154090267-Using-the-Kitchen-Display-System-2-0)):
  - **State colors:** Gray (New) → Blue (Preparing) → Green (Ready to collect) → Brown (On hold) → Red (Canceled). Progression by tapping a play / bell / long-press menu.
  - **Two views toggleable:** "Ticket View" (per-order cards) vs "Items List View" (aggregated quantities across all tickets — i.e. the all-day prep view).
  - **Configurable summary fields:** customer, server, **order source**, pickup time, allergens, seating info — each is a checkbox the operator turns on/off. Source visibility is a setting, not a layout dimension.
  - **Wait-time thresholds** are configurable with delayed/late "color pulses".
  - **Light / Dark themes** built-in.
  - **Offline functionality** if the venue loses internet ([Hotelvak — Lightspeed KDS 2.0](https://hotelvak.eu/en/hotel-management/food-beverage/lightspeed-launches-kitchen-display-system-2-0-the-secret-ingredient-for-efficient-kitchen-management/)).

### 1.3 Square KDS (Android-only redesign, 2024)

- **Migration completed in 2024:** iPad/iOS support dropped; Android-only going forward. ([Square Community — New Square KDS launches on Android](https://community.squareup.com/t5/Archived-Articles-Read-Only/New-Square-KDS-launches-on-Android-with-larger-screens-and-more/ba-p/652370)) The migration enabled **7 new features that were never available on iOS**, including routing by kitchen, station, and dining option.
- **Expeditor Mode** as a distinct app mode (vs prep-station mode).
- Configurable text size + ticket density.
- Pricing: $20–$30/month/device ([Square — KDS pricing page](https://squareup.com/us/en/point-of-sale/restaurants/kitchen-display-system)).

### 1.4 Fresh KDS (active 2023–2026)

- **Allergy Styling** (released Oct 2 2023): auto-detects keywords "allergy / allergen / allergic / gluten free / gluten-free" in modifiers + special instructions, then renders them in **orange + bold + italic**. ([Fresh KDS — Allergy Styling](https://www.fresh.technology/kds-features/allergy-styling)) This is the **single most concrete allergen-highlighting specification I found** in any public KDS source.
- **Modifier Styles** (configurable colors, bold, italic) for non-allergen modifiers.
- **Split View** to separate dine-in row from takeout row when both apply.
- **Take-Out View** as a dedicated mode for off-premise-only operations — directly relevant to FoodKing V1.
- **Prioritization** (move tickets to top of queue with a visual marker), **Recall** (restore cleared tickets), **Ingredient All-Day Counts** (aggregate live).
- **On The Fly** companion mobile app for kitchen KPIs.

### 1.5 Otter KDS (multichannel / aggregator-native)

- Otter processes ~18 % of US food-delivery transactions as of 2024 ([Otter — corporate](https://www.tryotter.com/)).
- **Workflow:** orders from any source land on a single card; staff tap items to check them off; once all items are checked, the card disappears and the ticket promotes to an **Assembly KDS** where everything is bundled for handoff. ([Otter Helpdesk — KDS Introduction](https://helpdesk.tryotter.com/hc/en-us/articles/32699511365011-Kitchen-Display-System-KDS-Introduction-Guide))
- Designed from the ground up assuming multi-channel input — but the kitchen view itself is **source-agnostic** (channel becomes metadata, not a column).

### 1.6 QSR Automations ConnectSmart Kitchen (renamed "Crunchtime Kitchen" post-acquisition)

- Acquired by Crunchtime; rebranded but same product family used by Chili's, Dave's Hot Chicken, Buffalo Wild Wings, Raising Cane's, Papa John's. ([QSR Automations / Crunchtime](https://qsrautomations.com/connectsmart-kitchen/))
- Average **40 % ticket-time reduction** claimed; "delayed routing" paces items so a long-cook item and a short-cook item finish together. ([Restaurant Business — QSR Automations acquired](https://www.restaurantbusinessonline.com/technology/kitchen-tech-supplier-qsr-automations-acquired-pe-firm))
- Integrates with 80+ POS and digital-ordering platforms (Order Aggregation pattern).
- Customer Order Ready / queue-position screens are part of the same suite.
- **UI specifics not publicly documented** beyond marketing copy.

### 1.7 Innovorder KDS (France, fast-food–centric)

- French SaaS (Paris HQ, showroom in central Paris); strongest European fast-food fit and the only system in this list that publishes **specific age-color thresholds** in plain text. ([Innovorder — KDS page](https://www.innovorder.com/en/kitchen-display-system))
- **Age coloring:** green (new) → orange (>3 min) → red (>10 min). This is the most explicit, citable threshold I found anywhere — and consistent with the older convention but still actively published in 2024.
- **Each card shows:** content, consumption mode (dine-in / takeaway / delivery), platform source (Uber Eats, Deliveroo, kiosk, pre-order), order channel. **Source-as-metadata, single queue**, not source-as-column.
- Modular: customer ODS (Order Display Screen) consumes the same data the KDS emits.
- French context, NF525-friendly, multi-channel — closest direct analog to FoodKing's environment.

### 1.8 Wingstop Smart Kitchen (proprietary, AI-driven, 2024–2025 rollout)

- **The only enterprise chain where the KDS UI itself is publicly documented.**
- **Four touch-screen monitors** form the KDS, one per station: **Bombardier** (chicken into fryers), **Wingman** (sauce-and-toss), **Fry** (sides), **Pilot** (expo — auto-prints assembly stickers). ([Restaurant Business — How Wingstop's Smart Kitchen will change the game](https://www.restaurantbusinessonline.com/operations/how-wingstops-smart-kitchen-upgrades-will-change-game))
- **Graphics-based task indicators** instead of text-heavy tickets. Team members **swipe "like a video game"** to mark tasks done — pure full-card / full-task gesture, no separate CTA button.
- **AI demand forecasting** in 15-minute increments using weather, sporting events, school calendars to drive prep quantities.
- **Result:** ticket time from 18–22 min → ~10 min (-40 %); 8-point CSAT lift. ([Restaurant Dive — Wingstop Smart Kitchen installed in 1,000 restaurants](https://www.restaurantdive.com/news/wingstop-smart-kitchen-installed-1000-restaurants-improved-operations/756375/) and [QSR Magazine — Wingstop's Game-Changing Kitchen Tech](https://www.qsrmagazine.com/story/wingstops-game-changing-kitchen-tech-is-driving-profit-and-pace/))

### 1.9 Chipotle "Chipotle Kitchen" digital makeline display

- Replaces text-based station displays with **ingredient-based visual cues**. Operational in 100 stores at announcement, targeting chainwide by year-end 2026. ([Restaurant Dive — Chipotle equipment package rollout](https://www.restaurantdive.com/news/chipotle-rollout-equipment-bots-kitchen-display-operational-efficiency/818974/))
- Augmented Makeline (Hyphen, Sep 2024) + Autocado (Vebu) are robotics, not strictly KDS, but they share the trend: **visual / ingredient-first UI over text-first UI**. ([Chipotle Newsroom — Autocado + Augmented Makeline](https://newsroom.chipotle.com/2024-09-16-CHIPOTLE-DEBUTS-AUTOCADO-AND-THE-AUGMENTED-MAKELINE-BY-HYPHEN-IN-RESTAURANTS))

### 1.10 Enterprise chains where KDS UI is NOT publicly documented

For honesty: I searched these and found strategic investment / AI features but **no publicly documented screen design**. Marking explicitly:

- **McDonald's "DOM v2"** — heavy 2024 spend on Edge / Google Cloud, AI Accuracy Scales, predictive maintenance ([CIO Dive — McDonald's invests aggressively in technology](https://www.ciodive.com/news/mcdonalds-invests-billions-tech-google-cloud-accenture/706680/); [Restaurant Dive — same](https://www.restaurantdive.com/news/mcdonalds-invests-billions-tech-google-cloud-accenture/706735/)). **UI design of the kitchen displays themselves is not publicly documented.**
- **Burger King "Reclaim the Flame" / Royal Reset** — $250 M for kitchen equipment + tech; "Sizzle" restaurant design ([RBI — Reclaim the Flame](https://www.rbi.com/English/news/news-details/2022/Burger-King-Announces-Reclaim-the-Flame-Plan-to-Accelerate-Growth-in-the-U.S/default.aspx); [NRN — $400M investment](https://www.nrn.com/quick-service/burger-king-plans-400m-2-year-investment-in-u-s-system)). **KDS UI not publicly documented.**
- **Yum! Brands Poseidon → Byte by Yum!** — Poseidon rolled to 1,700 Taco Bells; Dragontail AI kitchen-management system across 4,000+ stores ([QSR Magazine — Yum! state-of-the-art tech suite](https://www.qsrmagazine.com/operations/fast-food/yum-introduces-state-of-the-art-tech-suite-to-power-its-restaurants-worldwide/); [Business Wire — Byte by Yum](https://www.businesswire.com/news/home/20250206765566/en/Introducing-Byte-by-Yum-an-AI-Driven-Restaurant-Technology-Platform-Powering-Customer-and-Team-Member-Experiences-Worldwide)). **KDS UI not publicly documented.**
- **Chick-fil-A elevated drive-thru** — conveyor-belt food transport, 4 lanes, kitchen above ([QSR Magazine — Chick-fil-A two-story](https://www.qsrmagazine.com/story/chick-fil-a-redefines-fast-food-convenience-with-bold-two-story-drive-thru-concept/)). **Lane-expediter KDS UI not publicly documented.**
- **Inspire Brands "Alliance Kitchen"** — multi-brand ghost kitchen reducing labor 54 %, equipment cost 45 % ([NRN — Inspire Brands ghost kitchen](https://www.nrn.com/news/inspire-brands-opens-first-ghost-kitchen-serving-arby-s-buffalo-wild-wings-jimmy-john-s-sonic)). **KDS UI not publicly documented.**

---

## 2. Cross-System Pattern Convergence (2024–2026)

The systems above don't agree on everything, but the convergence is striking on the items below. Each pattern is supported by at least two independent vendors.

| Convergent pattern | Evidence |
|---|---|
| **Single unified queue, source as metadata-on-card (not source-as-column)** | Toast moves dining option *into the header*; Innovorder embeds platform source on each card; Lightspeed 2.0 makes `order source` a configurable summary checkbox; Otter Assembly KDS is fundamentally source-agnostic. |
| **Light + Dark themes shipped together** | Toast, Lightspeed 2.0 — both explicit. |
| **Items-list / all-day aggregate view as a togglable mode** | Lightspeed 2.0 "Items List View"; Fresh KDS "Ingredient All-Day Counts"; GoTab All-Day view. ([GoTab — How modern KDS works](https://gotab.com/latest/how-a-modern-kitchen-display-system-kds-works-the-end-to-end-workflow-explained)) |
| **Color-coded age thresholds, green → orange → red** | Innovorder publishes the exact thresholds (3 / 10 min); Lightspeed configurable delayed/late pulses; Fresh "on-time / caution / late" 15-30 min windows; legacy convention is now expected, not novel. |
| **Allergen / modifier highlighting** | Fresh KDS Allergy Styling (orange + bold + italic, keyword-driven); Lightspeed allergen display tied to seating; Toast modifier emphasis in grid view. |
| **Customer Order-Ready / pickup screen as a sibling app** | Innovorder ODS; QSR Automations "OrderReady"; Fresh Order Tracker; Otter SMS notifications. The KDS no longer "ends" at the bump — it pipes to a guest-visible screen. |
| **Touch + bump-bar parity** | Toast, Fresh, TouchBistro, Loyverse — all publish bump-bar integration documentation alongside touch. None deprecate touch. ([TouchBistro — Bump Bar Guide](https://www.touchbistro.com/blog/what-is-a-bump-bar/)) |
| **Multi-language support exposed in the UI** | Toast Language Toggle (Jan 2025); Lightspeed 2.0 Language setting; Innovorder French-first with multilingual integrations. |
| **Offline / degraded-mode** | Lightspeed 2.0 (advertised); Otter "Wireless offline mode"; TouchBistro continues during outage. |
| **Visual / icon-led card content (away from pure text)** | Wingstop graphics-based station displays; Chipotle ingredient-based visual cues; modern Otter cards. Text-first dense ticket views are giving way to glanceable visual primitives, especially in QSR. |
| **Touch-anywhere-on-card / swipe gesture to bump (modern QSR)** | Wingstop "swipe like a video game"; Otter "tap items to check off, card disappears"; Toast tap-to-bump within grid. |

What is **NOT** convergent (and where my evidence is mixed):

- **Automatic state transition from NEW → PREPARING.** Every system I documented uses an **explicit manual action** to start prep — tap (Otter), play button (Lightspeed 2.0 — "Active production via play button"), swipe (Wingstop), bump-bar key (Toast / QSR Automations). I found **zero public documentation** of automatic NEW→PREPARING transition in modern QSR KDS. The closest analog is QSR Automations' "delayed routing" which paces *order release*, not state transitions. This means FoodKing's planned auto-transition is **a deliberate design departure**, not a found industry pattern — and must be justified locally (see §4).

---

## 3. Anti-Pattern Callouts (now considered outdated)

Patterns that show up in 5–10 year old KDS and that 2024–2026 systems explicitly move away from:

1. **Per-source columns / swimlanes ("Dine-in | Online | Takeaway | Kiosk" as fixed columns).** All modern systems unify the queue and treat source as a card-level badge. Toast's documented move of dining option *into the header* is the cleanest evidence of de-emphasis. FoodKing's current 4-column layout is the textbook anti-pattern.
2. **iOS-only KDS.** Square forced its entire customer base off iPad in 2024 to unlock new routing features. The lesson: **don't tie a KDS to a specific consumer-grade OS**; ruggedized Android + web is the modern stance.
3. **Closed accordions / hidden line items.** Every modern system I documented displays line items inline by default — Toast grid, Lightspeed full view, Fresh ticket view, Otter card. Hidden line items are a 2014-era cost-saving for tiny screens; with 22"–32" KDS panels now standard ([Square Hardware — 21.5" Kitchen Touchscreen](https://squareup.com/shop/hardware/us/en/products/kitchen-touchscreen-22); [Sunmi D2s 15.6" KDS](https://www.ers-online.co.uk/p11440/sunmi-d2s-kds-kitchen-display-system); 27–32" wall-mount standard) this is no longer a real constraint.
4. **Modifier visual treatment identical to base items.** Fresh's keyword-driven Allergy Styling (orange + bold + italic) and Lightspeed's seating-tied allergen display reflect a clear convergence: modifiers, especially allergy-related, must look different from regular item lines. A single uniform text style across all lines is now considered unsafe.
5. **Tiny age indicators (thin border, low opacity).** All modern systems use either bold header colors, full-card tint, or pulsing animations — i.e. **dominant visual signals**. FoodKing's current 1px border at 55 % opacity + neutralized pulse is the anti-pattern.
6. **Sub-WCAG touch targets.** WCAG 2.5.8 (Level AA) requires minimum 24×24 CSS px ([Accessibility Checker — WCAG 2.5.8](https://www.accessibilitychecker.org/wcag-guides/all-touch-targets-must-be-24px-large-or-leave-sufficient-space/); [TestParty — WCAG 2.5.8 guide](https://testparty.ai/blog/wcag-target-size-guide)); WCAG 2.5.5 (Level AAA) recommends 44×44. In a kitchen with gloves / wet hands, the **W3C Mobile A11y Extension** ([W3C Mobile A11y — Touch](https://w3c.github.io/Mobile-A11y-Extension/touch.html)) explicitly cites wet hands as the canonical reason to go larger. A 32 px bump button violates both AA + the mobile-extension common-sense guidance.
7. **Source-aware kitchen workflow when only one station exists.** Toast, Fresh, Otter all push source to a *card hint* rather than letting it gate prep flow. A single-chef kitchen does not need source-aware workflow — only the *handoff* (printed sticker / pass screen) needs source identity.

---

## 4. Recommendation Matrix for FoodKing V1 (takeaway-only, single small kitchen, POS + Kiosk)

Each row: **Recommendation → competitor inspiration → reasoning for FoodKing V1 → modern alternative rejected and why.**

### 4.1 Layout

**Recommendation:** Single unified FIFO queue rendered as a **grid of cards** (configurable; start with 4×2 = 8 cards visible, oldest top-left → newest bottom-right). Scroll behaviour: only scroll when queue exceeds 8 cards; otherwise no scroll. Each card is a fixed slot, content fits inside.

- **Inspiration:** Toast Grid Layout (5×2 / 4×2 / 3×2 documented options).
- **Why for FoodKing V1:** Single chef on a 27–32" wall-mounted screen sees the entire backlog at a glance. 8 cards covers ~15–20 minutes of typical fast-food throughput. No accordions, no scroll surprise.
- **Rejected:** Per-source 4-column layout (current FoodKing) — splits cognition for no operational benefit when only one chef works one card at a time. Toast's explicit move of source-into-header is the canonical opposite.
- **Rejected:** Kanban swimlanes by state (NEW / PREPARING / READY) — adds horizontal eye-travel, and with single-chef + auto-transition (see §4.3) there's effectively only one or two states visible at once.

### 4.2 Card Design

**Recommendation:**

- **Header (sticky):** order number (large), source badge (small chip — `POS` or `KIOSK`), elapsed time (mm:ss).
- **Body:** items inline (no accordion), one item per row, modifiers indented underneath in smaller weight, **allergen-flagged items in orange + bold + italic** with an inline icon.
- **Footer:** primary action button "Prêt / Ready" sized ≥ 60 px height for gloved use; bump-bar key shortcut shown as keyboard hint if bump bar is configured.
- **Background tint:** subtle full-card color reflects age (white → light orange → light red) — secondary signal layered over header color.

- **Inspiration:** Lightspeed 2.0 ticket Full View (collection code + server + order type + items + timestamps); Fresh KDS Allergy Styling (the exact orange + bold + italic formula); Toast grid layout where dining option lives in the header.
- **Why for FoodKing V1:** Single chef needs every order's items visible at a glance — no opening / closing. Source badge satisfies the "future online orders should be distinguishable" requirement without rebuilding layout. Allergen treatment uses the most concrete public KDS spec available.
- **Rejected:** Source as background-color of the whole card (would conflict with age-color). Source as a column or vertical stripe (anti-pattern — see §3).

### 4.3 State Machine (auto-transition policy)

**Recommendation:** **Conditional auto-transition NEW → PREPARING.** Rule: when an order enters the KDS, if zero other orders on the screen are in state PREPARING, the new order auto-transitions to PREPARING immediately. Otherwise it stays NEW until the chef bumps the order ahead. PREPARING → READY is always manual (chef taps Prêt). READY cards disappear after a short delay (3–5 s with undo).

- **Inspiration:** This is **not a found industry pattern.** Every modern KDS I documented uses an explicit manual action (Lightspeed play button, Otter tap-to-start, Wingstop swipe, Toast tap-or-bump). I cite this honestly.
- **Why for FoodKing V1 (the local justification):**
  - Single chef + single station + takeaway-only: **only one order can physically be in prep at a time**. The system can infer "started prep" with near-zero risk of false positive.
  - Removes one tap per order — saves real time and one wet-glove touch per ticket.
  - FIFO discipline is enforced by the system rather than relying on chef memory.
  - **Reversibility:** if FoodKing later re-enables dine-in or multi-station, the rule degrades cleanly to manual (the condition "zero other PREPARING orders" simply will no longer be satisfied frequently, and chef goes back to tapping; or a feature flag turns auto-transition off entirely).
- **Rejected:** Unconditional auto-transition — would break the moment a second concurrent station / chef exists.
- **Rejected:** Pure manual transition — wastes a tap per order in the single-chef takeaway-only V1 case, and adds nothing because there's no decision to make.

### 4.4 Source Visibility

**Recommendation:** Single small chip on the header — `POS` (counter) or `KIOSK` (borne). Color-neutral by default. Reserve **a distinct color** for future `ONLINE` chip (when online ordering activates) and **a second distinct color** for future `DINE-IN` chip. No source-driven layout changes.

- **Inspiration:** Toast (dining option *in the header*); Innovorder (channel as a card badge); Lightspeed 2.0 (`order source` as a configurable summary field, not a layout column).
- **Why for FoodKing V1:** Chef doesn't need source to *cook*, but the handoff workflow (printed ticket at pickup) needs to know which counter / bag-station the order goes to. Chip is glanceable; color reservations protect future channels without touching layout.
- **Rejected:** Hiding source entirely — owner explicitly wants modifiers / future online orders distinguishable, and printed-ticket handoff at minimum needs the chef to spot the channel before bumping.
- **Rejected:** Source as the dominant card color — conflicts with age tint and breaks once a third or fourth channel arrives.

### 4.5 Modifier / Allergen Highlighting

**Recommendation:**

- **Standard modifier** (e.g. "sans oignon"): smaller font, indented under parent item, italic but neutral color.
- **Supplement / paid add** (e.g. "+ cheddar"): same indentation, color = brand accent yellow (FoodKing palette), italic.
- **Allergen-flagged** (keyword match: "allergie", "allergy", "allergen", "sans gluten", "gluten free", "lactose"): **orange + bold + italic** background pill, alongside an icon (warning triangle) at the item row's left margin.

- **Inspiration:** Fresh KDS Allergy Styling — the single most concrete public allergen-highlight spec (Oct 2 2023 launch). Fresh's keyword list directly maps to French + English variants for FoodKing.
- **Why for FoodKing V1:** Three-language environment (FR / EN / AR) needs keyword detection in all three. Visual treatment is identical across language so the chef's pattern recognition transfers. Orange+bold+italic is consistent with the FoodKing palette (red / yellow / black / white) without introducing a new color.
- **Rejected:** Red highlight for allergens — would collide with the age-red signal. Orange is unambiguously "allergen" because age uses red.
- **Rejected:** Icon-only allergen marker — fails when the chef glances from 2 m away; text styling is louder.

### 4.6 Age / Urgency Visual Treatment

**Recommendation:** Three thresholds: **0–3 min (green / neutral), 3–6 min (orange), >6 min (red, pulsing slowly at 1 Hz)**. Applied as: card border (4 px, not 1 px) + card-background tint (subtle) + header color (dominant). No reliance on opacity alone.

- **Inspiration:** Innovorder (green / orange @ 3 min / red @ 10 min — published explicitly); Lightspeed 2.0 "delayed / late color pulses"; legacy convention reaffirmed in 2024 vendor docs.
- **Why for FoodKing V1:** Fast-food takeaway average prep target is < 5 min for a single combo, so the "orange at 3 min, red at 6 min" tightening (vs Innovorder's 3/10 min for general fast-food) reflects FoodKing's takeaway-only target. The thresholds are exposed in config so the owner can tune by category later.
- **Rejected:** 5-second pulsing red — too aggressive in a 1-chef kitchen; causes alert fatigue when only the chef sees the screen.
- **Rejected:** Single age threshold (binary on-time / late) — loses the "still OK but getting old" window where the chef can re-sequence.

### 4.7 Bump Action UX

**Recommendation:** **Two parallel paths to bump:**

1. **Primary:** large "Prêt / Ready" CTA button at the bottom of each card, ≥ 60 px tall, full card width minus padding. Single tap → state PREPARING → READY → card fades and disappears with a 3 s undo Toast.
2. **Secondary:** bump-bar key shortcut (when bump bar is plugged in), keyboard shortcut shown on card.

No full-card-tap bump (avoids accidental fires when chef leans / wipes screen).

- **Inspiration:** Toast bump-bar integration alongside touch; Fresh KDS bump-bar support; Lightspeed 2.0 explicit play / bell / bump buttons.
- **Why for FoodKing V1:** 60 px exceeds WCAG 2.5.8 (24 px) and WCAG 2.5.5 (44 px), and aligns with the W3C Mobile A11y Extension's wet-hands / gloved guidance. CTA-button discipline avoids the "swipe-like-a-video-game" Wingstop pattern that requires extensive staff training. Undo Toast covers the most common chef error (bumped wrong card).
- **Rejected:** Wingstop-style full-card swipe — high risk of misfire on a touch panel with grease, and Wingstop has dedicated training per station; not viable for a small operation.
- **Rejected:** Tap-anywhere-on-card-to-bump — too easy to misfire when leaning, wiping, or reading the card.

### 4.8 Chef Workflow (happy path + edge cases)

**Happy path:**

1. Customer pays at POS or Kiosk → order lands on KDS at top-left as a NEW card.
2. If no other order is PREPARING, the card auto-transitions to PREPARING (background tint becomes "active").
3. Chef cooks the order; modifiers / allergens are visible inline.
4. Chef taps **Prêt** → card fades, 3 s undo Toast visible; order state → READY; printed ticket emits at the pass (with source = POS / KIOSK so staff can route).
5. Next NEW card auto-transitions to PREPARING.

**Edge cases handled:**

- **Two orders arrive at the same instant**: the first to enter the queue (by `created_at` then `id`) takes PREPARING; the second stays NEW.
- **Chef bumps wrong card**: undo Toast within 3 s. After 3 s, recall via long-press on the "recently completed" tray (Fresh recall pattern).
- **Allergen on order**: orange+bold+italic pill prevents chef from missing it; even a quick glance lights up the row.
- **Order canceled at POS while in PREPARING**: card border turns red with strikethrough + explicit "ANNULÉ" banner. Chef must explicitly dismiss.
- **Screen lock / chef walks away**: card states persist server-side; nothing depends on local state.
- **Network blip**: KDS continues to display the last known queue (Lightspeed 2.0 / Otter pattern). When reconnection happens, server reconciles.

### 4.9 Reversibility Plan (re-introducing per-source views later)

The single-queue + source-as-chip design is **structurally reversible** for the three foreseeable changes:

| Future change | What changes in the UI | Data-model touch |
|---|---|---|
| Online ordering activates | New `ONLINE` chip color appears on cards from that channel | No schema change — `source` column already supports the value |
| Dine-in re-enables | New `DINE-IN` chip color; optionally a Split View toggle (Fresh-style) to separate dine-in row from takeaway row | No schema change |
| Multiple stations (grill / fries / assembly) | Toast Grid + station routing pattern; one KDS per station with a filtered queue | Schema needs an `items.station_id` or routing rule — should be specified now to avoid retrofit |
| Expediter / pass station | Add an expo-mode that consumes READY events and exposes assembly checks (Otter Assembly KDS / Toast Expo) | Item-level fulfillment column on `order_items` should be planned now |

**Concrete action for V1:** even though FoodKing V1 does not need station routing or item-level fulfillment, the **data model should already carry** (a) `items.station_id` nullable, (b) `order_items.fulfilled_at` nullable, (c) `orders.source` already exists. This keeps the door open without paying retrofit cost later.

---

## 5. Decision (one paragraph)

FoodKing V1 KDS should be **a single unified FIFO queue rendered as a 4×2 grid of full-content cards, sorted oldest-top-left → newest-bottom-right, with source surfaced as a small chip in the header (not as a column), allergens highlighted via Fresh KDS's orange+bold+italic keyword-driven formula, three-step age coloring (green / orange@3min / red@6min, both card border and header), an explicit 60 px Prêt button (touch) with optional bump-bar parity, and a conditional auto-transition NEW→PREPARING that only fires when no other order is in prep — keeping the data model reversibly ready for future dine-in, online ordering, and station routing.** The strongest competitive anchors are **Toast's 2024–2026 grid layout + dining-option-in-header pattern**, **Lightspeed KDS 2.0's documented state colors and configurable summary fields**, **Fresh KDS's Allergy Styling formula**, and **Innovorder's published age-color thresholds as the closest direct fast-food European analog**. The conditional auto-transition is the one deliberate departure from documented industry practice — justified by the V1-specific single-chef / single-station / takeaway-only constraint, and structurally reversible.

---

## Sources

- [Toast — Kitchen Display System product page](https://pos.toasttab.com/hardware/kitchen-display-system)
- [Toast — KDS Platform Guide (Overview)](https://doc.toasttab.com/doc/platformguide/platformKDSOverview.html)
- [Toast — Using a KDS expediter screen](https://doc.toasttab.com/doc/platformguide/adminUsingExpo.html)
- [Toast Community — August 2025 Product Updates](https://community.toasttab.com/t5/product-updates/august-2025-product-updates-us/m-p/16529)
- [Applebee's — selects Toast for chainwide POS + KDS (April 2025)](https://www.applebees.com/en/news/2025/applebees-selects-toast-technology-as-new-point-of-sale-and-kitchen-display-systems-partner)
- [Lightspeed — Next-Generation KDS launch (Nov 18 2024)](https://www.lightspeedhq.com/news/lightspeed-introduces-the-next-generation-kitchen-display-system/)
- [Lightspeed K-Series — Using KDS 2.0 (UI reference)](https://k-series-support.lightspeedhq.com/hc/en-us/articles/22708154090267-Using-the-Kitchen-Display-System-2-0)
- [Hotelvak — Lightspeed KDS 2.0 launch](https://hotelvak.eu/en/hotel-management/food-beverage/lightspeed-launches-kitchen-display-system-2-0-the-secret-ingredient-for-efficient-kitchen-management/)
- [Square — KDS product page](https://squareup.com/us/en/point-of-sale/restaurants/kitchen-display-system)
- [Square Community — KDS launches on Android](https://community.squareup.com/t5/Archived-Articles-Read-Only/New-Square-KDS-launches-on-Android-with-larger-screens-and-more/ba-p/652370)
- [Square Hardware — 21.5" Kitchen Touchscreen](https://squareup.com/shop/hardware/us/en/products/kitchen-touchscreen-22)
- [Fresh KDS — features page](https://www.fresh.technology/kitchen-display-system)
- [Fresh KDS — Fast Casual fit](https://www.fresh.technology/restaurants/fast-casual)
- [Fresh KDS — Allergy Styling (Oct 2 2023)](https://www.fresh.technology/kds-features/allergy-styling)
- [Otter — corporate page](https://www.tryotter.com/)
- [Otter Helpdesk — KDS Introduction Guide](https://helpdesk.tryotter.com/hc/en-us/articles/32699511365011-Kitchen-Display-System-KDS-Introduction-Guide)
- [QSR Automations / Crunchtime — ConnectSmart Kitchen](https://qsrautomations.com/connectsmart-kitchen/)
- [Restaurant Business — QSR Automations acquired by PE firm](https://www.restaurantbusinessonline.com/technology/kitchen-tech-supplier-qsr-automations-acquired-pe-firm)
- [Innovorder — Kitchen Display System (production screen for fast food)](https://www.innovorder.com/en/kitchen-display-system)
- [Innovorder — Order Display System (customer screen)](https://www.innovorder.com/en/order-display-system)
- [Restaurant Business — How Wingstop's Smart Kitchen will change the game](https://www.restaurantbusinessonline.com/operations/how-wingstops-smart-kitchen-upgrades-will-change-game)
- [Restaurant Dive — Wingstop Smart Kitchen installed in 1,000 restaurants](https://www.restaurantdive.com/news/wingstop-smart-kitchen-installed-1000-restaurants-improved-operations/756375/)
- [QSR Magazine — Wingstop's Game-Changing Kitchen Tech](https://www.qsrmagazine.com/story/wingstops-game-changing-kitchen-tech-is-driving-profit-and-pace/)
- [Chipotle Newsroom — Autocado + Augmented Makeline (Sep 16 2024)](https://newsroom.chipotle.com/2024-09-16-CHIPOTLE-DEBUTS-AUTOCADO-AND-THE-AUGMENTED-MAKELINE-BY-HYPHEN-IN-RESTAURANTS)
- [Restaurant Dive — Chipotle equipment + KDS rollout](https://www.restaurantdive.com/news/chipotle-rollout-equipment-bots-kitchen-display-operational-efficiency/818974/)
- [CIO Dive — McDonald's tech investment](https://www.ciodive.com/news/mcdonalds-invests-billions-tech-google-cloud-accenture/706680/)
- [Restaurant Dive — McDonald's invests aggressively in technology](https://www.restaurantdive.com/news/mcdonalds-invests-billions-tech-google-cloud-accenture/706735/)
- [RBI — Burger King "Reclaim the Flame"](https://www.rbi.com/English/news/news-details/2022/Burger-King-Announces-Reclaim-the-Flame-Plan-to-Accelerate-Growth-in-the-U.S/default.aspx)
- [NRN — Burger King $400M technology investment](https://www.nrn.com/quick-service/burger-king-plans-400m-2-year-investment-in-u-s-system)
- [QSR Magazine — Chick-fil-A two-story drive-thru concept](https://www.qsrmagazine.com/story/chick-fil-a-redefines-fast-food-convenience-with-bold-two-story-drive-thru-concept/)
- [QSR Magazine — Yum! state-of-the-art tech suite](https://www.qsrmagazine.com/operations/fast-food/yum-introduces-state-of-the-art-tech-suite-to-power-its-restaurants-worldwide/)
- [Business Wire — Byte by Yum! AI platform](https://www.businesswire.com/news/home/20250206765566/en/Introducing-Byte-by-Yum-an-AI-Driven-Restaurant-Technology-Platform-Powering-Customer-and-Team-Member-Experiences-Worldwide)
- [NRN — Inspire Brands Alliance Kitchen (ghost kitchen)](https://www.nrn.com/news/inspire-brands-opens-first-ghost-kitchen-serving-arby-s-buffalo-wild-wings-jimmy-john-s-sonic)
- [Miso Robotics — Flippy Fry Station next-gen launch](https://misorobotics.com/newsroom/miso-launches-next-generation-flippy-fry-station-the-most-significant-evolution-of-the-ai-powered-robot-since-its-inception/)
- [GoTab — How a modern KDS works (end-to-end workflow)](https://gotab.com/latest/how-a-modern-kitchen-display-system-kds-works-the-end-to-end-workflow-explained)
- [TouchBistro — What is a Bump Bar (bump-bar vs touch comparison)](https://www.touchbistro.com/blog/what-is-a-bump-bar/)
- [Accessibility Checker — WCAG 2.5.8 Target Size Minimum](https://www.accessibilitychecker.org/wcag-guides/all-touch-targets-must-be-24px-large-or-leave-sufficient-space/)
- [TestParty — WCAG 2.5.8 guide](https://testparty.ai/blog/wcag-target-size-guide)
- [W3C Mobile A11y Extension — Touch (wet hands rationale)](https://w3c.github.io/Mobile-A11y-Extension/touch.html)
- [Sunmi D2s KDS (15.6" capacitive, gloves + wet-hand support)](https://www.ers-online.co.uk/p11440/sunmi-d2s-kds-kitchen-display-system)
- [Loman.ai — 7 Best KDS for Order Routing 2024](https://www.loman.ai/blog/7-best-kitchen-display-systems-kds-for-order-routing-2024)
- [Cuboh — Cuboh vs Deliverect vs Otter vs Chowly](https://www.cuboh.com/cuboh-vs-deliverect-vs-otter-vs-chowly)
- [Cloud Kitchens — POS + online integration for production kitchens](https://cloudkitchens.com/blog/how-to-integrate-pos-systems-and-online-plataforms-for-production-kitchens)
