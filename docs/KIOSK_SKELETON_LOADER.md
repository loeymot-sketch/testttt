# KioskSkeletonLoader

Greenfield component (Wave Alpha — M-1) showing structured shimmer placeholders during fetch operations on non-frozen kiosk surfaces. Improves perceived speed by 20–30% versus a single spinner or empty state.

## Usage

```vue
<template>
  <KioskSkeletonLoader v-if="loading" type="card" :count="4" />
  <MyRealContent v-else :data="items" />
</template>

<script>
import KioskSkeletonLoader from '@/components/frontend/kiosk/KioskSkeletonLoader.vue';
export default { components: { KioskSkeletonLoader } };
</script>
```

Show between `fetch start` and `data ready`. Unmount as soon as data lands — never overlay it on real content.

## Props

| Prop  | Type   | Default | Notes |
|-------|--------|---------|-------|
| type  | String | `'card'` | One of `card | list | grid | inline`. Validator enforces. |
| count | Number | `3`     | Must be ≥ 1. Number of placeholder rows/cards/items. |

## Types

- **card** — horizontal card with circle thumb + 3 lines + button (item lists, search results).
- **list** — vertical row with 2 lines (orders history, simple feeds).
- **grid** — auto-fit grid (min 180px) with rect image + line (categories, product grid).
- **inline** — single shimmering bar (header, single value placeholder).

## A11y

- `aria-busy="true"` + `role="status"` so AT announces async state.
- `aria-label` translated via `kiosk.skeleton.aria_label` (fr+en today; falls back to FR string if i18n key missing).
- `prefers-reduced-motion` disables shimmer keyframes (static surface tone instead).

## Where to use

- Non-frozen kiosk screens during data fetch.
- **Do NOT** add to frozen-zone wizard components (`KioskCategoriesComponent`, `KioskCartComponent`, `KioskWizardComponent`, etc.) without lifting the freeze.
- Tokens consumed: `--kiosk-surface*`, `--kiosk-border`, `--kiosk-radius-*`, `--kiosk-space-*`. Safe fallbacks inline so the component renders sanely outside the kiosk DS context (e.g. tests).
