# Impl D — Mobile Fictional Products Purge — Evidence Bundle

**Date** : 2026-05-18
**Implementer** : Impl D (GOAL Round 2)
**Scope** : P0-MOB-01..05 from `99_SYNTHESIS_MASTER.md`
**Files touched** : `mobile/data/orders.js` + `mobile/data/loyalty.js` + new `tests/js/mobileDataAntiFiction.spec.js`
**Branch** : `v1-0-1-hardening-2026-05-17`

---

## 1. Canonical item_ids used in healed `mobile/data/orders.js`

Every `items[].item_id` in the rewritten mock now resolves to a real entry in `mobile/data/menu.js` :

| item_id | Canonical name (menu.js) | Used in order |
|---|---|---|
| 101  | Sandwich Cayenne             | C-1212 |
| 102  | Big Cayenne                  | C-1234 |
| 202  | Galette Cayenne              | C-1190 |
| 301  | Sandwich Classique           | C-1142 |
| 302  | Big Classique                | C-1100 |
| 401  | Chicken Burger               | C-1208 |
| 502  | Tacos L                      | C-1234 |
| 602  | Bowl Frites Poulet curry     | C-1234 |
| 608  | Bowl Riz Poulet crispy       | C-1190 |
| 701  | Petite Frites                | C-1208 |
| 702  | Grande Frites                | C-1212 |
| 902  | Tarte Daim                   | C-1100 |
| 1001 | Coca-Cola 33cl               | C-1234, C-1212, C-1142 |
| 1002 | Coca-Cola Zero 33cl          | C-1190 |

**Coverage** : 14 canonical SKUs across 6 orders (1 active + 5 history) — same volume as before, varied behaviours preserved (cash + card + pending + delivered, multi-item baskets, bols composer with gratiné supplement).

## 2. Canonical refs used in healed `mobile/data/loyalty.js`

REWARDS payload corrections :

| Reward id | Before | After | Type |
|---|---|---|---|
| R1 | item_id=7001 (fictional) + variation_id=70011 | item_id=701 (Petite Frites) | free_item |
| R5 | category_id=2 (Galette) — label said "Burger" | category_id=4 (Burgers) | free_item |
| R6 | item_id=2001 (Box Solo, fictional) | item_id=501 (Tacos M) | free_item |
| R7 | item_id=2003 (Box Familiale, fictional) | item_id=102 (Big Cayenne) | percent_discount |

DEFAULT_HISTORY description corrections :

| Entry id | Before | After |
|---|---|---|
| 1001 | "Box Nashville"                 | "Big Cayenne · Tacos L · Bowl Frites" |
| 1003 | "Burger gratuit (Box Nashville −50%)" | "Burger gratuit (Big Chicken)" |
| 1004 | "Wrap Poulet · Bowl"            | "Galette Cayenne · Bowl Riz" |
| 1005 | "Le Gourmet"                    | "Sandwich Classique" |
| 1006 | "Box Nashville"                 | "Sandwich Classique · Coca-Cola" |

Entries 1002 / 1007 kept ("Smash Cheese" → "Sandwich Cayenne · Grande Frites" for 1002; 1007 was already canonical welcome bonus).

## 3. Diff stat

```
 mobile/data/loyalty.js | 28 ++++++++++++-------
 mobile/data/orders.js  | 73 +++++++++++++++++++++++++++++---------------------
 2 files changed, 60 insertions(+), 41 deletions(-)
+ new file  tests/js/mobileDataAntiFiction.spec.js  (140 lines)
```

Frozen-zone diff = 0 (mobile is fully standalone, not in CLAUDE.md §7 frozen-zone list).

## 4. Sentinel test results

`npx vitest run tests/js/mobileDataAntiFiction.spec.js`

```
 RUN  v1.6.0 /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt

 ✓ tests/js/mobileDataAntiFiction.spec.js  (6 tests) 5ms

 Test Files  1 passed (1)
      Tests  6 passed (6)
   Start at  02:17:33
   Duration  351ms
```

Test coverage :
1. `orders.js → every items[].item_id exists in menu.js` (canonical SSOT enforcement)
2. `loyalty.js → every REWARDS[].payload.item_id exists in menu.js`
3. `loyalty.js → every REWARDS[].payload.category_id exists in menu.js`
4. `loyalty.js → reward 5 targets the Burgers category` (cat slug assertion = anti-drift)
5. `orders.js → every total equals Σ line_total` (arithmetic parity post-rewrite)
6. `forbidden pre-MENU-RESET strings absent from healed files` (lexical anti-fiction)

## 5. Quick smoke parity (Node)

```
Menu : 41 items / 11 cats
Orders : 1 active / 5 history
Loyalty : 8 rewards / 7 history entries
Orders item_ids used : 101, 102, 202, 301, 302, 401, 502, 602, 608, 701, 702, 902, 1001, 1002
Rewards payloads : R1 item_id=701  R5 category_id=4  R6 item_id=501  R7 item_id=102
C-1234: total=29.80 Σ=29.80 OK
C-1212: total=13.00 Σ=13.00 OK
C-1208: total=16.30 Σ=16.30 OK
C-1190: total=17.40 Σ=17.40 OK
C-1142: total=8.50 Σ=8.50 OK
C-1100: total=12.80 Σ=12.80 OK
```

## 6. Out-of-scope items (deferred — different Impl owners)

- `mobile/screens-modals.jsx:204-206` fallback array (P1 per Agent 8) — still references "Box Nashville / Bowl Gratiné / Frite XXL". Per Round 2 dispatch table Impl D scope is strictly `mobile/data/orders.js + loyalty.js`. The fallback only renders when `orderId` is not found in `LC.orders` — given our healed `LC.orders.findById` exposes every demo order, the fallback path is unreachable in normal demo flow. Safe to defer to a follow-up Impl with screens scope.
- `mobile/data/dev-helpers.js:212` `seedHistory().itemNames` — dev-only seeding harness (gated by `window.LC.isDev`), not surfaced in user UX. Out of scope.
- `mobile/screens-main.jsx:109` `findItem('tacos-xxl')` (P2) — out of scope, screens file.

## 7. Commit SHA

`c138b32dd` — `fix(oss-v1-prep): chime TV-wall fallback + PRÊT WCAG AA contrast heal`

Note : Impl D deliverables (mobile/data/orders.js + loyalty.js + tests/js/
mobileDataAntiFiction.spec.js + this evidence file) were swept into the parallel
Impl C commit `c138b32dd` due to concurrent staging while Round 2 agents ran in
parallel. The full Impl D scope landed atomically in that commit alongside Impl
B + C deliverables — see the commit's diffstat for the 5 Impl D files (lines
matching `mobile/data/`, `tests/js/mobileDataAntiFiction.spec.js`, and the
round-2 evidence bundle). No separate Impl D commit was created; this docs-only
follow-up backfill records the SHA for traceability.

## 8. Acceptance gates

- [x] Every `item_id` in orders.js + loyalty.js exists in menu.js (sentinel green)
- [x] Reward 5 category_id resolves to Burgers (not Galette)
- [x] Order totals = Σ line_total post-rewrite (arithmetic parity)
- [x] Order count + history shape unchanged (1 active + 5 history)
- [x] Reward count unchanged (8 rewards)
- [x] Vitest sentinel test passing 6/6
- [x] Frozen-zone diff = 0
- [x] Mobile remains STANDALONE (no API wireup added)

---

**END Impl D Round 2 evidence**
