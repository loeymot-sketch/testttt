# PROPOSAL 009 — Emoji fallback for missing image relies on French
keywords + cannot represent half the catalogue

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P3 (i18n + content fallback)
**Reasoning angle**: Client-impatient persona · UX consistency

---

## Observation

Lines 123 + 270–276:

```js
const DESSERT_EMOJI = {
  dessert: '🍰', gâteau: '🎂', glace: '🍦', boisson: '🥤',
  café: '☕', jus: '🧃', eau: '💧', coca: '🥤', frite: '🍟'
};

getEmoji(name) {
  const n = (name || '').toLowerCase();
  for (const [key, emoji] of Object.entries(DESSERT_EMOJI)) {
    if (n.includes(key)) return emoji;
  }
  return '🍽️';
}
```

Issues:

1. **Keyword matching is French-only** — won't match Arabic / English /
   German item names. An English-locale kiosk showing *"Coffee"* gets the
   default `🍽️` plate emoji instead of `☕`.
2. **No accent normalization** — `"Cafe"` (without `é`) misses the match
   for `"café"`. `"CAFÉ"` works because of `.toLowerCase()`, but `"Cafe"`
   silently falls through.
3. **`boisson` is `🥤` AND `coca` is `🥤`** — duplicate emoji, harmless
   but suggests the map was crafted ad-hoc.
4. The fallback `🍽️` is **not localized for RTL** — it renders fine but
   conveys no info. With no image, the customer sees a generic plate
   emoji with no signal about what the product actually is.
5. The map name `DESSERT_EMOJI` is misleading — it covers drinks, fries,
   etc. Code smell.
6. **Backend image gap** is the real bug — missing thumbs for upsell
   items should be a backend data quality issue, not a frontend
   workaround. Consider failing more loudly to expose missing-image
   gaps.

## Risks

- Multilingual customers see less-helpful screen.
- Hides backend content gaps (images never uploaded by restaurant
  manager).
- Inconsistent product representation degrades trust.

## Proposed fix

### Option A — Map by `category_name` or `item_category_id` instead of name

`SimpleItemResource` (`app/Http/Resources/SimpleItemResource.php:48`)
already returns `category_name`. The kiosk store has category metadata
in `globalState.lists.categories`. Map by category id (or category slug)
instead of name keywords:

```js
const CATEGORY_EMOJI = {
  3: '🥤',  // Boissons
  5: '🍰',  // Desserts
  7: '🍟',  // Frites
  // ...
};
getEmoji(item) {
  return CATEGORY_EMOJI[item.item_category_id] || '🍽️';
}
```

Category IDs are restaurant-scoped → must be config-driven, not hardcoded.
Better: add a `kiosk_fallback_emoji` column to `categories` table (V1
backlog) so each restaurant configures their own fallback per category.

### Option B — Generic SVG placeholder per category

Drop emoji entirely. Use a category-shaped SVG (drink, plate, sweet,
fries) loaded from a static path. Avoids font/emoji rendering
inconsistencies across kiosk hardware (some older Android boards render
emojis with the system colour-emoji font, others fall back to a
monochrome glyph).

### Option C — Quietly emit analytics for missing images

```js
if (!item.thumb && !item.image) {
  kioskAnalytics.track('upsell_item_missing_image', { item_id: item.id });
}
```

Existing whitelist would need to accept this event (backend mirror).
Lets ops dashboards flag *"Item #42 has no image, configure one to
boost upsell conversion"*. Provides a feedback loop to the restaurant
manager.

**Recommendation**: A + C. Use category-based fallback, surface the
underlying content gap.

## Scope estimate

- ~10 LOC in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- 1 new analytics event whitelist entry (frontend + backend mirror).
- Optional: migration to add `kiosk_fallback_emoji` to categories
  (V1.0.2+ backlog).

## Acceptance criteria

- English-locale kiosk with item "Coffee" displays `☕` (when no image).
- Items with `thumb` or `image` set never invoke the fallback path.
- Missing-image analytics events emit at runtime (and stay below 5% of
  upsell-shown impressions in healthy data).

## Rollback

Single-file revert. Map can be inlined or moved to a helper without
risk.
