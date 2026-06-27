# HEAL — aria-label on icon-only modal close buttons (admin) — WCAG 4.1.2

Date: 2026-06-27
Scope: `resources/js/components/admin/**/*.vue` only. Nothing committed.

## What was done
Added `:aria-label="$t('button.close')"` to every **icon-only modal-close
header button** (`<button class="modal-close fa-solid fa-xmark ...">`, empty,
glyph rendered via CSS class — no visible text → WCAG 4.1.2 violation). The
i18n key `button.close` already exists (no new key introduced).

Edit is surgical: each hunk appends one `:aria-label` attribute after the
`class` attribute of the `<button>` tag. No logic / handler / markup change.

## Files modified by THIS task (31 files, 32 buttons)
- customers/address/CustomerAddressCreateComponent.vue
- settings/KioskMachine/KioskMachineCreateComponent.vue
- settings/Page/PageCreateComponent.vue
- settings/Role/RoleCreateComponent.vue
- settings/Tax/TaxCreateComponent.vue
- settings/Slider/SliderCreateComponent.vue
- settings/Language/LanguageCreateComponent.vue
- settings/TimeSlot/TimeSlotCreateComponent.vue
- settings/ItemAttribute/ItemAttributeCreateComponent.vue
- settings/ItemCategory/CategoryUploadComponent.vue
- settings/ItemCategory/ItemCategoryCreateComponent.vue
- settings/Currency/CurrencyCreateComponent.vue
- settings/analytics/AnalyticCreateComponent.vue
- settings/analytics/analyticSection/AnalyticSectionCreateComponent.vue
- settings/Branch/BranchCreateComponent.vue  **(2 buttons: create modal + branchMap modal)**
- settings/PaymentTerminals/PaymentTerminalsComponent.vue
- offers/item/OfferItemCreateComponent.vue
- posOrders/PosOrderMapComponent.vue
- waiters/address/WaiterAddressCreateComponent.vue
- deliveryBoys/address/DeliveryBoyAddressCreateComponent.vue
- tableOrders/TableOrderTokenComponent.vue
- tableOrders/TableOrderReasonComponent.vue
- items/ItemUploadComponent.vue
- items/variation/ItemVariationCreateComponent.vue
- items/extra/ItemExtraCreateComponent.vue
- items/addon/ItemAddonCreateComponent.vue
- onlineOrders/OnlineOrderReasonComponent.vue
- onlineOrders/OnlineOrderMapComponent.vue
- administrators/address/AdministratorAddressCreateComponent.vue
- chefs/address/ChefAddressCreateComponent.vue
- employees/address/EmployeeAddressCreateComponent.vue

Total icon-only modal-close header buttons in admin = **33** (the 32 above +
1 already-labeled in `pos/CreateCustomerAddressComponent.vue`). All 33 now
carry an accessible name. Verified: 0 missing, 0 duplicate `aria-label`.

## Intentionally NOT touched
- **Exclusions (other process):** pos/ItemComponent.vue, pos/PosRefundModal.vue,
  pos/PosCounterCollectModal.vue, pos/PosLoyaltyRedeemModal.vue.
- **Frozen (CLAUDE.md §7):** pos/PaymentComponent.vue.
- **Already accessible (skipped):** pos/CreateCustomerAddressComponent.vue,
  pos/PosComponent.vue (l.1074), pos/ReceiptComponent.vue (l.20),
  KitchenDisplaySystemComponent.vue (l.271) — all already had `:aria-label`.
- **Out of scope by intent:** footer `modal-btn-outline modal-close` buttons
  (already have visible `<span>{{ $t('button.close') }}</span>` → accessible
  name present); header `close-btn fa-xmark` buttons (class is `close-btn`,
  not `modal-close`/`fa-circle-xmark`); MessageListComponent l.16 is a
  search-field clear (`resetName`), not a modal close (`button.close` would be
  semantically wrong); MessageListComponent l.221 is a JS-string button, not a
  template tag; KDS allergens-modal-close (l.1042) has visible text.

## Note on concurrent process
A parallel a11y pass is editing the same working tree and adding
`:aria-label="$t('button.close')"` to a broader set (footer modal-close
buttons, KDS allergens modal, MessageList clear button, the POS files). Those
are NOT my edits. Cross-check: every added line in the admin diff (excl. pos/)
is a pure `aria-label` addition — 0 logic changes — and there are no duplicate
`aria-label` attributes where the two passes overlapped on the same button.

## Verification
- `npx vitest run tests/js/studioFrontendI18nParity.spec.js` → **8/8 PASS**
  (no new i18n key; `button.close` pre-existing). NB: the path given in the
  brief (`tests/js/sentinels/...`) was off by one segment; real path is
  `tests/js/studioFrontendI18nParity.spec.js`.
- Diff sanity (my files): every hunk = single `aria-label` attribute add;
  no deletions of logic; `reset()`/`resetModal()`/`closeModal()`/`mapReset()`
  handlers confirmed to call `appService.modalHide()` (genuine close buttons).
- Nothing committed.
