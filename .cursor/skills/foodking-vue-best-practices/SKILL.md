---
name: foodking-vue-best-practices
description: Vue 3 + Laravel Mix conventions for FoodKing admin/kiosk/POS surfaces. Use when editing resources/js components, stores, or POS/kiosk UI; complements frontend-design and web-design-guidelines.
---

# FoodKing Vue – Best Practices

## Stack facts

- **Vue 3** (`package.json`), build via **Laravel Mix**; unit tests often **Vitest** + `@vue/test-utils`.
- Backend is **Laravel**; treat API responses and domain enums as authoritative.

## Invariants (non-negotiable)

- **Pricing SSOT is backend** — display formatted money from the server; do not invent totals, discounts, or tax logic in the browser beyond presentation of API values.
- **`OrderStatus` (and related enums)** — use shared constants / API shapes; avoid hardcoded status strings that can drift from PHP.
- **`branch_id` isolation** — any query, form, or store logic must respect tenant/branch scoping as the rest of the file already does; never “convenience fetch” across branches.
- **Order flows** — keep symmetry with backend expectations (`OrderService` / `FrontendOrderService` patterns documented in project docs); dispatch and events stay server-driven.
- **Frozen zones** — if the file you touch is frozen per gates/docs, read-only unless a cleared gate exists.

## Vue patterns

- **Match the file’s existing style**: Options API vs `<script setup>` — extend the dominant pattern in that directory rather than mixing styles in one feature without reason.
- **Props down, events up** — prefer explicit emits and typed/validated props for admin components that others reuse.
- **Reactivity** — avoid deep watching large graphs; prefer computed + targeted updates; watch out for stale object references when normalizing API payloads.
- **Keys and lists** — stable `:key` for anything tied to order lines, modifiers, or fiscal rows; avoid index keys when rows reorder.
- **Performance** — large tables: virtualize or paginate if the codebase already does; avoid unnecessary global state for view-local UI.

## UI and UX

- **i18n** — user-visible strings go through the project’s locale mechanism (`resources/js/languages/*.json`); keep keys consistent with siblings in the same feature.
- **Accessibility** — labels for inputs, focus order for modals, keyboard paths for POS/kiosk where applicable; align with existing components.
- **Printing / receipts** — receipt templates are sensitive; minimal diffs, preserve fiscal/legal layout constraints already in the component; test print flows when touched.

## Safety

- **XSS** — sanitize or trust-boundary HTML consistently with existing use of DOMPurify or server-escaped content; do not pipe raw user HTML into `v-html` without the same guards as nearby code.
- **Secrets** — never embed API keys or tenant secrets in frontend bundles.

## Verification

- Run **targeted** frontend/unit tests for the area changed (`npm test` / Vitest filters as documented in the task).
- For POS/kiosk flows, follow **Playwright** guidance in `.cursor/rules/playwright.mdc` when E2E is in scope.

## When another skill applies

- Use **`frontend-design`** for visual craft on safe surfaces.
- Use **`web-design-guidelines`** for guideline audits (often read-only).
- Use **`systematic-debugging`** when hunting regressions or flaky tests before patching.
