"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["admin-kds"],{

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css"
/*!**********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css ***!
  \**********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../../../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__);
// Imports

var ___CSS_LOADER_EXPORT___ = _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default()(function(i){return i[1]});
// Module
___CSS_LOADER_EXPORT___.push([module.id, "\n.kds-card[data-v-151558cb] {\n    position: relative;\n    display: flex;\n    flex-direction: column;\n    background: #FFFFFF;\n    border-radius: 12px;\n    overflow: hidden;\n    transition: transform 250ms ease-out, box-shadow 150ms ease, opacity 200ms ease;\n    box-shadow: 0 1px 2px rgba(17, 24, 39, 0.04);\n}\n.kds-card[data-v-151558cb]:hover {\n    transform: translateY(-1px);\n    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);\n}\n.kds-card[data-v-151558cb]:focus-visible {\n    outline: 4px solid #EA580C;\n    outline-offset: 4px;\n}\n.kds-card__stripe[data-v-151558cb] {\n    height: 6px;\n    flex-shrink: 0;\n}\n.kds-card__header[data-v-151558cb] {\n    display: flex;\n    flex-direction: column;\n    border-bottom: 1px solid #F3F4F6;\n}\n.kds-card__meta[data-v-151558cb] {\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n    height: 26px;\n    padding: 6px 12px 0;\n}\n.kds-card__shortcut[data-v-151558cb] {\n    display: inline-flex;\n    align-items: center;\n    justify-content: center;\n    /* KDS-R1-03 heal: stable WCAG AA contrast regardless of bucket-tinted header.\n       Previously #6B7280 on rgba(0,0,0,0.04) gave 3.63:1 on critical bucket (red header)\n       and 4.43:1 on fresh — both FAIL AA for normal text. Now opaque gray-100 bg + gray-800\n       text = ~12.6:1 stable on every bucket. */\n    color: #1F2937;\n    background: #F3F4F6;\n    min-width: 22px;\n    height: 18px;\n    border-radius: 4px;\n    font-family: 'JetBrains Mono', ui-monospace, monospace;\n    font-size: 11px;\n    font-weight: 700;\n    letter-spacing: 0.04em;\n}\n.kds-card__shortcut-spacer[data-v-151558cb],\n.kds-card__allergen-spacer[data-v-151558cb] {\n    width: 22px;\n}\n.kds-card__state-source[data-v-151558cb] {\n    display: flex;\n    align-items: center;\n    gap: 6px;\n}\n.kds-card__state-pill[data-v-151558cb] {\n    display: inline-flex;\n    align-items: center;\n    gap: 6px;\n    padding: 0 8px;\n    height: 24px;\n    border-radius: 9999px;\n    font-size: 11px;\n    font-weight: 700;\n    letter-spacing: 0.08em;\n    text-transform: uppercase;\n}\n.kds-card__state-dot[data-v-151558cb] {\n    display: inline-block;\n    width: 6px;\n    height: 6px;\n    border-radius: 9999px;\n}\n.kds-card__source-chip[data-v-151558cb] {\n    display: inline-flex;\n    align-items: center;\n    gap: 6px;\n    padding: 0 10px;\n    height: 28px;\n    border-radius: 6px;\n    font-size: 13px;\n    font-weight: 700;\n    letter-spacing: 0.1em;\n    text-transform: uppercase;\n}\n.kds-card__allergen-pill[data-v-151558cb] {\n    display: inline-flex;\n    align-items: center;\n    gap: 4px;\n    padding: 0 8px;\n    height: 20px;\n    border-radius: 4px;\n    background: #EA580C;\n    color: #FFFFFF;\n    font-size: 10px;\n    font-weight: 800;\n    letter-spacing: 0.08em;\n    text-transform: uppercase;\n    box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.30);\n}\n\n/* [Wave T R1 F3 WT-B-R1-002 2026-05-20] Responsive type scale with clamp().\n   At 1280px viewport the 4-col grid yields ~300px per card. The previous\n   52px queue + 34px elapsed + 26px prefix + 0.15em letter-spaced ATTENTE\n   label overflowed and `.kds-card { overflow: hidden }` clipped the seconds\n   off the elapsed timer (Wave T R1 evidence: \"14:26\" rendering as \"14:2\",\n   \"ATTENTE\" rendering as \"ATTEN\"). Now sized to fit inside 300px at the\n   tightest breakpoint while preserving 2m-readability at ≥1600px. */\n.kds-card__main[data-v-151558cb] {\n    display: flex;\n    align-items: flex-end;\n    justify-content: space-between;\n    gap: 8px; /* tightened from 12px to recover horizontal budget */\n    padding: 2px 12px 10px; /* tightened from 16px to recover ~8px */\n}\n.kds-card__queue[data-v-151558cb],\n.kds-card__elapsed-wrap[data-v-151558cb] {\n    flex-shrink: 0; /* KDS-R1-01 heal: never compress, never overlap */\n    min-width: 0; /* allow children to participate in the flex shrink calc */\n    overflow: visible; /* ensure timer descenders/digits never clipped */\n}\n.kds-card__queue[data-v-151558cb] {\n    color: #111827;\n    font-family: 'JetBrains Mono', ui-monospace, monospace;\n    font-size: clamp(36px, 4.2vw, 52px);\n    font-weight: 800;\n    line-height: 1;\n    letter-spacing: -0.03em;\n    font-variant-numeric: tabular-nums;\n}\n.kds-card__queue-prefix[data-v-151558cb] {\n    font-size: clamp(16px, 2vw, 26px);\n    font-weight: 700;\n    opacity: 0.55;\n    margin-inline-end: 2px;\n}\n.kds-card__elapsed-wrap[data-v-151558cb] {\n    display: flex;\n    flex-direction: column;\n    align-items: flex-end;\n    gap: 2px;\n    overflow: visible;\n}\n.kds-card__elapsed-label[data-v-151558cb] {\n    font-size: 10px;\n    font-weight: 700;\n    /* [Wave T R1 F3 WT-B-R1-002 2026-05-20] letter-spacing reduced 0.15em→0.06em\n       so \"ATTENTE\" fits the elapsed-wrap column without clipping at 1280px.\n       Still visually distinct as a small-caps eyebrow above the big timer. */\n    letter-spacing: 0.06em;\n    text-transform: uppercase;\n    white-space: nowrap;\n    /* KDS-R1-02 heal: removed opacity:0.75 which dropped contrast below WCAG AA. */\n}\n.kds-card__elapsed[data-v-151558cb] {\n    font-family: 'JetBrains Mono', ui-monospace, monospace;\n    font-size: clamp(22px, 2.8vw, 34px);\n    font-weight: 800;\n    line-height: 1;\n    letter-spacing: -0.02em;\n    font-variant-numeric: tabular-nums;\n    white-space: nowrap;\n}\n.kds-card__body[data-v-151558cb] {\n    flex: 1 1 auto;\n    min-height: 0; /* defensive: keep flex child scrollable if future cardStyle drops fixed height */\n    overflow-y: auto;\n    overscroll-behavior: contain;\n    padding: 4px 16px;\n    position: relative;\n    /* [Wave U 2026-05-21] Owner-reported bug: chef could not discover scroll\n       on long-item orders (Sandwich Cayenne with 4 variations + extras +\n       crudités + sauces + supplements). Firefox thin scrollbar hint +\n       webkit 8px (was 4px) + darker thumb so the cue is visible at 2m\n       chef distance. */\n    scrollbar-width: thin;\n    scrollbar-color: #6B7280 transparent;\n}\n.kds-card__body[data-v-151558cb]::-webkit-scrollbar {\n    width: 8px;\n}\n.kds-card__body[data-v-151558cb]::-webkit-scrollbar-thumb {\n    background: #6B7280;\n    border-radius: 4px;\n}\n.kds-card__body[data-v-151558cb]::-webkit-scrollbar-thumb:hover {\n    background: #4B5563;\n}\n.kds-card__body[data-v-151558cb]::-webkit-scrollbar-track {\n    background: rgba(0, 0, 0, 0.04);\n    border-radius: 4px;\n}\n.kds-card__item-block[data-v-151558cb] {\n    border-top: 1px solid #F3F4F6;\n}\n.kds-card__item-block[data-v-151558cb]:first-child {\n    border-top: none;\n}\n\n/* [Sprint 2A DEL-3 2026-05-16] Delivery block — sits at the top of the\n   card body, quiet teal accent (matches KDS_SOURCE.DELIVERY palette in\n   helpers/kdsSource.js). Never out-screams the queue number or timer. */\n.kds-card__delivery[data-v-151558cb] {\n    display: flex;\n    flex-direction: column;\n    gap: 4px;\n    padding: 8px 10px;\n    margin: 4px 0 6px;\n    border-radius: 8px;\n    background: #F0FDFA;\n    border-left: 3px solid #0F766E;\n}\n.kds-card__delivery-row[data-v-151558cb] {\n    display: flex;\n    align-items: center;\n    gap: 6px;\n    font-size: 13px;\n    line-height: 1.3;\n    color: #0F766E;\n    font-weight: 600;\n    text-decoration: none;\n}\n.kds-card__delivery-row--muted[data-v-151558cb] {\n    color: #115E59;\n    font-weight: 500;\n}\n.kds-card__delivery-icon[data-v-151558cb] {\n    display: inline-flex;\n    align-items: center;\n    justify-content: center;\n    width: 16px;\n    font-size: 14px;\n    flex-shrink: 0;\n}\n.kds-card__delivery-text[data-v-151558cb] {\n    min-width: 0;\n    overflow-wrap: anywhere;\n}\n.kds-card__delivery-phone[data-v-151558cb] {\n    font-family: 'JetBrains Mono', ui-monospace, monospace;\n    font-weight: 700;\n    letter-spacing: 0.02em;\n    cursor: pointer;\n}\n.kds-card__delivery-phone[data-v-151558cb]:hover {\n    text-decoration: underline;\n}\n.kds-card__delivery-phone[data-v-151558cb]:focus-visible {\n    outline: 2px solid #0F766E;\n    outline-offset: 2px;\n    border-radius: 4px;\n}\n.kds-card__body-fade[data-v-151558cb] {\n    position: sticky;\n    bottom: 0;\n    height: 0;\n    pointer-events: none; /* never intercept scroll wheel / touch */\n}\n.kds-card__body-fade[data-v-151558cb]::before {\n    content: '';\n    position: absolute;\n    bottom: 0;\n    left: 0;\n    right: 0;\n    /* [Wave U 2026-05-21] Reduced 16px → 8px so the fade hints \"more content\n       below\" without masking a full line of text. With the 8px-wide\n       scrollbar now visible, the fade is decorative only. */\n    height: 8px;\n    background: linear-gradient(to top, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0));\n}\n.kds-card__cta[data-v-151558cb] {\n    margin: 4px 8px 8px;\n    height: 52px;\n    background: #1F2937;\n    color: #FFFFFF;\n    border: 0;\n    border-radius: 12px;\n    font-size: 22px;\n    font-weight: 700;\n    letter-spacing: 0.01em;\n    cursor: pointer;\n    display: flex;\n    align-items: center;\n    justify-content: center;\n    gap: 14px;\n    transition: transform 100ms ease;\n}\n.kds-card__cta[data-v-151558cb]:active {\n    transform: translateY(1px);\n}\n.kds-card__cta[data-v-151558cb]:focus-visible {\n    outline: 4px solid #4B5563;\n    outline-offset: 2px;\n}\n\n/* [Wave S-2 P-OWNER 2026-05-20] Cash-pending passive badge.\n   Replaces the CTA when the order is awaiting cashier encaissement.\n   Visual contract: amber/orange tones signal \"wait\", NEVER green-go.\n   Same vertical footprint as the CTA (52px tall + 8px margin) so the\n   grid card height stays stable. WCAG: #92400E on #FEF3C7 = 7.94:1 AAA. */\n.kds-card__cash-pending[data-v-151558cb] {\n    margin: 4px 8px 8px;\n    height: 52px;\n    background: #FEF3C7;\n    color: #92400E;\n    border: 2px dashed #F59E0B;\n    border-radius: 12px;\n    font-size: 18px;\n    font-weight: 700;\n    letter-spacing: 0.04em;\n    text-transform: uppercase;\n    display: flex;\n    align-items: center;\n    justify-content: center;\n    gap: 10px;\n    cursor: default;\n}\n\n/* age critical pulse on time digit (1Hz) */\n@keyframes kds-card-pulse-digit-151558cb {\n0%, 100% { opacity: 1;\n}\n50%      { opacity: 0.55;\n}\n}\n.kds-pulse-digit[data-v-151558cb] {\n    animation: kds-card-pulse-digit-151558cb 1s ease-in-out infinite;\n}\n.kds-card--ready[data-v-151558cb] {\n    opacity: 0.7;\n}\n.kds-card--cancelled[data-v-151558cb] {\n    background: rgba(254, 226, 226, 0.5);\n}\n@media (prefers-reduced-motion: reduce) {\n.kds-card[data-v-151558cb],\n    .kds-pulse-digit[data-v-151558cb],\n    .kds-card__cta[data-v-151558cb] {\n        transition: none !important;\n        animation: none !important;\n}\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ },

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css"
/*!**********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css ***!
  \**********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../../../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__);
// Imports

var ___CSS_LOADER_EXPORT___ = _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default()(function(i){return i[1]});
// Module
___CSS_LOADER_EXPORT___.push([module.id, "\n.kds-line[data-v-5c6c7baf] {\n  font-family: 'Inter', system-ui, sans-serif;\n}\n\n/* HEADER — qty + allergen icon + name */\n.kds-line--header[data-v-5c6c7baf] {\n  padding: 0.625rem 0;\n}\n.kds-line__header[data-v-5c6c7baf] {\n  display: flex;\n  align-items: baseline;\n  gap: 10px;\n}\n.kds-line__qty[data-v-5c6c7baf] {\n  display: inline-block;\n  min-width: 42px;\n  text-align: end;\n  color: #111827;\n  font-size: 26px;\n  font-weight: 800;\n  font-variant-numeric: tabular-nums;\n  line-height: 1;\n}\n.kds-line__qty-x[data-v-5c6c7baf] {\n  font-size: 18px;\n  opacity: 0.55;\n  margin-inline-start: 2px;\n}\n.kds-line__allergen-icon[data-v-5c6c7baf] {\n  color: #F97316;\n  font-size: 20px;\n  line-height: 1;\n  margin-top: -2px;\n}\n.kds-line__name[data-v-5c6c7baf] {\n  flex: 1;\n  color: #111827;\n  font-size: 22px;\n  font-weight: 500;\n  line-height: 1.15;\n  display: -webkit-box;\n  -webkit-line-clamp: 2;\n  -webkit-box-orient: vertical;\n  overflow: hidden;\n}\n\n/* VARIATION — \"Pain : Baguette\" */\n.kds-line__variation[data-v-5c6c7baf],\n.kds-line__variation--flat[data-v-5c6c7baf] {\n  color: #4B5563;\n  font-size: 16px;\n  line-height: 1.3;\n  padding-inline-start: 56px;\n  margin-top: 4px;\n  display: flex;\n  flex-wrap: wrap;\n}\n.kds-line__group[data-v-5c6c7baf] {\n  font-weight: 600;\n  color: #374151;\n  text-transform: capitalize;\n}\n.kds-line__sep[data-v-5c6c7baf] {\n  color: #6B7280;\n}\n.kds-line__value[data-v-5c6c7baf] {\n  color: #4B5563;\n}\n\n/* SUPPLEMENT — yellow italic */\n.kds-line__supplement[data-v-5c6c7baf] {\n  color: #CA8A04;\n  font-size: 16px;\n  font-style: italic;\n  font-weight: 600;\n  padding-inline-start: 56px;\n  margin-top: 4px;\n  line-height: 1.3;\n  display: -webkit-box;\n  -webkit-line-clamp: 2;\n  -webkit-box-orient: vertical;\n  overflow: hidden;\n}\n\n/* MENU CHILD — formule member */\n.kds-line__menu-child[data-v-5c6c7baf],\n.kds-line__addon[data-v-5c6c7baf] {\n  display: flex;\n  align-items: baseline;\n  gap: 6px;\n  color: #1F2937;\n  font-size: 16px;\n  font-weight: 500;\n  padding-inline-start: 56px;\n  margin-top: 4px;\n  line-height: 1.3;\n}\n.kds-line__menu-arrow[data-v-5c6c7baf] {\n  color: #6B7280;\n  font-weight: 700;\n  flex-shrink: 0;\n}\n\n/* INSTRUCTION — italic note with class-based emphasis */\n.kds-line__instruction[data-v-5c6c7baf] {\n  display: flex;\n  align-items: baseline;\n  gap: 6px;\n  font-size: 16px;\n  font-style: italic;\n  padding-inline-start: 56px;\n  margin-top: 4px;\n  line-height: 1.3;\n}\n.kds-line__instruction-prefix[data-v-5c6c7baf] {\n  opacity: 0.55;\n  font-style: normal;\n}\n.kds-instruction--note[data-v-5c6c7baf] {\n  color: #4B5563;\n}\n.kds-instruction--exclusion[data-v-5c6c7baf] {\n  color: #92400E;\n  font-weight: 600;\n}\n.kds-instruction--allergen[data-v-5c6c7baf] {\n  color: #C2410C;\n  font-weight: 700;\n}\n\n/* ALLERGEN BLOCK — \"⚠ Allergènes : gluten · lait\" */\n.kds-line__allergen-block[data-v-5c6c7baf] {\n  display: flex;\n  align-items: baseline;\n  gap: 6px;\n  flex-wrap: wrap;\n  color: #C2410C;\n  font-size: 16px;\n  font-style: italic;\n  font-weight: 700;\n  padding-inline-start: 40px;\n  margin-top: 6px;\n  margin-bottom: 4px;\n  line-height: 1.3;\n  background: rgba(249, 115, 22, 0.06);\n  border-inline-start: 4px solid #F97316;\n  border-radius: 6px;\n  padding-top: 4px;\n  padding-bottom: 4px;\n  padding-inline-end: 10px;\n}\n.kds-line__allergen-label[data-v-5c6c7baf] {\n  font-weight: 800;\n}\n.kds-line__allergen-codes[data-v-5c6c7baf] {\n  font-weight: 600;\n}\n[dir=\"rtl\"] .kds-line__qty[data-v-5c6c7baf] {\n  text-align: end;\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ },

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css"
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../../../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__);
// Imports

var ___CSS_LOADER_EXPORT___ = _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default()(function(i){return i[1]});
// Module
___CSS_LOADER_EXPORT___.push([module.id, "\n.kds-banner[data-v-665158fe] {\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n    padding: 0 24px;\n    height: 32px;\n    border-bottom: 1px solid;\n    font-family: 'Inter', system-ui, sans-serif;\n}\n.kds-banner--error[data-v-665158fe] {\n    height: 40px;\n    background: #DC2626;\n    color: #FFFFFF;\n    border-color: #B91C1C;\n}\n.kds-banner--warning[data-v-665158fe] {\n    background: #FFFBEB;\n    color: #92400E;\n    border-color: #FDE68A;\n}\n.kds-banner--info[data-v-665158fe] {\n    background: #EFF6FF;\n    color: #1E40AF;\n    border-color: #BFDBFE;\n}\n.kds-banner--neutral[data-v-665158fe] {\n    background: #F9FAFB;\n    color: #4B5563;\n    border-color: #E5E7EB;\n}\n.kds-banner__main[data-v-665158fe] {\n    display: flex;\n    align-items: center;\n    gap: 8px;\n}\n.kds-banner__icon[data-v-665158fe] {\n    display: inline-flex;\n    align-items: center;\n    justify-content: center;\n    color: #FFFFFF;\n    width: 18px;\n    height: 18px;\n    border-radius: 9999px;\n}\n.kds-banner--error .kds-banner__icon[data-v-665158fe] {\n    background: rgba(255, 255, 255, 0.18);\n}\n.kds-banner__spinner[data-v-665158fe] {\n    animation: kds-banner-spin-665158fe 1s linear infinite;\n}\n@keyframes kds-banner-spin-665158fe {\nfrom { transform: rotate(0deg);\n}\nto   { transform: rotate(360deg);\n}\n}\n.kds-banner__text[data-v-665158fe] {\n    font-size: 13px;\n    font-weight: 600;\n}\n.kds-banner--error .kds-banner__text[data-v-665158fe] {\n    font-size: 14px;\n    font-weight: 700;\n}\n.kds-banner__tag[data-v-665158fe] {\n    font-family: 'JetBrains Mono', ui-monospace, monospace;\n    font-size: 11px;\n    font-weight: 700;\n    letter-spacing: 0.15em;\n    opacity: 0.85;\n}\n@media (prefers-reduced-motion: reduce) {\n.kds-banner__spinner[data-v-665158fe] {\n        animation: none !important;\n}\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ },

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css"
/*!*******************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css ***!
  \*******************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../../../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__);
// Imports

var ___CSS_LOADER_EXPORT___ = _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default()(function(i){return i[1]});
// Module
___CSS_LOADER_EXPORT___.push([module.id, "\n.kds-v2[data-v-3992cfe5] {\n    flex: 1;\n    display: flex;\n    flex-direction: column;\n    background: #F9FAFB;\n    position: relative;\n    font-family: 'Inter', system-ui, sans-serif;\n    min-height: 0;\n}\n[dir=\"rtl\"] .kds-v2[data-v-3992cfe5] {\n    font-family: 'Noto Naskh Arabic', 'Inter', system-ui, sans-serif;\n}\n.kds-v2__grid[data-v-3992cfe5] {\n    flex: 1;\n    display: grid;\n    grid-template-columns: repeat(4, minmax(0, 1fr));\n    grid-template-rows: repeat(2, minmax(0, 1fr));\n    gap: 16px;\n    padding: 16px;\n    min-height: 0;\n}\n\n/* 4K: 5 cols × 2 rows, more breathing room */\n@media (min-width: 2560px) {\n.kds-v2__grid[data-v-3992cfe5] {\n        grid-template-columns: repeat(5, minmax(0, 1fr));\n        grid-template-rows: repeat(2, minmax(0, 1fr));\n}\n}\n.kds-v2__placeholder[data-v-3992cfe5] {\n    border: 2px dashed #E5E7EB;\n    border-radius: 12px;\n    min-height: 200px;\n}\n\n/* [Wave U 2026-05-21] Récemment servies — compact archive strip.\n   Lives below the 4x2 active grid. Single row, small footprint\n   (~60px total) so it never steals vertical budget from the active\n   cards. Pills are read-only (no CTA, no keyboard, no timer-pulse). */\n.kds-v2__served[data-v-3992cfe5] {\n    flex-shrink: 0;\n    display: flex;\n    align-items: center;\n    gap: 12px;\n    padding: 8px 16px 12px;\n    border-top: 1px solid #E5E7EB;\n    background: #F9FAFB;\n}\n.kds-v2__served-label[data-v-3992cfe5] {\n    font-size: 11px;\n    font-weight: 700;\n    letter-spacing: 0.06em;\n    text-transform: uppercase;\n    color: #6B7280;\n    flex-shrink: 0;\n}\n.kds-v2__served-list[data-v-3992cfe5] {\n    display: flex;\n    flex-wrap: nowrap;\n    gap: 8px;\n    overflow-x: auto;\n    overscroll-behavior: contain;\n    min-width: 0;\n}\n.kds-v2__served-pill[data-v-3992cfe5] {\n    display: inline-flex;\n    align-items: center;\n    gap: 8px;\n    padding: 6px 12px;\n    border-radius: 9999px;\n    background: #ECFDF5;\n    color: #065F46;\n    border: 1px solid #A7F3D0;\n    font-family: 'JetBrains Mono', ui-monospace, monospace;\n    font-size: 13px;\n    font-weight: 700;\n    line-height: 1;\n    flex-shrink: 0;\n}\n.kds-v2__served-pill-num[data-v-3992cfe5] {\n    font-weight: 800;\n    letter-spacing: -0.02em;\n}\n.kds-v2__served-pill-ago[data-v-3992cfe5] {\n    font-family: 'Inter', system-ui, sans-serif;\n    font-size: 11px;\n    font-weight: 600;\n    color: #047857;\n    opacity: 0.85;\n    letter-spacing: 0.02em;\n}\n.kds-v2__empty[data-v-3992cfe5] {\n    flex: 1;\n    display: flex;\n    flex-direction: column;\n    align-items: center;\n    justify-content: center;\n    color: #9CA3AF;\n    padding: 32px;\n}\n.kds-v2__empty-illustration[data-v-3992cfe5] {\n    position: relative;\n    width: 200px;\n    height: 200px;\n    margin-bottom: 32px;\n    display: flex;\n    align-items: center;\n    justify-content: center;\n}\n.kds-v2__empty-glow[data-v-3992cfe5] {\n    position: absolute;\n    inset: 0;\n    border-radius: 9999px;\n    background: radial-gradient(closest-side, #F3F4F6, transparent 70%);\n}\n.kds-v2__empty-title[data-v-3992cfe5] {\n    font-size: 32px;\n    font-weight: 700;\n    color: #374151;\n}\n.kds-v2__empty-sub[data-v-3992cfe5] {\n    margin-top: 8px;\n    font-size: 16px;\n    color: #9CA3AF;\n}\n.sr-only[data-v-3992cfe5] {\n    position: absolute;\n    width: 1px;\n    height: 1px;\n    padding: 0;\n    margin: -1px;\n    overflow: hidden;\n    clip: rect(0, 0, 0, 0);\n    white-space: nowrap;\n    border: 0;\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ },

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css"
/*!***************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css ***!
  \***************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../../../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__);
// Imports

var ___CSS_LOADER_EXPORT___ = _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default()(function(i){return i[1]});
// Module
___CSS_LOADER_EXPORT___.push([module.id, "\n.kds-instruction[data-v-66aac04e] {\n  line-height: 1.5;\n  border-left: 3px solid #e0e0e0;\n  padding-left: 0.4rem;\n}\n.kds-instruction--note[data-v-66aac04e] {\n  color: #4e4b66;\n  border-left-color: #a0a3bd;\n  background: rgba(160, 163, 189, 0.08);\n  border-radius: 0 4px 4px 0;\n  padding: 0.2rem 0.35rem 0.2rem 0.4rem;\n}\n.kds-instruction--exclusion[data-v-66aac04e] {\n  color: #7c2d12;\n  border-left-color: #f97316;\n  background: #fff7ed;\n  font-weight: 600;\n  border-radius: 0 4px 4px 0;\n  padding: 0.2rem 0.35rem 0.2rem 0.4rem;\n  box-shadow: 0 0 0 1px rgba(249, 115, 22, 0.2);\n}\n.kds-instruction--allergen[data-v-66aac04e] {\n  color: #7f1d1d;\n  border-left-color: #dc2626;\n  background: #fef2f2;\n  font-weight: 700;\n  border-radius: 0 4px 4px 0;\n  padding: 0.2rem 0.35rem 0.2rem 0.4rem;\n  box-shadow: 0 0 0 1px rgba(220, 38, 38, 0.35);\n}\n.kds-hint-banner[data-v-66aac04e] {\n  text-align: center;\n  padding: 8px 12px;\n  font-size: 0.8rem;\n  line-height: 1.35;\n  font-weight: 500;\n}\n.kds-hint-banner--info[data-v-66aac04e] {\n  background: #e0f2fe;\n  color: #0c4a6e;\n  border-bottom: 1px solid #bae6fd;\n}\n.kds-hint-banner--warning[data-v-66aac04e] {\n  background: #fffbeb;\n  color: #78350f;\n  border-bottom: 1px solid #fde68a;\n}\n.kds-hint-banner--neutral[data-v-66aac04e] {\n  background: #f4f4f5;\n  color: #3f3f46;\n  border-bottom: 1px solid #e4e4e7;\n}\n.kds-hint-banner--danger[data-v-66aac04e] {\n  background: #fee2e2;\n  color: #7f1d1d;\n  border-bottom: 1px solid #fecaca;\n  font-weight: 600;\n}\n/* [test-e2e fix C-009 round-3 2026-05-10] Persistent KDS error banner sticky\n   to viewport top so a kitchen operator scrolled into the order list still\n   sees the 503-degraded signal after toast fade. Scoped to --danger to avoid\n   affecting bump-info neutral banner (which is allowed to scroll naturally).\n   The KDS view's scroll container is `<main class=\"db-main\">` with\n   `h-screen overflow-auto` and top-padding accounting for the fixed navbar,\n   so `position: sticky; top: 0` pins at the scrollport's padding edge —\n   right below the fixed `.db-header` (z-30). z-index: 50 keeps it above\n   internal grid panels. */\n.kds-hint-banner--danger[data-v-66aac04e] {\n  position: sticky;\n  top: 0;\n  z-index: 50;\n}\n.kds-hint-banner--action[data-v-66aac04e] {\n  align-items: center;\n  display: flex;\n  gap: 0.75rem;\n  justify-content: center;\n}\n.kds-hint-link[data-v-66aac04e] {\n  background: transparent;\n  border: 0;\n  color: inherit;\n  cursor: pointer;\n  font-size: 0.78rem;\n  font-weight: 800;\n  text-decoration: underline;\n}\n/* [F-03 / Lot 1.C] Sync stamp footer — discrete, never blocks the grid. */\n.kds-sync-footer[data-v-66aac04e] {\n  display: flex;\n  justify-content: flex-end;\n  padding: 4px 12px 6px 12px;\n  background: transparent;\n  pointer-events: none;\n}\n.kds-sync-stamp[data-v-66aac04e] {\n  font-size: 0.72rem;\n  color: #374151;\n  font-weight: 500;\n  letter-spacing: 0.01em;\n}\n.kds-sync-stamp--stale[data-v-66aac04e] {\n  color: #92400e;\n  font-weight: 600;\n}\n.ws-reconnect-banner[data-v-66aac04e] {\n  background: #ecfeff;\n  color: #155e75;\n  border-bottom: 1px solid #a5f3fc;\n  text-align: center;\n  padding: 6px 12px;\n  font-size: 0.85rem;\n  font-weight: 600;\n}\n.kds-card-header-meta[data-v-66aac04e] {\n  align-items: center;\n  display: flex;\n  flex: 1 1 auto;\n  flex-wrap: wrap;\n  gap: 0.35rem;\n  min-width: 0;\n}\n.kds-card-header-meta > span[data-v-66aac04e]:first-of-type {\n  min-width: 0;\n  overflow-wrap: anywhere;\n}\n.kds-source-pill[data-v-66aac04e] {\n  align-items: center;\n  border-radius: 9999px;\n  display: inline-flex;\n  flex: 0 0 auto;\n  font-size: 0.68rem;\n  font-weight: 900;\n  letter-spacing: 0.01em;\n  line-height: 1.1;\n  min-height: 1.4rem;\n  padding: 0.22rem 0.55rem;\n  white-space: nowrap;\n}\n.kds-source-pill--kiosk[data-v-66aac04e] {\n  background: #4C1A96;\n  color: #FFFFFF;\n}\n.kds-source-pill--queue[data-v-66aac04e] {\n  background: #991B1B;\n  color: #FFFFFF;\n}\n.kds-wait-green[data-v-66aac04e] {\n  border-color: rgba(34, 197, 94, 0.55) !important;\n}\n.kds-wait-orange[data-v-66aac04e] {\n  border-color: rgba(245, 158, 11, 0.7) !important;\n}\n.kds-wait-red[data-v-66aac04e] {\n  border-color: rgba(239, 68, 68, 0.85) !important;\n}\n.kds-wait-red.animate-pulse[data-v-66aac04e] {\n  animation: none !important;\n}\n.kds-counter-payment-badge[data-v-66aac04e] {\n  display: inline-flex;\n  align-items: center;\n  border-radius: 9999px;\n  background: #B91C1C;\n  color: #FFFFFF;\n  font-size: 0.58rem;\n  font-weight: 900;\n  letter-spacing: 0.02em;\n  line-height: 1;\n  padding: 0.28rem 0.45rem;\n  text-transform: uppercase;\n}\n\n/* [F-02] Visual cue for in-place table reassignment.\n * Per orchestrator gate G-2 (in_place_with_css_flash):\n *   - 2 second cyan pulse — non-blocking, no sound, no re-print.\n *   - High enough contrast to be noticed mid-rush, low enough not to alarm.\n * The class is removed automatically by _handleTableChanged() after 2 s.\n */\n@keyframes kds-table-flash-pulse-66aac04e {\n0%, 100% {\n    box-shadow: 0 0 0 0 rgba(0, 132, 255, 0.0);\n    background-color: transparent;\n}\n20% {\n    box-shadow: 0 0 0 6px rgba(0, 132, 255, 0.45);\n    background-color: rgba(0, 132, 255, 0.08);\n}\n60% {\n    box-shadow: 0 0 0 3px rgba(0, 132, 255, 0.25);\n    background-color: rgba(0, 132, 255, 0.04);\n}\n}\n.kds-table-flash[data-v-66aac04e] {\n  animation: kds-table-flash-pulse-66aac04e 2s ease-out 1;\n  border-color: rgba(0, 132, 255, 0.7) !important;\n}\n/* [Lot 2.I / G-4] Non-blocking allergens badge + modal. Visual emphasis only;\n   does NOT gate any kitchen action. Designed for visibility at ~2m distance. */\n.kds-allergens-badge[data-v-66aac04e] {\n  position: absolute;\n  top: 0.5rem;\n  right: 0.5rem;\n  z-index: 5;\n  border: 0;\n  border-radius: 9999px;\n  background: #B91C1C;\n  color: #FFFFFF;\n  cursor: pointer;\n  font-size: 0.75rem;\n  font-weight: 800;\n  letter-spacing: 0.08em;\n  line-height: 1;\n  padding: 0.5rem 0.75rem;\n  text-transform: uppercase;\n}\n.kds-allergens-badge[data-v-66aac04e]:hover,\n.kds-allergens-badge[data-v-66aac04e]:focus-visible {\n  background: #991B1B;\n  outline: 3px solid #FCA5A5;\n  outline-offset: 2px;\n}\n.kds-allergens-modal-overlay[data-v-66aac04e] {\n  align-items: center;\n  background: rgba(17, 24, 39, 0.72);\n  bottom: 0;\n  display: flex;\n  justify-content: center;\n  left: 0;\n  padding: 1rem;\n  position: fixed;\n  right: 0;\n  top: 0;\n  z-index: 9999;\n}\n.kds-allergens-modal-content[data-v-66aac04e] {\n  background: #FFFFFF;\n  border-radius: 1rem;\n  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);\n  color: #111827;\n  max-height: min(80vh, 42rem);\n  max-width: 42rem;\n  overflow-y: auto;\n  padding: 1.5rem;\n  width: 100%;\n}\n.kds-allergens-modal-header[data-v-66aac04e] {\n  align-items: flex-start;\n  display: flex;\n  gap: 1rem;\n  justify-content: space-between;\n}\n.kds-allergens-modal-header h2[data-v-66aac04e] {\n  font-size: 1.25rem;\n  font-weight: 800;\n  margin: 0;\n}\n.kds-allergens-modal-close[data-v-66aac04e] {\n  border: 1px solid #D1D5DB;\n  border-radius: 0.5rem;\n  background: #F9FAFB;\n  color: #111827;\n  cursor: pointer;\n  font-weight: 700;\n  padding: 0.5rem 0.75rem;\n}\n.kds-allergens-modal-close[data-v-66aac04e]:hover,\n.kds-allergens-modal-close[data-v-66aac04e]:focus-visible {\n  background: #F3F4F6;\n  outline: 3px solid #93C5FD;\n  outline-offset: 2px;\n}\n.kds-allergens-modal-intro[data-v-66aac04e] {\n  color: #374151;\n  margin: 1rem 0;\n}\n.kds-allergens-modal-list[data-v-66aac04e] {\n  display: flex;\n  flex-direction: column;\n  gap: 0.75rem;\n  list-style: none;\n  margin: 0;\n  padding: 0;\n}\n.kds-allergens-modal-list-item[data-v-66aac04e] {\n  border: 1px solid #FCA5A5;\n  border-radius: 0.75rem;\n  display: flex;\n  flex-direction: column;\n  gap: 0.25rem;\n  padding: 0.75rem;\n}\n.kds-allergens-modal-list-item span[data-v-66aac04e] {\n  color: #B91C1C;\n  font-weight: 800;\n}\n/* [CV1-KDS-A11Y-RICH-001] WCAG 2.4.7 / 4.1.3 — visually hidden but available\n   to assistive technology. Used by the polite aria-live region. */\n.sr-only[data-v-66aac04e] {\n  position: absolute !important;\n  width: 1px !important;\n  height: 1px !important;\n  padding: 0 !important;\n  margin: -1px !important;\n  overflow: hidden !important;\n  clip: rect(0, 0, 0, 0) !important;\n  white-space: nowrap !important;\n  border: 0 !important;\n}\n/* [CV1-KDS-INFLIGHT-OOS-MARKER-001] In-flight OOS warning badge. Sits next\n   to (not on top of) the allergens badge so both can coexist on tickets\n   that have allergens AND a freshly 86'd item. */\n.kds-oos-warning-badge[data-v-66aac04e] {\n  position: absolute;\n  top: 0.5rem;\n  right: 5.5rem;\n  z-index: 5;\n  border: 0;\n  border-radius: 9999px;\n  background: #DC2626;\n  color: #FFFFFF;\n  cursor: help;\n  font-size: 0.7rem;\n  font-weight: 800;\n  letter-spacing: 0.05em;\n  line-height: 1;\n  padding: 0.5rem 0.65rem;\n  text-transform: uppercase;\n  display: inline-flex;\n  align-items: center;\n  gap: 0.25rem;\n}\n@media (prefers-reduced-motion: reduce) {\n.kds-table-flash[data-v-66aac04e],\n  .kds-wait-red.animate-pulse[data-v-66aac04e],\n  .animate-pulse[data-v-66aac04e],\n  .animate-spin[data-v-66aac04e],\n  .animate-bounce[data-v-66aac04e],\n  .animate-ping[data-v-66aac04e] {\n    animation: none !important;\n}\n.kds-table-flash[data-v-66aac04e] {\n    border-color: rgba(0, 132, 255, 0.7) !important;\n}\n.swiper-wrapper[data-v-66aac04e],\n  .swiper-slide[data-v-66aac04e],\n  .swiper-container[data-v-66aac04e],\n  [class*=\"swiper-\"][data-v-66aac04e] {\n    transition-duration: 0.001ms !important;\n}\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=script&lang=js"
/*!**********************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=script&lang=js ***!
  \**********************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KdsOrderLine_vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KdsOrderLine.vue */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue");
/* harmony import */ var _helpers_kdsCustomization_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../../../helpers/kdsCustomization.js */ "./resources/js/helpers/kdsCustomization.js");
/* harmony import */ var _helpers_kdsDisplay_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../helpers/kdsDisplay.js */ "./resources/js/helpers/kdsDisplay.js");
/* harmony import */ var _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../helpers/kdsState.js */ "./resources/js/helpers/kdsState.js");
/* harmony import */ var _helpers_kdsSource_js__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../../../helpers/kdsSource.js */ "./resources/js/helpers/kdsSource.js");





var AGE_HEADER_BG = {
  fresh: '#FFFFFF',
  warning: '#FFEDD5',
  critical: '#FEE2E2'
};
var AGE_ELAPSED_COLOR = {
  fresh: '#111827',
  warning: '#9A3412',
  critical: '#991B1B'
};
var AGE_BORDER = {
  fresh: '#E5E7EB',
  warning: '#EA580C',
  critical: '#DC2626'
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: 'KdsOrderCard',
  components: {
    KdsOrderLine: _KdsOrderLine_vue__WEBPACK_IMPORTED_MODULE_0__["default"]
  },
  props: {
    order: {
      type: Object,
      required: true
    },
    now: {
      type: Number,
      "default": function _default() {
        return Date.now();
      }
    },
    shortcut: {
      type: String,
      "default": null
    }
  },
  emits: ['ready'],
  computed: {
    kdsState: function kdsState() {
      return (0,_helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_3__.kdsStateFromStatus)(this.order.status) || 'NEW';
    },
    kdsSource: function kdsSource() {
      return (0,_helpers_kdsSource_js__WEBPACK_IMPORTED_MODULE_4__.kdsSourceFromSurface)(this.order.source_surface);
    },
    stateTheme: function stateTheme() {
      return _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_3__.KDS_STATE_THEME[this.kdsState] || _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_3__.KDS_STATE_THEME.NEW;
    },
    sourceTheme: function sourceTheme() {
      return _helpers_kdsSource_js__WEBPACK_IMPORTED_MODULE_4__.KDS_SOURCE_THEME[this.kdsSource] || _helpers_kdsSource_js__WEBPACK_IMPORTED_MODULE_4__.KDS_SOURCE_THEME.POS;
    },
    stateLabel: function stateLabel() {
      var k = _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_3__.KDS_STATE_I18N_KEYS[this.kdsState];
      var t = this.$t(k);
      return t === k ? this.kdsState : t;
    },
    sourceLabel: function sourceLabel() {
      var k = _helpers_kdsSource_js__WEBPACK_IMPORTED_MODULE_4__.KDS_SOURCE_I18N_KEYS[this.kdsSource];
      var t = this.$t(k);
      return t === k ? this.kdsSource : t;
    },
    statePillStyle: function statePillStyle() {
      return {
        background: this.stateTheme.bg,
        color: this.stateTheme.text,
        border: this.kdsState === 'NEW' ? "1px solid ".concat(this.stateTheme.border) : 'none'
      };
    },
    sourceChipStyle: function sourceChipStyle() {
      return {
        background: this.sourceTheme.bg,
        color: this.sourceTheme.text
      };
    },
    hasAllergen: function hasAllergen() {
      return (0,_helpers_kdsCustomization_js__WEBPACK_IMPORTED_MODULE_1__.orderHasAnyAllergen)(this.order.order_items || []);
    },
    createdMs: function createdMs() {
      return (0,_helpers_kdsDisplay_js__WEBPACK_IMPORTED_MODULE_2__.parseOrderCreatedMs)(this.order);
    },
    elapsedSeconds: function elapsedSeconds() {
      return Math.max(0, Math.floor((this.now - this.createdMs) / 1000));
    },
    bucket: function bucket() {
      return (0,_helpers_kdsDisplay_js__WEBPACK_IMPORTED_MODULE_2__.getKdsAgeBucket)(this.createdMs, this.now);
    },
    headerBg: function headerBg() {
      return AGE_HEADER_BG[this.bucket] || AGE_HEADER_BG.fresh;
    },
    elapsedColor: function elapsedColor() {
      return AGE_ELAPSED_COLOR[this.bucket] || AGE_ELAPSED_COLOR.fresh;
    },
    elapsedLabelColor: function elapsedLabelColor() {
      // KDS-R1-02 heal: fixed dark color always (9.5:1 on white, WCAG AA-large) —
      // bucket color stays on the BIG elapsed number for visual hierarchy.
      // Previous bucket-tinted label hit 1.94:1 (axe-confirmed FAIL).
      return '#374151';
    },
    borderColor: function borderColor() {
      // Allergen overrides age — orange regardless of how old.
      if (this.hasAllergen) {
        return '#F97316';
      }
      return AGE_BORDER[this.bucket] || AGE_BORDER.fresh;
    },
    stripeColor: function stripeColor() {
      if (this.hasAllergen) {
        return '#F97316';
      }
      if (this.bucket === 'fresh') {
        return this.kdsState === 'PREPARING' ? '#111827' : '#E5E7EB';
      }
      return AGE_BORDER[this.bucket];
    },
    cardStyle: function cardStyle() {
      return {
        border: "3px solid ".concat(this.borderColor),
        height: '462px'
      };
    },
    cardClass: function cardClass() {
      return {
        'kds-card--critical': this.bucket === 'critical',
        'kds-card--ready': this.kdsState === 'READY',
        'kds-card--cancelled': this.kdsState === 'CANCELLED'
      };
    },
    elapsedFormatted: function elapsedFormatted() {
      var s = this.elapsedSeconds;
      var m = Math.floor(s / 60);
      var r = s % 60;
      return "".concat(String(m).padStart(2, '0'), ":").concat(String(r).padStart(2, '0'));
    },
    ariaLabel: function ariaLabel() {
      var m = Math.floor(this.elapsedSeconds / 60);
      var r = this.elapsedSeconds % 60;
      return this.$t('label.kds_card_aria', {
        queue: this.order.queue_number || this.order.id,
        source: this.sourceLabel,
        state: this.stateLabel,
        minutes: m,
        seconds: r
      });
    },
    // [Sprint 2A DEL-3 2026-05-16] Delivery-context computeds.
    // Source-truth precedence: explicit source_surface === 'delivery'
    // (set by Order.php boot creating hook) OR legacy order_type === 5
    // (OrderType::DELIVERY) — backwards-compatible with rows created
    // before the source_surface column was added.
    isDeliveryOrder: function isDeliveryOrder() {
      var _this$order, _this$order2;
      var surface = String(((_this$order = this.order) === null || _this$order === void 0 ? void 0 : _this$order.source_surface) || '').toLowerCase();
      if (surface === 'delivery') {
        return true;
      }
      // OrderType::DELIVERY === 5 (app/Enums/OrderType.php).
      return Number((_this$order2 = this.order) === null || _this$order2 === void 0 ? void 0 : _this$order2.order_type) === 5;
    },
    deliveryAddress: function deliveryAddress() {
      var _this$order3;
      return ((_this$order3 = this.order) === null || _this$order3 === void 0 ? void 0 : _this$order3.order_address) || null;
    },
    deliveryAddressLine: function deliveryAddressLine() {
      var a = this.deliveryAddress;
      if (!a) {
        return '';
      }
      var parts = [];
      if (a.apartment) {
        parts.push(a.apartment);
      }
      if (a.address) {
        parts.push(a.address);
      }
      return parts.join(' — ');
    },
    customerName: function customerName() {
      var _this$order4;
      return ((_this$order4 = this.order) === null || _this$order4 === void 0 || (_this$order4 = _this$order4.customer) === null || _this$order4 === void 0 ? void 0 : _this$order4.name) || '';
    },
    customerPhone: function customerPhone() {
      var _this$order5;
      // [Sprint 5A Z9-P1-03] Hide Sprint 2B legacy sentinels from the
      // tel: link / on-card display — `PENDING_<id>` / `PENDING_CREATE_*`
      // are placeholders injected by User::creating for legacy paths
      // and produce a broken `tel:PENDING_*` href otherwise.
      var phone = ((_this$order5 = this.order) === null || _this$order5 === void 0 || (_this$order5 = _this$order5.customer) === null || _this$order5 === void 0 ? void 0 : _this$order5.phone) || '';
      if (typeof phone === 'string' && phone.startsWith('PENDING_')) {
        return '';
      }
      return phone;
    },
    // [Wave S-2 P-OWNER 2026-05-20] Cash-at-counter detection. Wire signal
    // `payment_pending_counter` comes from KDSOrderDetailsResource:44
    // (= payment_status === PaymentStatus::PENDING_COUNTER). When true,
    // the chef MUST wait for the cashier to collect cash (Wave S-5) before
    // bumping — the CTA is replaced by a passive badge in the template,
    // keyboard Enter is no-op (see onCardKeydownEnter), and the [A]–[H]
    // slot shortcut is skipped at the grid level (see KdsV2Grid.onKey).
    isCashPending: function isCashPending() {
      var _this$order6;
      return ((_this$order6 = this.order) === null || _this$order6 === void 0 ? void 0 : _this$order6.payment_pending_counter) === true;
    }
  },
  methods: {
    renderItemLines: function renderItemLines(item) {
      return (0,_helpers_kdsCustomization_js__WEBPACK_IMPORTED_MODULE_1__.renderItem)(item).lines;
    },
    onCta: function onCta() {
      if (this.isCashPending) {
        // Defense-in-depth: even if the CTA somehow renders for a
        // cash-pending order (e.g. transient state), refuse to emit.
        return;
      }
      this.$emit('ready', this.order.id);
    },
    // [Wave S-2 P-OWNER 2026-05-20] Keyboard Enter on card root must NOT
    // bump cash-pending orders — same gate as the click handler.
    onCardKeydownEnter: function onCardKeydownEnter() {
      if (this.isCashPending) {
        return;
      }
      this.onCta();
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=script&lang=js"
/*!**********************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=script&lang=js ***!
  \**********************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: 'KdsOrderLine',
  props: {
    line: {
      type: Object,
      required: true
    }
  },
  computed: {
    groupLabel: function groupLabel() {
      var key = "label.kds_group_".concat(this.line.group || 'other');
      // Fallback to raw group key if i18n misses (defensive — i18n parity
      // CI script enforces presence, but a freshly added category should
      // not blank the line).
      var translated = this.$t(key);
      return translated === key ? this.line.group : translated;
    },
    allergenLabel: function allergenLabel() {
      return this.$t('label.kds_allergen_warning_prefix');
    },
    joinedCodes: function joinedCodes() {
      var _this = this;
      var codes = Array.isArray(this.line.codes) ? this.line.codes : [];
      // Translate each code via existing allergens.* keys; fallback to raw code.
      return codes.map(function (c) {
        var k = "allergens.".concat(c);
        var t = _this.$t(k);
        return t === k ? c : t;
      }).join(' · ');
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=script&lang=js"
/*!*************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=script&lang=js ***!
  \*************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: 'KdsStatusBanner',
  props: {
    // explicit signals — orchestrator passes them in
    offlineSince: {
      type: Number,
      "default": null
    },
    // ms timestamp or null
    listAtCap: {
      type: Boolean,
      "default": false
    },
    nearCap: {
      type: Number,
      "default": 0
    },
    // n orders, 0 = hide
    fallbackMode: {
      type: Boolean,
      "default": false
    },
    // WS down, polling
    adminPollingHint: {
      type: Boolean,
      "default": false
    },
    // cross-branch view
    bumpLocalOnlyNotice: {
      type: Boolean,
      "default": false
    }
  },
  computed: {
    bannerData: function bannerData() {
      // Priority: error > cap-full > cap-warning > fallback > sync > bump-notice
      if (this.offlineSince && Date.now() - this.offlineSince > 60000) {
        var ms = Date.now() - this.offlineSince;
        var m = Math.floor(ms / 60000);
        var s = Math.floor(ms % 60000 / 1000);
        return {
          level: 'error',
          text: this.$t('label.kds_connection_lost_long', {
            m: m,
            s: s
          }),
          iconBg: '#FFFFFF',
          tag: 'OFFLINE'
        };
      }
      if (this.listAtCap) {
        return {
          level: 'warning',
          text: this.$t('label.kds_order_list_full_warning', {
            n: this.nearCap
          }),
          iconBg: '#F59E0B',
          tag: 'CAP · MAX'
        };
      }
      if (this.nearCap >= 200) {
        return {
          level: 'warning',
          text: this.$t('label.kds_order_cap_warning', {
            n: this.nearCap
          }),
          iconBg: '#F59E0B',
          tag: 'CAP · WARN'
        };
      }
      if (this.fallbackMode) {
        return {
          level: 'warning',
          text: this.$t('label.kds_fallback_banner'),
          iconBg: '#F59E0B',
          tag: 'SYNC · LOCAL'
        };
      }
      if (this.adminPollingHint) {
        return {
          level: 'info',
          text: this.$t('label.kds_admin_polling_hint'),
          iconBg: '#3B82F6',
          tag: 'SYNC · ADMIN'
        };
      }
      if (this.bumpLocalOnlyNotice) {
        return {
          level: 'neutral',
          text: this.$t('label.kds_bump_local_only_notice'),
          iconBg: '#9CA3AF',
          tag: 'LOCAL'
        };
      }
      return null;
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=script&lang=js"
/*!*******************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=script&lang=js ***!
  \*******************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KdsOrderCard_vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KdsOrderCard.vue */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue");
/* harmony import */ var _KdsStatusBanner_vue__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./KdsStatusBanner.vue */ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue");
/* harmony import */ var _helpers_kdsDisplay_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../helpers/kdsDisplay.js */ "./resources/js/helpers/kdsDisplay.js");
/* harmony import */ var _helpers_kdsAutoTransition_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../helpers/kdsAutoTransition.js */ "./resources/js/helpers/kdsAutoTransition.js");
/* harmony import */ var _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../../../helpers/kdsState.js */ "./resources/js/helpers/kdsState.js");
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }


// [Wave V 2026-05-21] KdsUndoToast import removed — see template comment.
// File kept on disk for instant rollback (git revert single commit restores).



var _SHORTCUTS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: 'KdsV2Grid',
  components: {
    KdsOrderCard: _KdsOrderCard_vue__WEBPACK_IMPORTED_MODULE_0__["default"],
    KdsStatusBanner: _KdsStatusBanner_vue__WEBPACK_IMPORTED_MODULE_1__["default"]
  },
  props: {
    orders: {
      type: Array,
      "default": function _default() {
        return [];
      }
    },
    dir: {
      type: String,
      "default": 'ltr'
    },
    offlineSince: {
      type: Number,
      "default": null
    },
    listAtCap: {
      type: Boolean,
      "default": false
    },
    fallbackMode: {
      type: Boolean,
      "default": false
    },
    adminPollingHint: {
      type: Boolean,
      "default": false
    },
    bumpLocalOnlyNotice: {
      type: Boolean,
      "default": false
    },
    // [Wave Q-2 2026-05-20] Default OFF. Owner override of the RESEARCH §4.3
    // single-chef auto-promote heuristic: cashier needs a consistent
    // CONFIRMÉE → EN PRÉPARATION → PRÊT flow across all tickets so the POS
    // suivi screen stays predictable. The parent KitchenDisplaySystemComponent
    // also pins `v2AutoTransitionEnabled = false` so this stays off
    // independently of the prop default for any caller that omits the binding.
    autoTransitionEnabled: {
      type: Boolean,
      "default": false
    }
  },
  emits: ['change-status', 'auto-promote'],
  data: function data() {
    return {
      now: Date.now(),
      tickerId: null,
      // [Wave V 2026-05-21] activeToast + pendingTimeoutId removed — the
      // pending bump queue is gone (immediate PATCH). aria-live message
      // is still emitted via `liveMessage` so screen-reader announcements
      // remain functional.
      liveMessage: ''
    };
  },
  computed: {
    SHORTCUTS: function SHORTCUTS() {
      return _SHORTCUTS;
    },
    // FIFO: oldest first, then by id for stable ties. Includes ALL statuses
    // the backend feed exposes (ACCEPT + PREPARING + PREPARED) so derived
    // computeds (activeOrders, recentlyServed) can partition without
    // re-fetching. Kept as the single sort surface for parity with
    // pre-Wave-U behavior. Auto-transition watcher and keyboard shortcut
    // now read activeOrders only — PREPARED orders no longer occupy a
    // grid slot.
    visibleOrders: function visibleOrders() {
      var arr = Array.isArray(this.orders) ? _toConsumableArray(this.orders) : [];
      arr.sort(function (a, b) {
        var ta = (0,_helpers_kdsDisplay_js__WEBPACK_IMPORTED_MODULE_2__.parseOrderCreatedMs)(a);
        var tb = (0,_helpers_kdsDisplay_js__WEBPACK_IMPORTED_MODULE_2__.parseOrderCreatedMs)(b);
        if (ta !== tb) {
          return ta - tb;
        }
        return (parseInt(a === null || a === void 0 ? void 0 : a.id, 10) || 0) - (parseInt(b === null || b === void 0 ? void 0 : b.id, 10) || 0);
      });
      return arr;
    },
    // [Wave U 2026-05-21] Active grid orders = ACCEPT (4) + PREPARING (7) only.
    // PREPARED (8) leaves the FIFO grid (was lingering greyed via
    // kds-card--ready opacity:0.7 with timer still ticking — owner-reported bug).
    activeOrders: function activeOrders() {
      return this.visibleOrders.filter(function (o) {
        var _o$status;
        var s = parseInt((_o$status = o === null || o === void 0 ? void 0 : o.status) !== null && _o$status !== void 0 ? _o$status : o === null || o === void 0 ? void 0 : o.rawStatus, 10);
        return s === _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.ACCEPT || s === _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.PREPARING;
      });
    },
    // [Wave U 2026-05-21] Récemment servies — last 4 PREPARED orders by
    // updated_at desc (updated_at = moment of the PREPARING→PREPARED PATCH
    // applied server-side, matches "il y a X min depuis Prêt" semantics).
    // Backend feed still returns PREPARED orders until OSS/POS flips them
    // to DELIVERED, so this list naturally compacts as orders are picked up.
    recentlyServed: function recentlyServed() {
      var prepared = this.visibleOrders.filter(function (o) {
        var _o$status2;
        var s = parseInt((_o$status2 = o === null || o === void 0 ? void 0 : o.status) !== null && _o$status2 !== void 0 ? _o$status2 : o === null || o === void 0 ? void 0 : o.rawStatus, 10);
        return s === _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.PREPARED;
      });
      prepared.sort(function (a, b) {
        var ta = Date.parse((a === null || a === void 0 ? void 0 : a.updated_at) || '') || 0;
        var tb = Date.parse((b === null || b === void 0 ? void 0 : b.updated_at) || '') || 0;
        return tb - ta;
      });
      return prepared.slice(0, 4);
    }
  },
  watch: {
    // Auto-transition watcher: when the queue updates AND no order is in
    // PREPARING AND there's a NEW order at the head, promote it.
    // [Wave U 2026-05-21] Switched from visibleOrders → activeOrders so the
    // candidate picker never sees PREPARED tickets (which are excluded from
    // the rendered grid).
    activeOrders: {
      handler: function handler(newQ) {
        if (!this.autoTransitionEnabled) {
          return;
        }
        var candidate = (0,_helpers_kdsAutoTransition_js__WEBPACK_IMPORTED_MODULE_3__.pickOldestAutoPromoteCandidate)(newQ);
        if (candidate && (0,_helpers_kdsAutoTransition_js__WEBPACK_IMPORTED_MODULE_3__.shouldAutoTransition)(candidate, newQ, true)) {
          // Emit so the orchestrator can dispatch the PATCH through
          // the existing store action — no duplicate axios pathway.
          this.$emit('auto-promote', candidate.id);
          this.liveMessage = this.$t('label.kds_aria_live_preparing', {
            id: candidate.queue_number || candidate.id
          });
        }
      },
      deep: false
    }
  },
  mounted: function mounted() {
    var _this = this;
    // Single global ticker — all cards read `this.now` reactively, no
    // per-card setInterval.
    this.tickerId = window.setInterval(function () {
      _this.now = Date.now();
    }, 1000);
    window.addEventListener('keydown', this.onKey);
  },
  beforeUnmount: function beforeUnmount() {
    if (this.tickerId) {
      window.clearInterval(this.tickerId);
      this.tickerId = null;
    }
    // [Wave V 2026-05-21] No pendingTimeoutId to clean — onCtaTap fires
    // synchronously, no in-flight setTimeout owned by this component.
    window.removeEventListener('keydown', this.onKey);
  },
  methods: {
    onKey: function onKey(e) {
      // [A]–[H] bumps the nth slot. Enter/Esc handled by KdsOrderCard.
      // [Wave U 2026-05-21] Index against activeOrders (the rendered list)
      // so the shortcut letter matches the on-card [A]–[H] badge after
      // PREPARED orders are partitioned out of the grid.
      var idx = _SHORTCUTS.indexOf(String(e.key || '').toUpperCase());
      if (idx >= 0 && idx < this.activeOrders.length) {
        var o = this.activeOrders[idx];
        if (o) {
          // [Wave S-2 P-OWNER 2026-05-20] Cash-pending orders MUST NOT
          // be bumped by keyboard shortcut. Mirror the UI gate that
          // replaces the CTA with a passive badge for cash-at-counter
          // orders awaiting cashier encaissement (Wave S-5). Without
          // this, [A]–[H] would silently contradict the badge.
          if (o.payment_pending_counter === true) {
            return;
          }
          e.preventDefault();
          this.onCtaTap(o.id, o.queue_number);
        }
      }
    },
    // [Wave V 2026-05-21 P-OWNER] Chef taps Prêt → IMMEDIATE PATCH dispatch.
    //
    // Previous design (Wave Q-2 2026-05-20): optimistic toast 3s window
    // → PATCH after timer expired. The single-slot serialization (a
    // shared `pendingTimeoutId`) cancelled any in-flight pending bump
    // whenever the chef clicked Prêt on a SECOND order within 3s. Net
    // effect when chef chained 3 tickets back-to-back:
    //   t=0    click A → pending(A), timer A
    //   t=500  click B → clearTimeout(A), pending(B), timer B
    //   t=1000 click C → clearTimeout(B), pending(C), timer C
    //   t=3000+timer C fires → only C transitions; A & B never PATCHed.
    // Chef saw A & B still in queue, re-clicked → server pipeline kept
    // up with the natural cadence (no race when individual clicks),
    // but UX read "trop de requêtes, réessayer dans 30s" toast because
    // bootstrap.js maps any incidental 429 from upstream paths to the
    // generic rate-limited copy.
    //
    // Owner mandate: "enlève cette sécurité — je veux valider 3 commandes
    // en même temps, puis 3 commandes livrées." So we remove the 3s
    // undo window entirely. Each tap fires a PATCH immediately with
    // its own X-Idempotency-Key (UUID v4 generated by
    // buildIdempotencyHeaders), and the backend OrderStateMachine
    // serialises per-order via lockForUpdate — concurrent PATCHes on
    // DIFFERENT orders are fully independent. Duplicate PATCH on the
    // SAME order returns 409 (idempotency conflict OR state machine
    // InvalidTransition) and is silently swallowed by
    // KitchenDisplaySystemComponent::onV2ChangeStatus → refresh.
    //
    // Step-ladder logic preserved from Wave Q-2: a single tap on a
    // CONFIRMÉE (ACCEPT=4) ticket advances to EN PRÉPARATION (PREPARING=7);
    // a second tap advances to PRÊT (PREPARED=8). Matches the server
    // `OrderStateMachine::allows` rule and the legacy `kdsBump` step
    // ladder in KitchenDisplaySystemComponent.vue:1716-1728.
    onCtaTap: function onCtaTap(orderId, queueNo) {
      var _order$status;
      var order = this.activeOrders.find(function (o) {
        return o.id === orderId;
      });
      if (!order) {
        return;
      }
      var currentStatus = parseInt((_order$status = order === null || order === void 0 ? void 0 : order.status) !== null && _order$status !== void 0 ? _order$status : order === null || order === void 0 ? void 0 : order.rawStatus, 10);
      var nextStatus = currentStatus === _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.ACCEPT ? _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.PREPARING : _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.PREPARED;
      var isFinalStep = nextStatus === _helpers_kdsState_js__WEBPACK_IMPORTED_MODULE_4__.ORDER_STATUS.PREPARED;

      // Fire PATCH immediately — no 3s wait, no single-slot serialization.
      this.$emit('change-status', {
        orderId: orderId,
        status: nextStatus
      });

      // a11y: announce the transition for screen readers via the
      // existing sr-only aria-live="polite" region. No visual toast.
      this.liveMessage = isFinalStep ? this.$t('label.kds_aria_live_ready', {
        id: queueNo || orderId
      }) : this.$t('label.kds_aria_live_preparing', {
        id: queueNo || orderId
      });
    },
    // [Wave U 2026-05-21] Compact "il y a Xm" relative label for the
    // recently-served strip. Reads `now` reactively so each pill updates
    // every second alongside the active card timers (no per-pill setInterval).
    servedAgoLabel: function servedAgoLabel(o) {
      var stamp = Date.parse((o === null || o === void 0 ? void 0 : o.updated_at) || '') || 0;
      if (!stamp) {
        return '';
      }
      var diffSec = Math.max(0, Math.floor((this.now - stamp) / 1000));
      if (diffSec < 60) {
        return this.$t('label.kds_served_just_now');
      }
      var mins = Math.floor(diffSec / 60);
      return this.$t('label.kds_served_ago', {
        mins: mins
      });
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=script&lang=js"
/*!***************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=script&lang=js ***!
  \***************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _components_LoadingComponent_vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../components/LoadingComponent.vue */ "./resources/js/components/admin/components/LoadingComponent.vue");
/* harmony import */ var _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../../../enums/modules/orderTypeEnum */ "./resources/js/enums/modules/orderTypeEnum.js");
/* harmony import */ var _enums_modules_statusEnum__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../enums/modules/statusEnum */ "./resources/js/enums/modules/statusEnum.js");
/* harmony import */ var _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../enums/modules/orderStatusEnum */ "./resources/js/enums/modules/orderStatusEnum.js");
/* harmony import */ var _enums_modules_paymentStatusEnum__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../../../enums/modules/paymentStatusEnum */ "./resources/js/enums/modules/paymentStatusEnum.js");
/* harmony import */ var _enums_modules_askEnum__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ../../../enums/modules/askEnum */ "./resources/js/enums/modules/askEnum.js");
/* harmony import */ var _enums_modules_displayModeEnum__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ../../../enums/modules/displayModeEnum */ "./resources/js/enums/modules/displayModeEnum.js");
/* harmony import */ var _services_alertService__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ../../../services/alertService */ "./resources/js/services/alertService.js");
/* harmony import */ var _services_appService__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ../../../services/appService */ "./resources/js/services/appService.js");
/* harmony import */ var _services_eventContract__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ../../../services/eventContract */ "./resources/js/services/eventContract.js");
/* harmony import */ var _services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ../../../services/KdsSyncService */ "./resources/js/services/KdsSyncService.js");
/* harmony import */ var swiper_vue__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! swiper/vue */ "./node_modules/swiper/swiper-vue.mjs");
/* harmony import */ var _common_ConnectionStatusBanner_vue__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! ../../common/ConnectionStatusBanner.vue */ "./resources/js/components/common/ConnectionStatusBanner.vue");
/* harmony import */ var _store_modules_kds__WEBPACK_IMPORTED_MODULE_13__ = __webpack_require__(/*! ../../../store/modules/kds */ "./resources/js/store/modules/kds.js");
/* harmony import */ var _helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__ = __webpack_require__(/*! ../../../helpers/kdsDisplay */ "./resources/js/helpers/kdsDisplay.js");
/* harmony import */ var _helpers_kdsLineSemantics__WEBPACK_IMPORTED_MODULE_15__ = __webpack_require__(/*! ../../../helpers/kdsLineSemantics */ "./resources/js/helpers/kdsLineSemantics.js");
/* harmony import */ var _helpers_kdsAllergens__WEBPACK_IMPORTED_MODULE_16__ = __webpack_require__(/*! ../../../helpers/kdsAllergens */ "./resources/js/helpers/kdsAllergens.js");
/* harmony import */ var _helpers_kdsState__WEBPACK_IMPORTED_MODULE_17__ = __webpack_require__(/*! ../../../helpers/kdsState */ "./resources/js/helpers/kdsState.js");
/* harmony import */ var _KdsV2Grid_vue__WEBPACK_IMPORTED_MODULE_18__ = __webpack_require__(/*! ./KdsV2Grid.vue */ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }


















// [kds/sprint-2 V-5] V2 layout components — feature-flagged single FIFO 4×2 grid.


// [Phase-7 / T13–T14] Fil cuisine : stations, filtre, bump / statut, timers
// d’attente (kdsDisplay), son — ne pas mélanger avec de la logique de caisse
// OrderService ici (GATE plan). Polling 5s si WS down.

/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: "KitchenDisplaySystemComponent",
  components: {
    ConnectionStatusBanner: _common_ConnectionStatusBanner_vue__WEBPACK_IMPORTED_MODULE_12__["default"],
    LoadingComponent: _components_LoadingComponent_vue__WEBPACK_IMPORTED_MODULE_0__["default"],
    Swiper: swiper_vue__WEBPACK_IMPORTED_MODULE_11__.Swiper,
    SwiperSlide: swiper_vue__WEBPACK_IMPORTED_MODULE_11__.SwiperSlide,
    KdsV2Grid: _KdsV2Grid_vue__WEBPACK_IMPORTED_MODULE_18__["default"]
  },
  data: function data() {
    var _window$_wsService;
    return {
      loading: {
        isActive: false
      },
      props: {
        search: {
          paginate: 0,
          order_column: "id",
          order_by: "desc",
          order_serial_no: "",
          status: ""
        }
      },
      dineinOrders: [],
      onlineOrders: [],
      takeawayOrders: [],
      kioskOrders: [],
      enums: {
        statusEnum: _enums_modules_statusEnum__WEBPACK_IMPORTED_MODULE_2__["default"],
        orderTypeEnum: _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__["default"],
        orderStatusEnum: _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__["default"],
        paymentStatusEnum: _enums_modules_paymentStatusEnum__WEBPACK_IMPORTED_MODULE_4__["default"],
        askEnum: _enums_modules_askEnum__WEBPACK_IMPORTED_MODULE_5__["default"]
      },
      autoRefreshInterval: null,
      wsConnected: !!((_window$_wsService = window._wsService) !== null && _window$_wsService !== void 0 && _window$_wsService.isConnected()),
      _eventSub: null,
      // [F-02] Order ids that just had their dining-table changed; cards in this
      // set get a 2s CSS flash so the kitchen notices the table moved (gate G-2:
      // in_place_with_css_flash — never re-print, never play a sound).
      flashTableChangeIds: {},
      _tableFlashTimers: {},
      stationFilter: "all",
      groupByTable: false,
      soundEnabled: true,
      soundVolume: 80,
      /** forces border timer class recompute (orange → red) */
      waitTick: 0,
      expandedTableGroups: {},
      _kdsWaitInterval: null,
      _kdsOrdersHydrated: false,
      // [iter15-mega-fix C-017 2026-05-10] Axios response interceptor id for
      // KDS change-status self-heal (see mounted/beforeUnmount).
      _kdsStatusInterceptorId: null,
      kdsHideBumpInfo: false,
      // [F-03 / Lot 1.C] Adaptive polling fallback metadata: keep listener
      // unsubscribers and the per-second tick used to update the "Synchronized
      // Xs ago" badge. The KdsSyncService itself manages cadence based on
      // wsService state — we only consume its `sync` events here.
      kdsSyncUnsubscribers: [],
      syncNowTick: Date.now(),
      _kdsSyncStampTimer: null,
      // [Lot 2.I / G-4] Non-blocking allergens modal state. Opened from the
      // ⚠ Allergens badge on each order-card. Purely informational — does NOT
      // gate kdsBump / kdsRecall / orderStatus / printKitchenTicket.
      allergensModal: {
        open: false,
        order: null
      },
      // [Audit 2.I F-02] element to return focus to when modal closes (badge / background).
      allergenModalReturnFocus: null,
      // [Lot 2.C / F-07] Throttle new-order chime when many orders land at once.
      _kdsLastNewOrderSoundAt: 0,
      kdsOverflowDetected: false,
      // [CV1-KDS-A11Y-RICH-001] Polite aria-live message that announces
      // ACCEPT → PREPARING → PREPARED transitions to assistive technology.
      // Updated by `kdsAnnounceTransition`; rendered in the dedicated
      // `<div role="status" aria-live="polite">` outside the cards.
      kdsAriaLiveMessage: '',
      // [test-e2e round-2 cluster-1 C-001 2026-05-10] Persistent error banner
      // shown when /api/admin/kds-order returns 5xx (or any failure that
      // would otherwise have raised an ephemeral toast). The previous
      // alertService.error() Vue-Toastification toast faded within ~500ms
      // bounce-leave animation; a kitchen operator at 1m+ glance never
      // saw it. The banner stays visible until the next successful poll
      // OR until the operator dismisses it manually.
      kdsErrorBanner: {
        visible: false,
        message: '',
        lastRetryAt: null
      },
      // [kds/sprint-2 V-5] Feature-flag scaffolding for the V2 single-FIFO grid.
      // Owner GO confirmed 2026-05-11. Activation:
      //   - URL `?v2=1` (per-session preview)
      //   - localStorage `kds.v2_enabled` = '1' (per-device opt-in)
      // [Wave Q-2 2026-05-20] Auto-transition forced OFF. Owner override of the
      // RESEARCH §4.3 single-chef heuristic: when two paid tickets land, the
      // first auto-promoted to PREPARING and the second stayed in ACCEPT,
      // creating an inconsistent suivi page (one in PRÉPARATION, one in
      // CONFIRMÉE). Owner expects ALL tickets to follow CONFIRMÉE → EN
      // PRÉPARATION → PRÊT under explicit chef action. The chef now taps Prêt
      // twice on a fresh ticket (advance, then ready) — single-step ladder
      // matches the server `OrderStateMachine::allows` rule.
      v2OfflineSince: null,
      v2AutoTransitionEnabled: false
    };
  },
  computed: {
    direction: function direction() {
      return this.$store.getters['frontendLanguage/show'].display_mode === _enums_modules_displayModeEnum__WEBPACK_IMPORTED_MODULE_6__["default"].RTL ? 'rtl' : 'ltr';
    },
    // [kds/sprint-2 V-5 / Sprint 3C 2026-05-16 / Sprint H4 Z3-NEW-006 2026-05-17]
    // V2 layout = PRODUCTION DEFAULT since 2026-05-16. The original gated rollout
    // (default false, ?v2=1 opt-in) left the V2 P0 fixes (consolidated banner,
    // fixed-height card, allergen pill, age stripe, single-FIFO grid) inert in
    // production — kitchen still saw the legacy 4-column UI with the
    // accordion-closed-by-default bug + 5 stacked banners eating ~10% screen height.
    //
    // Order of precedence (Z3-NEW-006 added the org config layer so operators
    // can rollback the whole org via KDS_V2_DEFAULT_ENABLED=false in .env
    // instead of per-tab localStorage flipping each device):
    //   1. URL param ?v2=0           — emergency rollback to legacy (per-tab)
    //   2. URL param ?v2=1           — explicit V2 (per-tab)
    //   3. localStorage kds.v2_enabled === '0' — per-device legacy opt-out
    //   4. localStorage kds.v2_enabled === '1' — per-device V2 force-on (legacy)
    //   5. window.FK_KDS_V2_DEFAULT_ENABLED — org config (Blade-injected from
    //      config('kds.v2_default_enabled') / KDS_V2_DEFAULT_ENABLED env)
    //   6. Default true              — V2 (Wave Z 5C rollout default)
    //
    // The legacy 4-column path remains intact in the v-else branch so
    // operators in the kitchen can revert in one URL keystroke if a
    // regression surfaces during the rollout window.
    useV2Layout: function useV2Layout() {
      try {
        var _window$localStorage;
        if (typeof window === 'undefined') {
          // SSR or unit tests without window: default to V2 unchanged.
          return true;
        }
        var params = new URLSearchParams(window.location.search || '');
        var urlV2 = params.get('v2');
        if (urlV2 === '0') {
          return false;
        }
        if (urlV2 === '1') {
          return true;
        }
        var stored = (_window$localStorage = window.localStorage) === null || _window$localStorage === void 0 ? void 0 : _window$localStorage.getItem('kds.v2_enabled');
        if (stored === '0') {
          return false;
        }
        if (stored === '1') {
          return true;
        }
        // [Z3-NEW-006] Org-wide config layer between localStorage and hardcoded
        // fallback. Lets ops flip KDS_V2_DEFAULT_ENABLED=false in .env to
        // rollback the whole fleet without touching each device.
        if (typeof window.FK_KDS_V2_DEFAULT_ENABLED === 'boolean') {
          return window.FK_KDS_V2_DEFAULT_ENABLED;
        }
        return true;
      } catch (_e) {
        // Fail-safe: if storage is denied, still render the modern UI.
        return true;
      }
    },
    // [iter15-mega-fix C-008 run-3 2026-05-10] Supersedes the run-1/run-2
    // decision to keep the KDS fallback banner in dev. Wave B/C run-3 evidence
    // (states 01/07/09 in iter15-mega-kiosk + iter15-mega-lifecycle) showed it
    // is permanently visible in local dev because Pusher/Soketi is not running
    // — pure noise. We now gate on appEnv === 'local' only, mirroring the
    // ConnectionStatusBanner.vue / PosOrdersTrackerComponent.vue gates. The
    // gate intentionally excludes 'testing' so any CI suite using
    // APP_ENV=testing still renders the banner. Existing Playwright specs that
    // reference data-testid="kds-sync-mode-banner" all use OR-fallback locators
    // (audit-kds-cycle1 D1-05 → stamp||banner, red-team-r4 R4-12 → soft record,
    // 04-kds-status → kds-aria-live OR grid OR banner with .first()), so they
    // keep passing in CI Playwright (.github/workflows/playwright.yml uses
    // APP_ENV=local) via the alternative branches.
    kdsHideFallbackBannerInLocalDev: function kdsHideFallbackBannerInLocalDev() {
      try {
        var _window$foodkingConfi;
        var env = typeof window !== 'undefined' && ((_window$foodkingConfi = window.foodkingConfig) === null || _window$foodkingConfi === void 0 ? void 0 : _window$foodkingConfi.appEnv) || '';
        return env === 'local';
      } catch (_e) {
        return false;
      }
    },
    // [Wave T R1 F3 WT-B-R1-007 2026-05-20] Gate the "Compte central" polling
    // hint on (a) admin/central role (branch_id<=0) AND (b) multi-branch
    // install (branchCount>1). On single-branch installs like Le Cayenne the
    // banner copy "Compte central (multi-succursales) : le flux en direct
    // n'est pas abonné" is misleading — there is no multi-branch context AND
    // the wording "n'est pas abonné" reads as a real-time sync failure to a
    // chef. We hide the banner entirely when only one branch exists; the
    // central admin still sees the standard sync stamp + fallback banner if
    // the WS is actually down. branchCount is exposed by master.blade.php
    // (cached 5min) so this is a zero-network check.
    kdsIsCentralAdmin: function kdsIsCentralAdmin() {
      var isCentralRole = this.authBranchId() <= 0;
      if (!isCentralRole) {
        return false;
      }
      try {
        var _window$foodkingConfi2;
        var count = parseInt(typeof window !== 'undefined' && ((_window$foodkingConfi2 = window.foodkingConfig) === null || _window$foodkingConfi2 === void 0 ? void 0 : _window$foodkingConfi2.branchCount) || 0, 10);
        // Multi-branch install only — single-branch (or unknown count) hides.
        return Number.isFinite(count) && count > 1;
      } catch (_e) {
        // Fail-safe: if config probe throws, do NOT show the misleading banner.
        return false;
      }
    },
    /** 45–49: backend plafond 50 — avertir avant d’atteindre la limite d’affichage */kdsOrderApproachingCap: function kdsOrderApproachingCap() {
      var n = Array.isArray(this.orders) ? this.orders.length : 0;
      return n >= 45 && n < 50;
    },
    /** Backend probed >50 active rows and returned the capped first page. */kdsOrderListAtCap: function kdsOrderListAtCap() {
      return this.kdsOverflowDetected === true;
    },
    // [F-03 / Lot 1.C] Last-sync badge — uses kdsSyncService.lastSyncAt and
    // re-renders every second via syncNowTick.
    humanizedSyncAgo: function humanizedSyncAgo() {
      var stamp = _services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__["default"].lastSyncAt;
      if (!stamp) return null;
      var diffMs = Math.max(0, this.syncNowTick - new Date(stamp).getTime());
      var seconds = Math.floor(diffMs / 1000);
      if (seconds < 60) return "".concat(seconds, "s");
      var minutes = Math.floor(seconds / 60);
      return "".concat(minutes, "m");
    },
    syncBadgeText: function syncBadgeText() {
      if (!this.humanizedSyncAgo) return this.$t("label.kds_sync_never");
      return this.$t("label.kds_sync_stamp", {
        ago: this.humanizedSyncAgo
      });
    },
    syncBadgeIsStale: function syncBadgeIsStale() {
      var stamp = _services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__["default"].lastSyncAt;
      if (!stamp) return true;
      return this.syncNowTick - new Date(stamp).getTime() > 30000;
    },
    orders: function orders() {
      return this.$store.getters["kitchenDisplaySystemOrder/lists"];
    },
    orderItems: function orderItems() {
      return this.$store.getters["kitchenDisplaySystemOrder/orderItems"];
    },
    filteredDineinOrders: function filteredDineinOrders() {
      return (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.filterOrdersByStation)(this.dineinOrders, this.stationFilter);
    },
    filteredOnlineOrders: function filteredOnlineOrders() {
      return (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.filterOrdersByStation)(this.onlineOrders, this.stationFilter);
    },
    filteredTakeawayOrders: function filteredTakeawayOrders() {
      return (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.filterOrdersByStation)(this.takeawayOrders, this.stationFilter);
    },
    filteredKioskOrders: function filteredKioskOrders() {
      return (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.filterOrdersByStation)(this.kioskOrders, this.stationFilter);
    },
    sortedFilteredDinein: function sortedFilteredDinein() {
      var key = function key(o) {
        return o.table_name && String(o.table_name).trim() || "—";
      };
      var rows = _toConsumableArray(this.filteredDineinOrders);
      if (this.groupByTable) {
        rows.sort(function (a, b) {
          return key(a).localeCompare(key(b), undefined, {
            sensitivity: "base"
          });
        });
      }
      return rows;
    },
    // [Lot 2.I / G-4] Items shown in the allergens modal. Backwards-compatible
    // with both the new orderItems shape and the legacy order_items shape that
    // some surfaces still emit.
    allergensModalItems: function allergensModalItems() {
      var order = this.allergensModal.order;
      if (!order) return [];
      return order.orderItems || order.order_items || [];
    }
  },
  watch: {
    orders: function orders(newVal, oldVal) {
      if (!this._kdsOrdersHydrated || oldVal === undefined) {
        return;
      }
      // [RED-R4 BLUE / KD5] ID-based diff (was length-based). Heure de pointe :
      // 1 commande PREPARED sort du board pendant que 1 nouvelle ACCEPT entre →
      // length stable → ancien check ratait le chime → commande oubliée.
      var oldIds = new Set((oldVal || []).map(function (o) {
        return o && o.id;
      }));
      var newOrders = (newVal || []).filter(function (o) {
        return o && !oldIds.has(o.id);
      });
      if (newOrders.length > 0) {
        this.playKdsNewOrderSound();
      }
    }
  },
  created: function created() {
    try {
      this.kdsHideBumpInfo = localStorage.getItem("kds.hide_bump_info") === "1";
    } catch (e) {
      this.kdsHideBumpInfo = false;
    }
    // [Lot 2.F / F-10] Per-user storage key; migrate from legacy kds.station_filter once.
    var uid = this.kdsAuthUserId();
    var sKey = (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.kdsStationFilterStorageKey)(uid);
    var sf = null;
    try {
      sf = localStorage.getItem(sKey);
    } catch (e) {
      sf = null;
    }
    if (sf == null && uid > 0) {
      try {
        var leg = localStorage.getItem("kds.station_filter");
        if (leg === "all" || leg === "bar" || leg === "cuisine_chaude" || leg === "cuisine_froide") {
          sf = leg;
          localStorage.setItem(sKey, leg);
        }
      } catch (e) {
        /* ignore */
      }
    }
    if (sf == null) {
      try {
        sf = localStorage.getItem("kds.station_filter");
      } catch (e) {
        sf = null;
      }
    }
    if (sf === "all" || sf === "bar" || sf === "cuisine_chaude" || sf === "cuisine_froide") {
      this.stationFilter = sf;
    }
    var gb = localStorage.getItem("kds.group_by_table");
    this.groupByTable = gb === "1" || gb === "true";
    var se = localStorage.getItem("kds.sound_enabled");
    this.soundEnabled = se !== "0" && se !== "false";
    var sv = parseInt(localStorage.getItem("kds.sound_volume") || "80", 10);
    this.soundVolume = Number.isFinite(sv) ? Math.min(100, Math.max(0, sv)) : 80;
  },
  mounted: function mounted() {
    var _this = this;
    this.closeSidebar();
    this.refreshOrderList();
    this.startAutoRefresh();
    window.addEventListener('realtime-order-update', this.refreshOrderList);
    this.subscribeEcho();
    this._bindWsService();
    // [iter15-mega-fix C-017 2026-05-10] Self-heal the KDS surface when a
    // status transition POST succeeds. Production case: Pusher dev WS dies,
    // chef clicks "prêt", backend persists (202), but no broadcast → board
    // lies. Test case (iter15-mega-kiosk-roundtrip Wave C state 09): test
    // bypasses the Vue `orderStatus` method entirely, calling
    // `window.axios.post('admin/kds-order/change-status/...')` directly,
    // so the in-method `_debouncedRefresh()` never runs. A response
    // interceptor catches BOTH paths : it fires on every successful KDS
    // change-status POST regardless of caller (component method, raw axios,
    // future tooling). On 2xx we trigger an immediate refresh — bypassing
    // the 300ms debounce because this is a one-shot reaction, not a burst.
    try {
      var ax = typeof window !== 'undefined' && window.axios ? window.axios : null;
      if (ax && ax.interceptors && ax.interceptors.response) {
        this._kdsStatusInterceptorId = ax.interceptors.response.use(function (response) {
          try {
            var cfg = response && response.config;
            if (cfg && /^post$/i.test(cfg.method || '') && typeof cfg.url === 'string' && cfg.url.indexOf('admin/kds-order/change-status/') !== -1 && response.status >= 200 && response.status < 300) {
              // Direct refresh (no debounce) so the 09-kds-prete window
              // (~1500ms in the spec) catches the transition. Echo+method
              // paths still funnel through `_debouncedRefresh` elsewhere.
              _this._refreshWithCurrentFilter();
              _this.items();
            }
          } catch (_e) {/* defensive: never break the response chain */}
          return response;
        }, function (error) {
          return Promise.reject(error);
        });
      }
    } catch (_e) {/* defensive: interceptor is best-effort */}
    this._kdsWaitInterval = setInterval(function () {
      _this.waitTick += 1;
    }, 30000);
    // [F-03 / Lot 1.C] Adaptive polling fallback. Pauses automatically when
    // wsService.state === 'CONNECTED'; accelerates when degraded; backoffs on
    // 5xx. Safe to mount unconditionally — the service no-ops while WS is up.
    this.kdsSyncUnsubscribers.push(_services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__["default"].on('sync', function (_ref) {
      var _ref$gatedIds = _ref.gatedIds,
        gatedIds = _ref$gatedIds === void 0 ? [] : _ref$gatedIds,
        _ref$orders = _ref.orders,
        orders = _ref$orders === void 0 ? [] : _ref$orders,
        _ref$deleted_ids = _ref.deleted_ids,
        deleted_ids = _ref$deleted_ids === void 0 ? [] : _ref$deleted_ids;
      var hasFreshOrders = orders.some(function (o) {
        return !gatedIds.includes(o.id);
      });
      var hasDeletes = (deleted_ids || []).length > 0;
      _this.syncNowTick = Date.now();
      if (hasFreshOrders || hasDeletes) {
        _this._debouncedRefresh && _this._debouncedRefresh();
      }
    }));
    this.kdsSyncUnsubscribers.push(_services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__["default"].on('error', function () {
      _this.syncNowTick = Date.now();
    }));
    try {
      _services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__["default"].start(this.authBranchId());
    } catch (e) {/* defensive: never break KDS mount because of poller */}
    this._kdsSyncStampTimer = setInterval(function () {
      _this.syncNowTick = Date.now();
    }, 1000);
  },
  methods: {
    // [2026-05-18 PR-C T2 reframe heal] JS-side filter for OrderStatusChanged.
    // Mirrors `KitchenReleaseRule::visibleStatuses` (ACCEPT / PREPARING /
    // PREPARED). A status change affects the KDS board when EITHER the
    // previous OR the next status is in that set:
    //   - Entry transitions (PENDING→ACCEPT, ACCEPT→PREPARING, etc.)
    //   - Exit transitions  (PREPARED→DELIVERED, ACCEPT→CANCELED)
    //   - Same-board mutations (ACCEPT→PREPARING bump)
    // Pure transitions outside the KDS board (e.g. DELIVERED→REFUNDED,
    // CANCELED→RETURNED) no longer trigger an unnecessary debounced refresh.
    _statusChangeAffectsKds: function _statusChangeAffectsKds(parsed) {
      var KDS_VISIBLE = [4, 7, 8]; // ACCEPT, PREPARING, PREPARED (mirror KitchenReleaseRule)
      var payload = parsed && parsed.payload ? parsed.payload : parsed || {};
      var oldStatus = Number(payload.old_status);
      var newStatus = Number(payload.new_status);
      // Missing payload (legacy / unparsed event) → fall back to refresh
      // rather than risk swallowing a real transition.
      if (!Number.isFinite(oldStatus) && !Number.isFinite(newStatus)) {
        return true;
      }
      return KDS_VISIBLE.includes(oldStatus) || KDS_VISIBLE.includes(newStatus);
    },
    // [Sprint 2A DEL-3 2026-05-16] Legacy template delivery-block helpers.
    // Mirror the V2 KdsOrderCard `isDeliveryOrder` + `deliveryAddressLine`
    // computed logic for the rollback path (?v2=0 / kds.v2_enabled='0').
    // Source-truth precedence identical to V2 to avoid drift between the
    // two UIs the kitchen could switch between in a single shift.
    kdsLegacyShouldShowDelivery: function kdsLegacyShouldShowDelivery(order) {
      if (!order) {
        return false;
      }
      var surface = String(order.source_surface || '').toLowerCase();
      if (surface === 'delivery') {
        return true;
      }
      // OrderType::DELIVERY === 5 (app/Enums/OrderType.php).
      return Number(order.order_type) === 5;
    },
    kdsLegacyDeliveryAddress: function kdsLegacyDeliveryAddress(order) {
      var a = order && order.order_address;
      if (!a) {
        return '';
      }
      var parts = [];
      if (a.apartment) {
        parts.push(a.apartment);
      }
      if (a.address) {
        parts.push(a.address);
      }
      return parts.join(' — ');
    },
    // [kds/sprint-2 V-5] V2 grid event handlers — both delegate to the same
    // store action `kitchenDisplaySystemOrder/changeStatus` used by the
    // legacy 4-column layout. KDSOrderStateMachine on the server stays the
    // single source of truth (apply() lockForUpdate + idempotent early-return).
    onV2ChangeStatus: function onV2ChangeStatus(event) {
      var _this2 = this;
      // event: { orderId, status }
      var order = (this.orders || []).find(function (o) {
        return o.id === event.orderId;
      });
      if (!order) {
        return;
      }
      var payload = (0,_store_modules_kds__WEBPACK_IMPORTED_MODULE_13__.kdsStatusPayload)(order, event.status);
      this.$store.dispatch('kitchenDisplaySystemOrder/changeStatus', _objectSpread({}, payload)).then(function () {
        _this2.kdsAnnounceTransition(order, event.status);
        _this2._debouncedRefresh();
        window.dispatchEvent(new CustomEvent('realtime-order-update', {
          detail: {
            type: 'status-change',
            order_id: payload.id,
            status: event.status
          }
        }));
      })["catch"](function (err) {
        var _err$response;
        // Conflict (409) → silent refresh; other errors surface to existing
        // error-banner flow.
        if ((err === null || err === void 0 || (_err$response = err.response) === null || _err$response === void 0 ? void 0 : _err$response.status) === 409) {
          _this2._debouncedRefresh();
          return;
        }
        if (_this2.kdsErrorBanner) {
          _this2.kdsErrorBanner.visible = true;
          _this2.kdsErrorBanner.message = _this2.$t('label.kds_status_conflict');
        }
      });
    },
    onV2AutoPromote: function onV2AutoPromote(orderId) {
      // Auto-transition ACCEPT → PREPARING — same path as a manual chef bump.
      var order = (this.orders || []).find(function (o) {
        return o.id === orderId;
      });
      if (!order) {
        return;
      }
      this.onV2ChangeStatus({
        orderId: orderId,
        status: _helpers_kdsState__WEBPACK_IMPORTED_MODULE_17__.ORDER_STATUS.PREPARING
      });
    },
    authBranchId: function authBranchId() {
      var _this$$store$state;
      var candidates = [this.$store.getters['auth/authBranchId'], this.$store.getters.authBranchId, (_this$$store$state = this.$store.state) === null || _this$$store$state === void 0 || (_this$$store$state = _this$$store$state.auth) === null || _this$$store$state === void 0 ? void 0 : _this$$store$state.authBranchId];
      for (var _i = 0, _candidates = candidates; _i < _candidates.length; _i++) {
        var candidate = _candidates[_i];
        if (candidate === '' || candidate === null || typeof candidate === 'undefined') {
          continue;
        }
        var value = parseInt(candidate, 10);
        if (Number.isFinite(value)) {
          return value;
        }
      }
      return 0;
    },
    kdsInstructionClass: function kdsInstructionClass(text) {
      return (0,_helpers_kdsLineSemantics__WEBPACK_IMPORTED_MODULE_15__.kdsInstructionVisualClass)(text);
    },
    isPaymentPendingCounter: function isPaymentPendingCounter(order) {
      return (order === null || order === void 0 ? void 0 : order.payment_pending_counter) === true || parseInt(order === null || order === void 0 ? void 0 : order.payment_status, 10) === _enums_modules_paymentStatusEnum__WEBPACK_IMPORTED_MODULE_4__["default"].PENDING_COUNTER;
    },
    // [Lot 2.I / G-4] Thin wrappers over pure helpers in kdsAllergens.js so
    // they remain unit-testable in isolation (see tests/js/kdsAllergens.spec.js).
    orderHasAllergens: function orderHasAllergens(order) {
      return (0,_helpers_kdsAllergens__WEBPACK_IMPORTED_MODULE_16__.orderHasAllergens)(order);
    },
    sortedAllergens: function sortedAllergens(snapshot) {
      return (0,_helpers_kdsAllergens__WEBPACK_IMPORTED_MODULE_16__.sortedAllergens)(snapshot);
    },
    openAllergensModal: function openAllergensModal(order) {
      var _this3 = this;
      this.allergenModalReturnFocus = typeof document !== "undefined" && document.activeElement ? document.activeElement : null;
      this.allergensModal = {
        open: true,
        order: order
      };
      this.$nextTick(function () {
        var el = _this3.$refs.allergensModalCloseButton;
        if (el && typeof el.focus === "function") {
          try {
            el.focus();
          } catch (_) {/* defensive: focus is best-effort */}
        }
      });
    },
    closeAllergensModal: function closeAllergensModal() {
      var returnTo = this.allergenModalReturnFocus;
      this.allergensModal = {
        open: false,
        order: null
      };
      this.allergenModalReturnFocus = null;
      this.$nextTick(function () {
        if (returnTo && typeof returnTo.focus === "function") {
          try {
            returnTo.focus();
          } catch (_) {/* best-effort */}
        }
      });
    },
    // [Audit 2.I F-01] Keep Tab / Shift+Tab inside the dialog (simple 2-node cycle).
    onAllergensModalKeydown: function onAllergensModalKeydown(e) {
      if (e.key !== "Tab") {
        return;
      }
      var root = this.$refs.allergensModalRoot;
      var content = root && root.querySelector && root.querySelector(".kds-allergens-modal-content");
      if (!content) {
        return;
      }
      var focusables = Array.from(content.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (n) {
        return n.offsetParent !== null || n === document.activeElement;
      });
      // One focusable (e.g. only Close): keep Tab from moving focus out of the dialog.
      if (focusables.length < 2) {
        e.preventDefault();
        return;
      }
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else if (document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    },
    dismissKdsBumpNotice: function dismissKdsBumpNotice() {
      try {
        localStorage.setItem("kds.hide_bump_info", "1");
      } catch (e) {
        /* ignore */
      }
      this.kdsHideBumpInfo = true;
    },
    kdsWaitClass: function kdsWaitClass(order) {
      void this.waitTick;
      var ms = (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.parseOrderCreatedMs)(order);
      return (0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.getKdsEscalationClass)(ms, Date.now());
    },
    kdsDisplayDateTime: function kdsDisplayDateTime(value) {
      var raw = String(value || "").trim();
      var match = raw.match(/^(\d{1,2}):(\d{2})\s*(AM|PM),?\s*(.*)$/i);
      if (!match) {
        return raw.replace(/\s*\b(AM|PM)\b/gi, "").trim();
      }
      var hour = parseInt(match[1], 10);
      var minute = match[2];
      var marker = match[3].toUpperCase();
      var suffix = match[4] ? ", ".concat(match[4]) : "";
      if (marker === "PM" && hour < 12) hour += 12;
      if (marker === "AM" && hour === 12) hour = 0;
      return "".concat(String(hour).padStart(2, "0"), ":").concat(minute).concat(suffix);
    },
    orderStatusBadgeClasses: function orderStatusBadgeClasses(status) {
      return status === this.enums.orderStatusEnum.PREPARED ? "bg-[#166534] text-white" : status === this.enums.orderStatusEnum.ACCEPT ? "bg-[#4C1A96] text-white" : "bg-[#92400E] text-white";
    },
    kdsAuthUserId: function kdsAuthUserId() {
      var u = this.$store.getters["auth/authInfo"] || {};
      var id = u.id != null ? parseInt(u.id, 10) : 0;
      return Number.isFinite(id) && id > 0 ? id : 0;
    },
    persistKdsUiPrefs: function persistKdsUiPrefs() {
      try {
        localStorage.setItem((0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.kdsStationFilterStorageKey)(this.kdsAuthUserId()), this.stationFilter);
      } catch (e) {
        /* private mode / quota */
      }
      localStorage.setItem("kds.group_by_table", this.groupByTable ? "1" : "0");
      localStorage.setItem("kds.sound_enabled", this.soundEnabled ? "1" : "0");
      localStorage.setItem("kds.sound_volume", String(this.soundVolume));
    },
    playKdsNewOrderSound: function playKdsNewOrderSound() {
      if (!this.soundEnabled) {
        return;
      }
      // [Lot 2.C / F-07] Throttle: burst of new orders (WS) must not machine-gun the chime.
      var now = Date.now();
      if (!(0,_helpers_kdsDisplay__WEBPACK_IMPORTED_MODULE_14__.shouldPlayKdsNewOrderSound)(this._kdsLastNewOrderSoundAt, now)) {
        return;
      }
      this._kdsLastNewOrderSoundAt = now;
      var el = this.$refs.kdsNewOrderAudio;
      if (!el) {
        return;
      }
      el.volume = Math.min(1, Math.max(0, this.soundVolume / 100));
      el.currentTime = 0;
      el.play()["catch"](function () {});
    },
    isTableGroupOpen: function isTableGroupOpen(key) {
      return this.expandedTableGroups[key] !== false;
    },
    toggleTableGroup: function toggleTableGroup(key) {
      var cur = this.isTableGroupOpen(key);
      this.expandedTableGroups = _objectSpread(_objectSpread({}, this.expandedTableGroups), {}, _defineProperty({}, key, !cur));
    },
    dineinTableKey: function dineinTableKey(order) {
      return order.table_name && String(order.table_name).trim() || "—";
    },
    kdsDineinTableHeaderVisible: function kdsDineinTableHeaderVisible(order, idx) {
      if (!this.groupByTable) {
        return false;
      }
      var list = this.sortedFilteredDinein;
      var key = function key(o) {
        return o.table_name && String(o.table_name).trim() || "—";
      };
      if (idx === 0) {
        return true;
      }
      return key(order) !== key(list[idx - 1]);
    },
    kdsIsBumped: function kdsIsBumped(orderId, itemId) {
      return this.$store.getters["kds/bumpTimestamp"](orderId, itemId) != null;
    },
    kdsCanRecall: function kdsCanRecall(orderId, itemId) {
      var ts = this.$store.getters["kds/bumpTimestamp"](orderId, itemId);
      if (ts == null) {
        return false;
      }
      return Date.now() - ts < 60000;
    },
    kdsBump: function kdsBump(order, item) {
      var _this4 = this;
      this.$store.dispatch("kds/bumpItem", {
        orderId: order.id,
        itemId: item.id
      });
      this.$nextTick(function () {
        if (_this4.$store.getters["kds/isReadyOrder"](order)) {
          if (order.status !== _this4.enums.orderStatusEnum.PREPARED) {
            var nextStatus = order.status === _this4.enums.orderStatusEnum.ACCEPT ? _this4.enums.orderStatusEnum.PREPARING : _this4.enums.orderStatusEnum.PREPARED;
            _this4.orderStatus(order, nextStatus);
          }
        }
      });
    },
    kdsRecall: function kdsRecall(order, item) {
      var _this5 = this;
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var r;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.n) {
            case 0:
              _context.n = 1;
              return _this5.$store.dispatch("kds/recallItem", {
                orderId: order.id,
                itemId: item.id
              });
            case 1:
              r = _context.v;
              if (r && r.ok === false && r.reason === "grace_expired") {
                _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].error(_this5.$t("message.kds_recall_grace_expired"));
              }
            case 2:
              return _context.a(2);
          }
        }, _callee);
      }))();
    },
    _bindWsService: function _bindWsService() {
      var _this6 = this;
      var ws = window._wsService;
      if (!ws) return;
      this._onWsConnected = function () {
        _this6.wsConnected = true;
        _this6.refreshOrderList();
        _this6._restartPolling();
      };
      this._onWsDisconnected = function () {
        _this6.wsConnected = false;
        _this6._restartPolling();
      };
      ws.on('connected', this._onWsConnected);
      ws.on('disconnected', this._onWsDisconnected);
    },
    _unbindWsService: function _unbindWsService() {
      var ws = window._wsService;
      if (!ws) return;
      if (this._onWsConnected) ws.off('connected', this._onWsConnected);
      if (this._onWsDisconnected) ws.off('disconnected', this._onWsDisconnected);
    },
    _pollingInterval: function _pollingInterval() {
      // [HEAL B.3 2026-05-19] KDS polling cadence is intentionally hardcoded
      // per-surface (not config-driven). 5000ms when WS down protects the
      // kitchen-staleness budget (orders must surface within ~5s of payment).
      // 60000ms when WS up because Echo handles the live push and polling
      // becomes a passive sanity-check. NOT a config-read miss — see
      // config/broadcasting.php for the per-surface SoT note (RED-Z3 §B-6).
      return this.wsConnected ? 60000 : 5000;
    },
    _restartPolling: function _restartPolling() {
      this.stopAutoRefresh();
      this.startAutoRefresh();
    },
    startAutoRefresh: function startAutoRefresh() {
      var _this7 = this;
      if (this.$route.path.includes('kitchen-display-system')) {
        this.autoRefreshInterval = setInterval(function () {
          _this7.refreshOrderList();
        }, this._pollingInterval());
      }
    },
    // [P4-1] Subscribe to branch Echo channel for real-time order updates.
    // Admin users (branch_id=0) rely on polling; branch staff get sub-second push.
    subscribeEcho: function subscribeEcho() {
      var _this8 = this;
      if (!window.Echo) return;
      var branchId = this.authBranchId();
      if (branchId <= 0) return; // Admin: polling fallback is sufficient
      // [AUDIT-P51-BUG2] Always unsubscribe first to prevent duplicate listeners on re-mount
      this.unsubscribeEcho();
      try {
        this._eventSub = (0,_services_eventContract__WEBPACK_IMPORTED_MODULE_9__.onEvents)(branchId, [
        // [2026-05-18 PR-C T2 reframe heal] Filter OrderStatusChanged JS-side:
        // refresh only when from OR to is in the KDS-visible status set
        // (ACCEPT=4 / PREPARING=7 / PREPARED=8 — mirror KitchenReleaseRule).
        // Before this guard, every status flip (DELIVERED→REFUNDED, CANCELED,
        // RETURNED, etc.) triggered a debounced full refresh even though
        // none of those affect the KDS board → wasted backend calls + flicker.
        {
          broadcastAs: 'OrderStatusChanged',
          handler: function handler(parsed) {
            if (_this8._statusChangeAffectsKds(parsed)) {
              _this8._debouncedRefresh();
            }
          }
        }, {
          broadcastAs: 'OrderCreated',
          handler: function handler() {
            _this8._debouncedRefresh();
          }
        }, {
          broadcastAs: 'OrderPaidAtCounter',
          handler: function handler() {
            _this8._debouncedRefresh();
          }
        },
        // [SYNC-001 + CV1-KDS-INFLIGHT-OOS-MARKER-001] KDS now also receives
        // ItemAvailabilityChanged so the station can flag in-flight tickets
        // that include a freshly 86'd item (rupture stock cuisine).
        // _onItemAvailabilityChanged dispatches into kdsInflight Vuex module
        // (lazy-purged 10min TTL), then triggers a debounced refresh.
        {
          broadcastAs: 'ItemAvailabilityChanged',
          handler: function handler(parsed) {
            _this8._onItemAvailabilityChanged(parsed);
          }
        },
        // [F-02] Floor-plan transfer / occupy → update the table label in place
        // and flash the card briefly. Refresh re-fetches table_name from the
        // backend; flash provides the visual cue (gate G-2 decision).
        {
          broadcastAs: 'OrderTableChanged',
          handler: function handler(payload) {
            _this8._handleTableChanged(payload);
          }
        }]);
        // [P13_LOG_HYGIENE] console.log(`[KDS] Echo subscribed to branch.${branchId}`);
      } catch (e) {
        // Echo not available or auth failed — polling fallback handles it
        console.warn('[KDS] Echo subscription failed:', e.message);
      }
    },
    unsubscribeEcho: function unsubscribeEcho() {
      var branchId = this.authBranchId();
      if (branchId <= 0) return;
      try {
        var _this$_eventSub;
        (_this$_eventSub = this._eventSub) === null || _this$_eventSub === void 0 || _this$_eventSub.unsubscribe();
        // [P13_LOG_HYGIENE] console.log(`[KDS] Echo unsubscribed from branch.${branchId}`);
      } catch (e) {
        console.warn('[KDS] Echo unsubscribe error:', e.message);
      }
      this._eventSub = null;
    },
    refreshOrderList: function refreshOrderList() {
      this.items();
      // [FIX-54-5] Preserve current filter — use _refreshWithCurrentFilter instead of list()
      // to avoid falsy value (0) being treated as "no filter" and resetting the status
      this._refreshWithCurrentFilter();
    },
    _isVisibleInCurrentBoard: function _isVisibleInCurrentBoard(item) {
      var selectedStatus = Number(this.props.search.status || 0);
      if (selectedStatus > 0) {
        return true;
      }
      // [iter15-mega-fix C-022 run-3 2026-05-10] Previously this filter
      // excluded PREPARED orders from the default "Toutes Les Commandes"
      // board. That contradicted the customer-facing reality: the
      // order-status-screen still announces "Prêt: A0024" so the customer
      // comes back to the counter, but the chef no longer saw the card on
      // the default KDS surface and had no way to confirm the hand-over.
      // The PREPARED visual marker already exists — `orderStatusBadgeClasses`
      // paints PREPARED cards green (#166534 / "Prête"), and the
      // ACCEPT/PREPARING action CTAs are status-conditional so PREPARED
      // cards naturally show no action button. Showing the card on the
      // default board is therefore non-disruptive and restores
      // kitchen ↔ customer-screen parity. The dedicated "Prêtes" filter
      // button (selectedStatus > 0 branch above) keeps its narrow view.
      return true;
    },
    _applyOrderBuckets: function _applyOrderBuckets(rows) {
      var _this9 = this;
      var visibleRows = (Array.isArray(rows) ? rows : []).filter(function (item) {
        return _this9._isVisibleInCurrentBoard(item);
      });
      // [test-e2e fix E-003 round-3] V1 dine-in disabled — kiosk orders are TAKEAWAY
      // (OrderRequest:200 enforces order_type=TAKEAWAY for ALL kiosk orders since
      // dine-in is gated off via feature flag pos.dine_in_enabled=false). Therefore
      // bucket by source_surface ('kiosk') first, falling back to order_type for
      // historical rows that pre-date the source_surface column. Without this fix
      // the "🖥️ Borne" KDS column is permanently empty in V1.
      var isKioskSource = function isKioskSource(item) {
        var surface = typeof item.source_surface === 'string' ? item.source_surface.toLowerCase() : '';
        if (surface === 'kiosk') return true;
        // Legacy fallback: orders created before source_surface column existed and
        // marked as KIOSK type still bucket as kiosk.
        if (!surface && item.order_type === _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__["default"].KIOSK) return true;
        return false;
      };
      this.kioskOrders = visibleRows.filter(isKioskSource);
      // Non-kiosk rows fan out across the POS / online / dine-in lanes by order_type.
      var nonKioskRows = visibleRows.filter(function (item) {
        return !isKioskSource(item);
      });
      this.dineinOrders = nonKioskRows.filter(function (item) {
        return item.order_type === _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__["default"].DINING_TABLE;
      });
      this.onlineOrders = nonKioskRows.filter(function (item) {
        return item.order_type === _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__["default"].DELIVERY;
      });
      // POS (caisse) orders follow the same kitchen lane as takeaway.
      this.takeawayOrders = nonKioskRows.filter(function (item) {
        return item.order_type === _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__["default"].TAKEAWAY || item.order_type === _enums_modules_orderTypeEnum__WEBPACK_IMPORTED_MODULE_1__["default"].POS;
      });
    },
    _refreshWithCurrentFilter: function _refreshWithCurrentFilter() {
      var _this0 = this;
      // [FIX-54-5] Re-fetch orders without modifying props.search.status
      // This ensures the current filter (e.g., status=7 PREPARING) is preserved
      // even when the value is 0 (ACCEPT) which is falsy in JavaScript
      this.loading.isActive = true;
      this.$store.dispatch("kitchenDisplaySystemOrder/lists", this.props.search).then(function (res) {
        var _res$data, _res$data2;
        _this0._applyOrderBuckets((res === null || res === void 0 || (_res$data = res.data) === null || _res$data === void 0 ? void 0 : _res$data.data) || []);
        _this0.kdsOverflowDetected = (res === null || res === void 0 || (_res$data2 = res.data) === null || _res$data2 === void 0 || (_res$data2 = _res$data2.meta) === null || _res$data2 === void 0 ? void 0 : _res$data2.overflow) === true;
        _this0.loading.isActive = false;
        _this0._kdsOrdersHydrated = true;
        // [test-e2e round-2 C-001] Successful poll → dismiss the persistent
        // error banner (kitchen connection restored).
        _this0._clearKdsErrorBanner();
      })["catch"](function (err) {
        _this0.loading.isActive = false;
        // [test-e2e round-2 C-001] Persistent banner instead of ephemeral
        // toast — kitchen operator at 1m+ glance must NOT miss this.
        _this0._raiseKdsErrorBanner(err);
      });
    },
    openFilterSlide: function openFilterSlide(event) {
      return _services_appService__WEBPACK_IMPORTED_MODULE_8__["default"].openFilterSlide(event);
    },
    closeFilterSlide: function closeFilterSlide(event) {
      return _services_appService__WEBPACK_IMPORTED_MODULE_8__["default"].closeFilterSlide(event);
    },
    stopAutoRefresh: function stopAutoRefresh() {
      if (this.autoRefreshInterval) {
        clearInterval(this.autoRefreshInterval);
        this.autoRefreshInterval = null;
      }
    },
    list: function list() {
      var _this1 = this;
      var status = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : "";
      if (status) {
        this.props.search.status = status;
      } else {
        this.props.search.status = "";
      }
      this.loading.isActive = true;
      this.$store.dispatch("kitchenDisplaySystemOrder/lists", this.props.search).then(function (res) {
        var _res$data3, _res$data4;
        _this1._applyOrderBuckets((res === null || res === void 0 || (_res$data3 = res.data) === null || _res$data3 === void 0 ? void 0 : _res$data3.data) || []);
        _this1.kdsOverflowDetected = (res === null || res === void 0 || (_res$data4 = res.data) === null || _res$data4 === void 0 || (_res$data4 = _res$data4.meta) === null || _res$data4 === void 0 ? void 0 : _res$data4.overflow) === true;
        _this1.loading.isActive = false;
        _this1._kdsOrdersHydrated = true;
        // [test-e2e round-2 C-001] Auto-dismiss the persistent banner
        // on next successful /api/admin/kds-order response.
        _this1._clearKdsErrorBanner();
      })["catch"](function (err) {
        _this1.loading.isActive = false;
        // [test-e2e round-2 C-001] Persistent banner instead of ephemeral
        // toast — see _raiseKdsErrorBanner / kdsErrorBanner data prop.
        _this1._raiseKdsErrorBanner(err);
      });
    },
    _raiseKdsErrorBanner: function _raiseKdsErrorBanner(err) {
      var _err$response2;
      // Build a copy that flags the kind of failure (network/5xx vs 4xx).
      var status = Number((err === null || err === void 0 || (_err$response2 = err.response) === null || _err$response2 === void 0 ? void 0 : _err$response2.status) || 0);
      var fallback = this.$t('error.kds_connection_lost');
      // For server-side messages (e.g. 422 validation), prefer the message
      // payload — but for 5xx / network drops, the standardized banner copy
      // is far clearer to a kitchen operator than a stack trace.
      var message;
      if (!status || status >= 500 || status === 408 || status === 425 || status === 429) {
        message = fallback;
      } else {
        var _err$response3;
        message = (err === null || err === void 0 || (_err$response3 = err.response) === null || _err$response3 === void 0 || (_err$response3 = _err$response3.data) === null || _err$response3 === void 0 ? void 0 : _err$response3.message) || fallback;
      }
      this.kdsErrorBanner = {
        visible: true,
        message: message,
        lastRetryAt: Date.now()
      };
    },
    _clearKdsErrorBanner: function _clearKdsErrorBanner() {
      if (this.kdsErrorBanner.visible) {
        this.kdsErrorBanner = {
          visible: false,
          message: '',
          lastRetryAt: null
        };
      }
    },
    dismissKdsErrorBanner: function dismissKdsErrorBanner() {
      // Manual ✕ dismiss. Next successful poll will keep it hidden anyway,
      // and the next failure will re-raise it.
      this._clearKdsErrorBanner();
    },
    items: function items() {
      var _this10 = this;
      this.loading.isActive = true;
      this.$store.dispatch("kitchenDisplaySystemOrder/orderItems").then(function (res) {
        _this10.loading.isActive = false;
      })["catch"](function (err) {
        var _err$response4;
        _this10.loading.isActive = false;
        _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].error((err === null || err === void 0 || (_err$response4 = err.response) === null || _err$response4 === void 0 || (_err$response4 = _err$response4.data) === null || _err$response4 === void 0 ? void 0 : _err$response4.message) || _this10.$t('message.something_wrong'));
      });
    },
    openSidebar: function openSidebar() {
      var _document, _document2;
      (_document = document) === null || _document === void 0 || (_document = _document.querySelector(".db-main")) === null || _document === void 0 || (_document = _document.classList) === null || _document === void 0 || _document.remove("expand");
      var activeMenu = document.querySelector('.db-sidebar-nav-item.active');
      if (activeMenu) {
        activeMenu.classList.remove('active');
      }
      (_document2 = document) === null || _document2 === void 0 || (_document2 = _document2.querySelector('.router-link-exact-active')) === null || _document2 === void 0 || (_document2 = _document2.parentElement) === null || _document2 === void 0 || (_document2 = _document2.classList) === null || _document2 === void 0 || _document2.add('active');
    },
    closeSidebar: function closeSidebar() {
      var _document3;
      (_document3 = document) === null || _document3 === void 0 || (_document3 = _document3.querySelector(".db-main")) === null || _document3 === void 0 || (_document3 = _document3.classList) === null || _document3 === void 0 || _document3.add("expand");
    },
    search: function search() {
      if (typeof this.props.search.order_serial_no !== "undefined" && this.props.search.order_serial_no !== "") {
        this.list();
      } else {
        this.list();
      }
    },
    searchReset: function searchReset() {
      this.props.search.order_serial_no = "";
      this.list();
    },
    kdsOverflowSeeMore: function kdsOverflowSeeMore() {
      this.props.search.order_serial_no = "";
      this.props.search.status = "";
      this.list();
    },
    // [AUDIT-P47-BUG4] Escape HTML to prevent XSS when printing kitchen tickets.
    // Order data comes from DB but could be poisoned if an admin account was compromised.
    escapeHtml: function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    },
    kdsAddonDisplayName: function kdsAddonDisplayName(addon) {
      if (!addon || _typeof(addon) !== 'object') {
        return '';
      }
      return addon.addon_name || addon.addon_item_name || addon.name || addon.item_name || 'Addon';
    },
    // [AUDIT-P2] Print a kitchen ticket for a given order using a hidden iframe.
    // Opens a minimal print window with order ref, items, variations, extras, addons, and instructions.
    // No external library needed — uses native window.print() on an isolated document.
    // [AUDIT-P47-BUG4] All dynamic values escaped to prevent stored XSS.
    printKitchenTicket: function printKitchenTicket(order) {
      var _this11 = this;
      var e = this.escapeHtml.bind(this);
      var lines = [];
      var orderLabel = e(order.order_serial_no) || '#' + e(order.id);
      var queueLabel = order.queue_number ? " \u2014 N\xB0".concat(e(order.queue_number)) : '';
      var typeLabel = _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty({}, this.enums.orderTypeEnum.DINING_TABLE, this.$t('label.kds_type_dinein')), this.enums.orderTypeEnum.DELIVERY, this.$t('label.kds_type_delivery')), this.enums.orderTypeEnum.TAKEAWAY, this.$t('label.kds_type_takeaway')), this.enums.orderTypeEnum.POS, this.$t('label.kds_type_pos')), this.enums.orderTypeEnum.KIOSK, this.$t('label.kds_type_kiosk'))[order.order_type] || '';
      lines.push("<h2 style=\"margin:0 0 6px;font-size:18px;\">".concat(orderLabel).concat(queueLabel, "</h2>"));
      if (typeLabel) lines.push("<p style=\"margin:0 0 4px;font-size:13px;color:#555;\">".concat(typeLabel, "</p>"));
      if (order.order_datetime) lines.push("<p style=\"margin:0 0 10px;font-size:12px;color:#888;\">".concat(e(order.order_datetime), "</p>"));
      lines.push('<hr style="border:none;border-top:1px dashed #ccc;margin:8px 0;">');
      (order.order_items || []).forEach(function (item) {
        lines.push("<div style=\"margin-bottom:10px;\">");
        lines.push("<strong style=\"font-size:15px;\">".concat(item.quantity, "\xD7 ").concat(e(item.item_name), "</strong>"));
        if (Array.isArray(item.item_variations) && item.item_variations.length > 0) {
          var vars = item.item_variations.map(function (v) {
            return "".concat(e(v.variation_name), ": ").concat(e(v.name));
          }).join(' | ');
          lines.push("<div style=\"font-size:12px;color:#444;margin-top:2px;\">".concat(vars, "</div>"));
        }
        if (Array.isArray(item.item_extras) && item.item_extras.length > 0) {
          var extras = item.item_extras.map(function (ex) {
            return e(ex.name);
          }).join(', ');
          lines.push("<div style=\"font-size:12px;color:#444;margin-top:2px;\">+ ".concat(extras, "</div>"));
        }
        if (Array.isArray(item.item_addons) && item.item_addons.length > 0) {
          var addons = item.item_addons.map(function (addon) {
            var qty = Number(addon.quantity || 1);
            var name = e(_this11.kdsAddonDisplayName(addon));
            return qty > 1 ? "".concat(name, " \xD7").concat(qty) : name;
          }).join(', ');
          lines.push("<div style=\"font-size:12px;color:#444;margin-top:2px;\">+ ".concat(addons, "</div>"));
        }
        if (item.instruction) {
          var vis = (0,_helpers_kdsLineSemantics__WEBPACK_IMPORTED_MODULE_15__.kdsInstructionVisualClass)(item.instruction);
          var instStyle = "font-size:11px;color:#666;margin-top:3px;white-space:pre-line";
          if (vis === "kds-instruction--allergen") {
            instStyle = "font-size:11px;color:#7f1d1d;background:#fef2f2;border:1px solid #fecaca;padding:4px 6px;border-radius:4px;margin-top:3px;white-space:pre-line;font-weight:600";
          } else if (vis === "kds-instruction--exclusion") {
            instStyle = "font-size:11px;color:#7c2d12;background:#fff7ed;border:1px solid #fdba74;padding:4px 6px;border-radius:4px;margin-top:3px;white-space:pre-line";
          }
          lines.push("<div style=\"".concat(instStyle, "\">").concat(e(item.instruction), "</div>"));
        }
        lines.push('</div>');
      });
      lines.push('<hr style="border:none;border-top:1px dashed #ccc;margin:8px 0;">');
      lines.push('<p style="font-size:11px;color:#aaa;text-align:center;">FoodKing KDS</p>');
      var html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Ticket cuisine</title>\n        <style>body{font-family:monospace;padding:12px;max-width:300px;margin:0 auto;}</style>\n        </head><body>".concat(lines.join(''), "</body></html>");
      var win = window.open('', '_blank', 'width=320,height=600,toolbar=0,menubar=0');
      if (!win) return; // popup blocked
      win.document.write(html);
      win.document.close();
      win.focus();
      win.print();
      win.close();
    },
    orderStatus: function orderStatus(order, status) {
      var _this12 = this;
      try {
        this.loading.isActive = true;
        var payload = (0,_store_modules_kds__WEBPACK_IMPORTED_MODULE_13__.kdsStatusPayload)(order, status);
        this.$store.dispatch("kitchenDisplaySystemOrder/changeStatus", _objectSpread({}, payload)).then(function (res) {
          _this12.loading.isActive = false;
          _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].successFlip(1, _this12.$t("label.status"));
          // [CV1-KDS-A11Y-RICH-001] Announce the transition to screen readers
          // via the aria-live region (does NOT steal focus, polite politeness).
          _this12.kdsAnnounceTransition(order, status);
          // [AUDIT-P49-BUG7] Debounce refresh: list() triggers items update via store,
          // and Echo broadcast also triggers refresh. Use debounce to prevent triple API calls.
          _this12._debouncedRefresh();
          // Propager le changement de statut à tous les composants qui écoutent (OSS, autres KDS)
          window.dispatchEvent(new CustomEvent('realtime-order-update', {
            detail: {
              type: 'status-change',
              order_id: payload.id,
              status: status
            }
          }));
        })["catch"](function (err) {
          var _err$response5, _err$response6;
          _this12.loading.isActive = false;
          if ((err === null || err === void 0 || (_err$response5 = err.response) === null || _err$response5 === void 0 ? void 0 : _err$response5.status) === 409) {
            _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].error(_this12.$t("message.kds_status_conflict"));
            _this12._debouncedRefresh();
            return;
          }
          // [AUDIT-P47-BUG7] Null-safe guard — err.response is undefined on network timeout
          var msg = (err === null || err === void 0 || (_err$response6 = err.response) === null || _err$response6 === void 0 || (_err$response6 = _err$response6.data) === null || _err$response6 === void 0 ? void 0 : _err$response6.message) || (err === null || err === void 0 ? void 0 : err.message) || 'Erreur réseau';
          _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].error(msg);
        });
      } catch (err) {
        var _err$response7;
        this.loading.isActive = false;
        // [AUDIT-P47-BUG7] Null-safe guard — err.response is undefined on network timeout
        var msg = (err === null || err === void 0 || (_err$response7 = err.response) === null || _err$response7 === void 0 || (_err$response7 = _err$response7.data) === null || _err$response7 === void 0 ? void 0 : _err$response7.message) || (err === null || err === void 0 ? void 0 : err.message) || 'Erreur réseau';
        _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].error(msg);
      }
    },
    /**
     * [CV1-KDS-INFLIGHT-OOS-MARKER-001] Handle ItemAvailabilityChanged events.
     *
     * Per RED-R3 F3 + RED-R4 KD11 :
     *   - Branch-scoped 86 (is_available === false): mark the item in
     *     `kdsInflight.recentlyDeavailable` so any in-flight ticket card
     *     that still contains this item gets a red OOS warning badge.
     *   - Item became available again (is_available === true): clear the
     *     flag so the badge disappears.
     *   - Other event shapes (global price/structural change): just refresh.
     *
     * The OOS marker is independent from the existing chime watcher (KD5).
     * Lazy TTL purge inside the Vuex getter ensures stale flags don't
     * outlive the rupture window (10 min). No timer leaks across remounts.
     */
    _onItemAvailabilityChanged: function _onItemAvailabilityChanged(parsed) {
      try {
        var _ref2, _payload$item_id, _ref3, _payload$is_available, _ref4, _payload$branch_id, _payload$reason;
        var payload = parsed && parsed.payload ? parsed.payload : {};
        var itemId = (_ref2 = (_payload$item_id = payload.item_id) !== null && _payload$item_id !== void 0 ? _payload$item_id : payload.itemId) !== null && _ref2 !== void 0 ? _ref2 : null;
        var isAvailable = (_ref3 = (_payload$is_available = payload.is_available) !== null && _payload$is_available !== void 0 ? _payload$is_available : payload.isAvailable) !== null && _ref3 !== void 0 ? _ref3 : null;
        var branchId = (_ref4 = (_payload$branch_id = payload.branch_id) !== null && _payload$branch_id !== void 0 ? _payload$branch_id : payload.branchId) !== null && _ref4 !== void 0 ? _ref4 : null;
        var reason = (_payload$reason = payload.reason) !== null && _payload$reason !== void 0 ? _payload$reason : null;
        if (itemId !== null && itemId !== undefined) {
          if (isAvailable === false) {
            this.$store.dispatch('kdsInflight/markDeavailable', {
              itemId: itemId,
              branchId: branchId,
              reason: reason,
              kdsBranchId: this.authBranchId()
            });
            // [iter15-mega-fix D-003 2026-05-10] Surface a visible warning toast
            // on the KDS surface in addition to the inflight badge — kitchen
            // staff with hands full + noise need an unmissable cue when an
            // item used by an in-preparation ticket goes 86. The aria-live
            // region (kds-aria-live) carries the same message for screen readers.
            try {
              var itemName = payload.name || payload.item_name || '#' + itemId;
              var label = reason ? "".concat(itemName, " indisponible \u2014 ").concat(reason) : "".concat(itemName, " indisponible");
              this.kdsAriaLiveMessage = label;
              _services_alertService__WEBPACK_IMPORTED_MODULE_7__["default"].warning(label);
            } catch (_e) {/* defensive */}
          } else if (isAvailable === true) {
            this.$store.dispatch('kdsInflight/clearItem', itemId);
          }
        }
      } catch (e) {
        // Defensive: never break KDS refresh because of a malformed payload.
        console.warn('[KDS] _onItemAvailabilityChanged failed:', e === null || e === void 0 ? void 0 : e.message);
      }
      this._debouncedRefresh();
    },
    /**
     * [CV1-KDS-INFLIGHT-OOS-MARKER-001] Card-level helper used by templates :
     * returns true if the given order is in_preparation (or accepted) AND
     * still references at least one item flagged in `kdsInflight`.
     * Cards in PREPARED state are intentionally NOT marked — the warning
     * is only useful while the cuisinier is still working on the ticket.
     */
    kdsHasOosWarning: function kdsHasOosWarning(order) {
      if (!order) return false;
      var status = Number(order.status);
      var isInflight = status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__["default"].ACCEPT || status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__["default"].PREPARING;
      if (!isInflight) return false;
      var getter = this.$store.getters['kdsInflight/orderHasRecentlyDeavailableItem'];
      return typeof getter === 'function' ? getter(order) : false;
    },
    /**
     * [CV1-KDS-A11Y-RICH-001] Update the polite aria-live region with a
     * one-line transition announcement. Screen readers pick it up without
     * stealing focus. Used from `orderStatus` after a successful change.
     * Falls back silently if the order has no serial number.
     */
    kdsAnnounceTransition: function kdsAnnounceTransition(order, status) {
      var _ref5,
        _order$order_serial_n,
        _this13 = this;
      if (!order) return;
      var serial = (_ref5 = (_order$order_serial_n = order.order_serial_no) !== null && _order$order_serial_n !== void 0 ? _order$order_serial_n : order.id) !== null && _ref5 !== void 0 ? _ref5 : '';
      if (serial === '' || serial === null || serial === undefined) return;
      var label = '';
      if (status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__["default"].PREPARING) {
        label = this.$t ? this.$t('label.kds_aria_live_preparing', {
          id: serial
        }) : "Order #".concat(serial, " preparing");
      } else if (status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__["default"].PREPARED) {
        label = this.$t ? this.$t('label.kds_aria_live_ready', {
          id: serial
        }) : "Order #".concat(serial, " ready");
      } else if (status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_3__["default"].ACCEPT) {
        label = this.$t ? this.$t('label.kds_aria_live_accepted', {
          id: serial
        }) : "Order #".concat(serial, " accepted");
      } else {
        label = "Order #".concat(serial);
      }
      // Vue reactivity : briefly clear before setting so identical successive
      // transitions still trigger an aria-live announcement.
      this.kdsAriaLiveMessage = '';
      this.$nextTick(function () {
        _this13.kdsAriaLiveMessage = label;
      });
    },
    /**
     * [F-02] Handle OrderTableChanged events.
     *
     * Per orchestrator gate G-2 (in_place_with_css_flash):
     *   - Refresh the list so the new table_name appears (SSOT = backend).
     *   - Mark the order id as "freshly moved" for ~2s so the card flashes.
     *   - NEVER replay a sound and NEVER trigger a re-print — this is a silent,
     *     visual-only signal that respects the kitchen rush flow.
     *
     * Idempotent: receiving the same payload twice resets the 2s timer.
     */
    _handleTableChanged: function _handleTableChanged(payload) {
      var _this14 = this;
      var orderId = parseInt((payload === null || payload === void 0 ? void 0 : payload.order_id) || 0);
      if (orderId <= 0) {
        this._debouncedRefresh();
        return;
      }
      this.flashTableChangeIds = _objectSpread(_objectSpread({}, this.flashTableChangeIds), {}, _defineProperty({}, orderId, true));
      if (this._tableFlashTimers[orderId]) {
        clearTimeout(this._tableFlashTimers[orderId]);
      }
      this._tableFlashTimers[orderId] = setTimeout(function () {
        var next = _objectSpread({}, _this14.flashTableChangeIds);
        delete next[orderId];
        _this14.flashTableChangeIds = next;
        delete _this14._tableFlashTimers[orderId];
      }, 2000);
      this._debouncedRefresh();
    },
    // [AUDIT-P49-BUG7] Debounced refresh: prevents simultaneous list()+items()+Echo refresh.
    _debouncedRefresh: function _debouncedRefresh() {
      var _this15 = this;
      if (this._refreshTimeout) {
        clearTimeout(this._refreshTimeout);
      }
      this._refreshTimeout = setTimeout(function () {
        _this15._refreshWithCurrentFilter();
        _this15.items();
      }, 300); // 300ms debounce — sufficient to absorb Echo broadcast + manual call
    },
    toggleFilter: function toggleFilter(index) {
      if (this.expandedFilter === index) {
        this.expandedFilter = null;
      } else {
        this.expandedFilter = index;
      }
    }
  },
  beforeUnmount: function beforeUnmount() {
    this.stopAutoRefresh();
    if (this._kdsWaitInterval) {
      clearInterval(this._kdsWaitInterval);
      this._kdsWaitInterval = null;
    }
    // [iter15-mega-fix C-017 2026-05-10] Eject the status-transition response
    // interceptor — leaks would re-fire `_refreshWithCurrentFilter()` on a
    // ghost component after the user navigates away from the KDS page.
    try {
      if (this._kdsStatusInterceptorId !== null && this._kdsStatusInterceptorId !== undefined && typeof window !== 'undefined' && window.axios && window.axios.interceptors && window.axios.interceptors.response) {
        window.axios.interceptors.response.eject(this._kdsStatusInterceptorId);
      }
    } catch (_e) {/* defensive */}
    this._kdsStatusInterceptorId = null;
    this.openSidebar();
    window.removeEventListener('realtime-order-update', this.refreshOrderList);
    this.unsubscribeEcho();
    this._unbindWsService();
    Object.values(this._tableFlashTimers || {}).forEach(function (t) {
      try {
        clearTimeout(t);
      } catch (e) {}
    });
    this._tableFlashTimers = {};
    // [F-03 / Lot 1.C] Tear down adaptive polling cleanly.
    try {
      _services_KdsSyncService__WEBPACK_IMPORTED_MODULE_10__["default"].stop();
    } catch (e) {/* ignore */}
    (this.kdsSyncUnsubscribers || []).forEach(function (u) {
      try {
        u && u();
      } catch (e) {}
    });
    this.kdsSyncUnsubscribers = [];
    if (this._kdsSyncStampTimer) {
      clearInterval(this._kdsSyncStampTimer);
      this._kdsSyncStampTimer = null;
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true"
/*!**************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true ***!
  \**************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-label", "data-order-id"];
var _hoisted_2 = {
  "class": "kds-card__meta"
};
var _hoisted_3 = ["title"];
var _hoisted_4 = {
  key: 1,
  "class": "kds-card__shortcut-spacer"
};
var _hoisted_5 = {
  "class": "kds-card__state-source"
};
var _hoisted_6 = {
  "class": "kds-card__source-label"
};
var _hoisted_7 = {
  key: 2,
  "class": "kds-card__allergen-pill",
  role: "alert",
  "aria-live": "assertive"
};
var _hoisted_8 = {
  key: 3,
  "class": "kds-card__allergen-spacer"
};
var _hoisted_9 = {
  "class": "kds-card__main"
};
var _hoisted_10 = {
  "class": "kds-card__queue keep-latin"
};
var _hoisted_11 = {
  "class": "kds-card__elapsed-wrap"
};
var _hoisted_12 = ["aria-label"];
var _hoisted_13 = {
  key: 0,
  "class": "kds-card__delivery",
  "data-testid": "kds-card-delivery"
};
var _hoisted_14 = {
  key: 0,
  "class": "kds-card__delivery-row"
};
var _hoisted_15 = {
  "class": "kds-card__delivery-text"
};
var _hoisted_16 = {
  key: 1,
  "class": "kds-card__delivery-row kds-card__delivery-row--muted"
};
var _hoisted_17 = {
  "class": "kds-card__delivery-text"
};
var _hoisted_18 = ["href", "aria-label"];
var _hoisted_19 = {
  "class": "kds-card__delivery-text"
};
var _hoisted_20 = ["aria-label"];
var _hoisted_21 = ["aria-label"];
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_KdsOrderLine = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("KdsOrderLine");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["kds-card", $options.cardClass]),
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)($options.cardStyle),
    tabindex: "0",
    role: "region",
    "aria-label": $options.ariaLabel,
    "data-order-id": $props.order.id,
    onKeydown: _cache[1] || (_cache[1] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)(function () {
      return $options.onCardKeydownEnter && $options.onCardKeydownEnter.apply($options, arguments);
    }, ["enter"]))
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" TOP ACCENT STRIPE — dominant 2m signal "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "kds-card__stripe",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)({
      background: $options.stripeColor
    })
  }, null, 4 /* STYLE */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" HEADER "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "kds-card__header",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)({
      background: $options.headerBg
    })
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" meta row: slot + state/source + allergen "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_2, [$props.shortcut ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
    key: 0,
    "class": "kds-card__shortcut",
    title: _ctx.$t('label.kds_bump_local_only_notice')
  }, "[" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.shortcut) + "]", 9 /* TEXT, PROPS */, _hoisted_3)) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_4)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_5, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__state-pill",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)($options.statePillStyle)
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__state-dot",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)({
      background: $options.stateTheme.dot
    })
  }, null, 4 /* STYLE */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.stateLabel), 1 /* TEXT */)], 4 /* STYLE */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__source-chip",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)($options.sourceChipStyle)
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_6, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.sourceLabel), 1 /* TEXT */)], 4 /* STYLE */)]), $options.hasAllergen ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_7, " ⚠ " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergie')), 1 /* TEXT */)) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_8))]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" main row: queue number + elapsed "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_9, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_10, [_cache[2] || (_cache[2] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__queue-prefix"
  }, "N°", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.order.queue_number || $props.order.id), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_11, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__elapsed-label",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)({
      color: $options.elapsedLabelColor
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_attente')), 5 /* TEXT, STYLE */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["kds-card__elapsed keep-latin", $options.bucket === 'critical' ? 'kds-pulse-digit' : '']),
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)({
      color: $options.elapsedColor
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.elapsedFormatted), 7 /* TEXT, CLASS, STYLE */)])])], 4 /* STYLE */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" BODY "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" KDS-R1-05 heal: tabindex=0 + role=region + aria-label = Safari keyboard accessibility\n         on scrollable overflow:auto region (axe-core 'scrollable-region-focusable' serious). "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "kds-card__body",
    tabindex: "0",
    role: "region",
    "aria-label": _ctx.$t('label.kds_card_body_aria', {
      queue: $props.order.queue_number || $props.order.id
    })
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Sprint 2A DEL-3 2026-05-16] Delivery block — only renders when\n           the order is destined for delivery AND the backend has populated\n           `order_address` (i.e. the eager-load was applied). Without this,\n           the livreur sees a token + delivery_time and literally cannot\n           deliver. Kept visually quiet (border-left accent, monospace\n           latin digits for the phone) so it never out-screams the queue\n           number or the elapsed timer. "), $options.isDeliveryOrder ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_13, [$options.deliveryAddressLine ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_14, [_cache[3] || (_cache[3] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__delivery-icon",
    "aria-hidden": "true"
  }, "📍", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_15, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.deliveryAddressLine), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.customerName ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_16, [_cache[4] || (_cache[4] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__delivery-icon",
    "aria-hidden": "true"
  }, "👤", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_17, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.customerName), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.customerPhone ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("a", {
    key: 2,
    "class": "kds-card__delivery-row kds-card__delivery-phone keep-latin",
    href: "tel:".concat($options.customerPhone),
    "aria-label": _ctx.$t('label.kds_call_customer_aria', {
      name: $options.customerName || '',
      phone: $options.customerPhone
    })
  }, [_cache[5] || (_cache[5] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-card__delivery-icon",
    "aria-hidden": "true"
  }, "📱", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_19, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.customerPhone), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_18)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($props.order.order_items, function (item, idx) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      key: item.id || idx,
      "class": "kds-card__item-block"
    }, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.renderItemLines(item), function (line, li) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createBlock)(_component_KdsOrderLine, {
        key: li,
        line: line
      }, null, 8 /* PROPS */, ["line"]);
    }), 128 /* KEYED_FRAGMENT */))]);
  }), 128 /* KEYED_FRAGMENT */)), _cache[6] || (_cache[6] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "kds-card__body-fade"
  }, null, -1 /* CACHED */))], 8 /* PROPS */, _hoisted_12), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" FOOTER CTA (paid orders) or CASH-PENDING badge (cash-at-counter waiting collection) "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Wave S-2 P-OWNER 2026-05-20] Owner decision: 1 clic CTA = PREPARING→PREPARED\n         direct for paid orders (S-1 auto-promotes ACCEPT→PREPARING on payment).\n         For cash-at-counter orders still awaiting cashier encaissement\n         (payment_pending_counter=true), the chef MUST NOT bump — show a\n         passive badge \"EN ATTENTE ENCAISSEMENT\" instead. This prevents\n         the kitchen from preparing food before the cashier collects cash\n         (regulatory + revenue-protection requirement).\n         Server-side, OrderStateMachine still blocks if the chef tries\n         anyway via direct axios, but this UI gate is the primary signal. "), $options.isCashPending ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    key: 0,
    "class": "kds-card__cash-pending",
    role: "status",
    "aria-label": _ctx.$t('label.kds_card_cash_pending_aria'),
    "data-testid": "kds-card-cash-pending"
  }, [_cache[7] || (_cache[7] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("svg", {
    width: "20",
    height: "20",
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    "stroke-width": "2.5",
    "stroke-linecap": "round",
    "stroke-linejoin": "round",
    "aria-hidden": "true"
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("circle", {
    cx: "12",
    cy: "12",
    r: "10"
  }), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("polyline", {
    points: "12 6 12 12 16 14"
  })], -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_card_cash_pending')), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_20)) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
    key: 1,
    type: "button",
    "class": "kds-card__cta",
    onClick: _cache[0] || (_cache[0] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
      return $options.onCta && $options.onCta.apply($options, arguments);
    }, ["prevent"])),
    "aria-label": _ctx.$t('label.kds_card_cta_ready'),
    "data-testid": "kds-card-cta-ready"
  }, [_cache[8] || (_cache[8] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("svg", {
    width: "22",
    height: "22",
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    "stroke-width": "3.5",
    "stroke-linecap": "round",
    "stroke-linejoin": "round",
    "aria-hidden": "true"
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("path", {
    d: "M5 12l5 5L20 7"
  })], -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_card_cta_ready')), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_21))], 46 /* CLASS, STYLE, PROPS, NEED_HYDRATION */, _hoisted_1);
}

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true"
/*!**************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true ***!
  \**************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = {
  key: 0,
  "class": "kds-line__header"
};
var _hoisted_2 = {
  "class": "kds-line__qty"
};
var _hoisted_3 = ["aria-label"];
var _hoisted_4 = {
  "class": "kds-line__name"
};
var _hoisted_5 = {
  "class": "kds-line__variation"
};
var _hoisted_6 = {
  "class": "kds-line__group"
};
var _hoisted_7 = {
  "class": "kds-line__value"
};
var _hoisted_8 = {
  "class": "kds-line__variation kds-line__variation--flat"
};
var _hoisted_9 = {
  "class": "kds-line__group"
};
var _hoisted_10 = {
  "class": "kds-line__value"
};
var _hoisted_11 = {
  "class": "kds-line__supplement"
};
var _hoisted_12 = {
  "class": "kds-line__menu-child"
};
var _hoisted_13 = {
  "class": "kds-line__addon"
};
var _hoisted_14 = {
  "class": "kds-line__allergen-block",
  role: "alert"
};
var _hoisted_15 = {
  "class": "kds-line__allergen-label"
};
var _hoisted_16 = {
  "class": "kds-line__allergen-codes"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["kds-line", "kds-line--".concat($props.line.type)])
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" header — qty + name + allergen icon "), $props.line.type === 'header' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_1, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_2, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.qty), 1 /* TEXT */), _cache[0] || (_cache[0] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__qty-x"
  }, "×", -1 /* CACHED */))]), $props.line.hasAllergen ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
    key: 0,
    "class": "kds-line__allergen-icon",
    "aria-label": _ctx.$t('label.kds_line_allergen_icon_aria')
  }, "⚠", 8 /* PROPS */, _hoisted_3)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_4, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)])) : $props.line.type === 'variation' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 1
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" grouped variation: \"Pain : Baguette\" "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_5, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_6, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.groupLabel), 1 /* TEXT */), _cache[1] || (_cache[1] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__sep"
  }, " : ", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_7, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)])], 64 /* STABLE_FRAGMENT */)) : $props.line.type === 'variation-flat' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 2
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" flat assiette: \"Avec : a, b, c\" "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_8, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_9, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_group_avec')), 1 /* TEXT */), _cache[2] || (_cache[2] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__sep"
  }, " : ", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_10, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)])], 64 /* STABLE_FRAGMENT */)) : $props.line.type === 'supplement' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 3
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" supplement: \"+ Cheddar\" in yellow italic "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_11, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)], 64 /* STABLE_FRAGMENT */)) : $props.line.type === 'menu_child' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 4
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" menu_child: \"▸ Frites Moyennes\" (formule child) "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_12, [_cache[3] || (_cache[3] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__menu-arrow"
  }, "▸", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)])], 64 /* STABLE_FRAGMENT */)) : $props.line.type === 'addon' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 5
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" generic addon "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_13, [_cache[4] || (_cache[4] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__menu-arrow"
  }, "▸", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)])], 64 /* STABLE_FRAGMENT */)) : $props.line.type === 'instruction' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 6
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" free-text instruction "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(['kds-line__instruction', $props.line.visualClass])
  }, [_cache[5] || (_cache[5] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__instruction-prefix"
  }, "·", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.line.label), 1 /* TEXT */)], 2 /* CLASS */)], 64 /* STABLE_FRAGMENT */)) : $props.line.type === 'allergen' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 7
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" allergen codes block "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_14, [_cache[6] || (_cache[6] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-line__allergen-icon"
  }, "⚠", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_15, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.allergenLabel), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_16, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.joinedCodes), 1 /* TEXT */)])], 64 /* STABLE_FRAGMENT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)], 2 /* CLASS */);
}

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true"
/*!*****************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true ***!
  \*****************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }

var _hoisted_1 = {
  "class": "kds-banner__main"
};
var _hoisted_2 = {
  key: 0,
  "class": "kds-banner__spinner",
  width: "14",
  height: "14",
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  "stroke-width": "3",
  "stroke-linecap": "round",
  "aria-hidden": "true"
};
var _hoisted_3 = {
  key: 1,
  width: "11",
  height: "11",
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  "stroke-width": "3",
  "stroke-linecap": "round",
  "stroke-linejoin": "round",
  "aria-hidden": "true"
};
var _hoisted_4 = {
  "class": "kds-banner__text"
};
var _hoisted_5 = {
  "class": "kds-banner__tag keep-latin"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  return $options.bannerData ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    key: 0,
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(['kds-banner', "kds-banner--".concat($options.bannerData.level)]),
    role: "status"
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_1, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "kds-banner__icon",
    style: (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeStyle)({
      background: $options.bannerData.iconBg
    })
  }, [$options.bannerData.level === 'error' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("svg", _hoisted_2, _toConsumableArray(_cache[0] || (_cache[0] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("path", {
    d: "M21 12a9 9 0 1 1-6.219-8.56"
  }, null, -1 /* CACHED */)])))) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("svg", _hoisted_3, _toConsumableArray(_cache[1] || (_cache[1] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("path", {
    d: "M12 9v4M12 17h.01"
  }, null, -1 /* CACHED */)]))))], 4 /* STYLE */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_4, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.bannerData.text), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_5, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.bannerData.tag), 1 /* TEXT */)], 2 /* CLASS */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true);
}

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true"
/*!***********************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true ***!
  \***********************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["dir"];
var _hoisted_2 = {
  key: 0,
  "class": "kds-v2__empty"
};
var _hoisted_3 = {
  "class": "kds-v2__empty-title"
};
var _hoisted_4 = {
  "class": "kds-v2__empty-sub"
};
var _hoisted_5 = {
  "class": "kds-v2__grid"
};
var _hoisted_6 = ["aria-label"];
var _hoisted_7 = {
  "class": "kds-v2__served-label"
};
var _hoisted_8 = {
  "class": "kds-v2__served-list"
};
var _hoisted_9 = ["title"];
var _hoisted_10 = {
  "class": "kds-v2__served-pill-num"
};
var _hoisted_11 = {
  "class": "kds-v2__served-pill-ago"
};
var _hoisted_12 = {
  "class": "sr-only",
  "aria-live": "polite",
  "aria-atomic": "true"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_KdsStatusBanner = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("KdsStatusBanner");
  var _component_KdsOrderCard = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("KdsOrderCard");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    "class": "kds-v2",
    dir: $props.dir
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Single banner zone "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_KdsStatusBanner, {
    "offline-since": $props.offlineSince,
    "list-at-cap": $props.listAtCap,
    "near-cap": $options.activeOrders.length,
    "fallback-mode": $props.fallbackMode,
    "admin-polling-hint": $props.adminPollingHint,
    "bump-local-only-notice": $props.bumpLocalOnlyNotice
  }, null, 8 /* PROPS */, ["offline-since", "list-at-cap", "near-cap", "fallback-mode", "admin-polling-hint", "bump-local-only-notice"]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Empty state (only when NO active orders — served strip below renders independently) "), $options.activeOrders.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_2, [_cache[0] || (_cache[0] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createStaticVNode)("<div class=\"kds-v2__empty-illustration\" aria-hidden=\"true\" data-v-3992cfe5><div class=\"kds-v2__empty-glow\" data-v-3992cfe5></div><svg width=\"120\" height=\"120\" viewBox=\"0 0 64 64\" fill=\"none\" stroke=\"#9CA3AF\" stroke-width=\"2.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\" data-v-3992cfe5><ellipse cx=\"32\" cy=\"42\" rx=\"22\" ry=\"6\" fill=\"#F3F4F6\" stroke=\"#D1D5DB\" data-v-3992cfe5></ellipse><path d=\"M10 38a22 8 0 0 1 44 0\" fill=\"white\" data-v-3992cfe5></path><path d=\"M14 30c0-4 6-8 18-8s18 4 18 8\" data-v-3992cfe5></path><circle cx=\"22\" cy=\"24\" r=\"1.5\" fill=\"#9CA3AF\" data-v-3992cfe5></circle><circle cx=\"32\" cy=\"22\" r=\"1.5\" fill=\"#9CA3AF\" data-v-3992cfe5></circle><circle cx=\"42\" cy=\"24\" r=\"1.5\" fill=\"#9CA3AF\" data-v-3992cfe5></circle></svg></div>", 1)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_3, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_empty_state')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_4, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_empty_state_sub')), 1 /* TEXT */)])) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 1
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" FIFO Grid 4×2 — ACTIVE only (ACCEPT + PREPARING). PREPARED → served strip below.\n         [Wave U 2026-05-21] Owner-reported bug: PREPARED orders stayed greyed in grid\n         (kds-card--ready opacity:0.7) with the elapsed timer still ticking, occupying\n         a slot. Now they leave the active FIFO entirely and surface in a compact\n         strip at the bottom for ~last 4 served, with elapsed-since-served. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_5, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.activeOrders.slice(0, 8), function (o, idx) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createBlock)(_component_KdsOrderCard, {
      key: o.id,
      order: o,
      now: $data.now,
      shortcut: $options.SHORTCUTS[idx],
      onReady: function onReady($event) {
        return $options.onCtaTap(o.id, o.queue_number);
      }
    }, null, 8 /* PROPS */, ["order", "now", "shortcut", "onReady"]);
  }), 128 /* KEYED_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" placeholders to keep grid stable when <8 "), ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(Math.max(0, 8 - $options.activeOrders.length), function (i) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      key: "ph-".concat(i),
      "class": "kds-v2__placeholder"
    });
  }), 128 /* KEYED_FRAGMENT */))])], 2112 /* STABLE_FRAGMENT, DEV_ROOT_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Wave U 2026-05-21] Récemment servies — compact archive strip.\n         Renders the 4 most recently PREPARED orders with elapsed-since-served.\n         Small footprint (60px row) so it never steals space from the active grid. "), $options.recentlyServed.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    key: 2,
    "class": "kds-v2__served",
    role: "region",
    "aria-label": _ctx.$t('label.kds_recently_served')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_7, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_recently_served')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_8, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.recentlyServed, function (o) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      key: "served-".concat(o.id),
      "class": "kds-v2__served-pill keep-latin",
      title: _ctx.$t('label.kds_served_pill_title', {
        queue: o.queue_number || o.id
      })
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_10, "N°" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(o.queue_number || o.id), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_11, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.servedAgoLabel(o)), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_9);
  }), 128 /* KEYED_FRAGMENT */))])], 8 /* PROPS */, _hoisted_6)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Wave V 2026-05-21] KdsUndoToast removed — chef Prêt tap now PATCHes\n         immediately. The 3s undo window + single-slot serialization\n         (clearTimeout(pendingTimeoutId)) caused a cross-order race: when chef\n         chained \"Prêt\" on 3+ orders within 3s, the previous order's PATCH\n         was cancelled by the next click → only the LAST order transitioned,\n         the rest stayed EN COURS until chef re-clicked (perceived as a 30s\n         retry-after toast). Per owner mandate \"enlève cette sécurité\".\n         Component file kept for instant rollback. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" aria-live region for screen readers "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_12, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($data.liveMessage), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_1);
}

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true"
/*!*******************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true ***!
  \*******************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }

var _hoisted_1 = {
  key: 0,
  "class": "ws-reconnect-banner",
  "data-testid": "kds-sync-mode-banner"
};
var _hoisted_2 = {
  key: 1,
  "class": "kds-hint-banner kds-hint-banner--danger kds-hint-banner--action",
  role: "alert",
  "aria-live": "assertive",
  "data-testid": "kds-error-banner"
};
var _hoisted_3 = ["aria-label"];
var _hoisted_4 = {
  key: 2,
  "class": "kds-hint-banner kds-hint-banner--info",
  role: "status"
};
var _hoisted_5 = {
  key: 3,
  "class": "kds-hint-banner kds-hint-banner--warning",
  role: "alert"
};
var _hoisted_6 = {
  key: 4,
  "class": "kds-hint-banner kds-hint-banner--danger kds-hint-banner--action",
  role: "alert"
};
var _hoisted_7 = {
  key: 5,
  "class": "kds-hint-banner kds-hint-banner--neutral flex flex-wrap items-center justify-between gap-2 text-left",
  role: "note"
};
var _hoisted_8 = {
  "class": "min-w-0 flex-1"
};
var _hoisted_9 = {
  "class": "row md:mt-4 lg:mt-0"
};
var _hoisted_10 = {
  "class": "lg:hidden flex items-center w-full px-4"
};
var _hoisted_11 = {
  "class": "kitchen-board db-tab-btn active text-base text-black font-semibold h-[38px] bg-white flex items-center justify-center rounded-l-lg px-7",
  "data-tab": "#item-order"
};
var _hoisted_12 = {
  "class": "kitchen-board db-tab-btn text-base text-black font-semibold h-[38px] bg-white flex items-center justify-center ro rounded-r-lg px-7",
  "data-tab": "#today-order"
};
var _hoisted_13 = {
  id: "item-order",
  "class": "col-12 lg:col-3 db-tab-div active lg:block hidden"
};
var _hoisted_14 = {
  "class": "db-card rounded-[10px] w-full"
};
var _hoisted_15 = {
  "class": "h-screen md:h-[calc(100vh-127px)] overflow-hidden"
};
var _hoisted_16 = {
  "class": "p-3 pb-2 border-b border-[#D9DBE9]"
};
var _hoisted_17 = {
  "class": "text-lg font-semibold"
};
var _hoisted_18 = {
  "class": "text-[11px] text-[#4B5563] leading-snug mt-1"
};
var _hoisted_19 = {
  "class": "h-full thin-scrolling overflow-auto pb-12"
};
var _hoisted_20 = {
  "class": "text-sm font-medium mb-1"
};
var _hoisted_21 = {
  key: 0,
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_22 = {
  key: 0
};
var _hoisted_23 = {
  key: 1,
  "class": "flex gap-1"
};
var _hoisted_24 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap"
};
var _hoisted_25 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_26 = {
  key: 0
};
var _hoisted_27 = {
  key: 2,
  "class": "flex gap-1"
};
var _hoisted_28 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap"
};
var _hoisted_29 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_30 = {
  key: 0
};
var _hoisted_31 = {
  key: 1
};
var _hoisted_32 = {
  "class": "text-sm font-medium w-6 h-6 rounded-full bg-black text-white flex items-center justify-center"
};
var _hoisted_33 = {
  id: "today-order",
  "class": "col-12 lg:col-9 db-tab-div lg:block hidden"
};
var _hoisted_34 = {
  "class": "ordersTab"
};
var _hoisted_35 = {
  "class": "db-card px-3 py-3 mb-4 flex flex-col xl:flex-row flex-wrap gap-4 xl:items-center xl:justify-between"
};
var _hoisted_36 = {
  "class": "flex flex-wrap items-center gap-3"
};
var _hoisted_37 = {
  "for": "kds-station-filter",
  "class": "text-sm font-medium text-heading shrink-0"
};
var _hoisted_38 = ["aria-label"];
var _hoisted_39 = {
  value: "all"
};
var _hoisted_40 = {
  value: "bar"
};
var _hoisted_41 = {
  value: "cuisine_chaude"
};
var _hoisted_42 = {
  value: "cuisine_froide"
};
var _hoisted_43 = {
  "class": "flex items-center gap-2 text-xs font-medium text-heading cursor-pointer"
};
var _hoisted_44 = {
  "class": "flex flex-wrap items-center gap-4"
};
var _hoisted_45 = {
  "class": "text-sm font-medium text-heading"
};
var _hoisted_46 = {
  "class": "flex items-center gap-2 text-xs text-heading cursor-pointer"
};
var _hoisted_47 = {
  "class": "sr-only"
};
var _hoisted_48 = {
  "class": "flex items-center gap-2 text-xs text-heading"
};
var _hoisted_49 = {
  "class": "whitespace-nowrap"
};
var _hoisted_50 = ["aria-label"];
var _hoisted_51 = {
  ref: "kdsNewOrderAudio",
  preload: "auto",
  "class": "hidden",
  src: "/sounds/kds-new-order.mp3"
};
var _hoisted_52 = {
  "class": "db-card px-3 py-2.5 mb-4"
};
var _hoisted_53 = {
  "class": "swiper kitchen-swiper !flex flex-col gap-y-2 xl:flex-row items-start justify-between"
};
var _hoisted_54 = {
  "class": "whitespace-nowrap text-sm font-medium"
};
var _hoisted_55 = {
  "class": "whitespace-nowrap text-sm font-medium"
};
var _hoisted_56 = {
  "class": "whitespace-nowrap text-sm font-medium"
};
var _hoisted_57 = {
  "class": "whitespace-nowrap text-sm font-medium"
};
var _hoisted_58 = ["placeholder", "aria-label"];
var _hoisted_59 = ["aria-label"];
var _hoisted_60 = {
  "class": "db-card rounded-[10px] h-fit"
};
var _hoisted_61 = {
  "class": "text-lg font-semibold"
};
var _hoisted_62 = {
  key: 0,
  "class": "p-3 text-sm text-[#6E7191]"
};
var _hoisted_63 = {
  key: 1,
  "class": "p-3 space-y-3"
};
var _hoisted_64 = {
  key: 0,
  "class": "mb-1"
};
var _hoisted_65 = ["onClick"];
var _hoisted_66 = ["aria-labelledby"];
var _hoisted_67 = ["title", "aria-label"];
var _hoisted_68 = ["onClick", "aria-label"];
var _hoisted_69 = {
  "class": "py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#F0F8FF]"
};
var _hoisted_70 = {
  "class": "flex items-center gap-1 text-[#0084FF]"
};
var _hoisted_71 = ["id"];
var _hoisted_72 = {
  key: 0,
  "class": "kds-source-pill kds-source-pill--queue"
};
var _hoisted_73 = {
  "class": "w-full pt-2 pb-3 px-3"
};
var _hoisted_74 = {
  "class": "text-sm font-normal leading-6 font-client capitalize text-[#6E7191]"
};
var _hoisted_75 = {
  "class": "text-heading font-medium"
};
var _hoisted_76 = {
  "class": "text-sm font-normal leading-6 font-client capitalize text-[#6E7191]"
};
var _hoisted_77 = {
  "class": "text-heading font-medium"
};
var _hoisted_78 = {
  key: 0,
  "class": "mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]",
  "data-testid": "kds-legacy-delivery"
};
var _hoisted_79 = {
  key: 0,
  "class": "text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1"
};
var _hoisted_80 = {
  "class": "break-words"
};
var _hoisted_81 = {
  key: 1,
  "class": "text-xs leading-snug text-[#115E59] flex items-center gap-1"
};
var _hoisted_82 = ["href"];
var _hoisted_83 = ["aria-label"];
var _hoisted_84 = {
  style: {
    "height": "0px"
  },
  "class": "overflow-hidden transition-all duration-500"
};
var _hoisted_85 = {
  "class": "text-sm font-medium shrink-0"
};
var _hoisted_86 = {
  "class": "flex-1 min-w-0"
};
var _hoisted_87 = {
  "class": "text-sm font-medium mb-1"
};
var _hoisted_88 = {
  key: 0,
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_89 = {
  key: 0
};
var _hoisted_90 = {
  key: 1,
  "class": "flex gap-1"
};
var _hoisted_91 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_92 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_93 = {
  key: 0
};
var _hoisted_94 = {
  key: 2,
  "class": "flex gap-1"
};
var _hoisted_95 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_96 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_97 = {
  key: 0
};
var _hoisted_98 = {
  key: 1
};
var _hoisted_99 = {
  "class": "flex flex-col items-end gap-1 shrink-0 pt-0.5"
};
var _hoisted_100 = ["title", "aria-label", "onClick"];
var _hoisted_101 = ["onClick"];
var _hoisted_102 = ["onClick"];
var _hoisted_103 = {
  "class": "kds-card-cta mt-2",
  "data-testid": "kds-card-cta"
};
var _hoisted_104 = ["onClick"];
var _hoisted_105 = ["onClick"];
var _hoisted_106 = {
  "class": "db-card rounded-[10px] h-fit"
};
var _hoisted_107 = {
  "class": "text-lg font-semibold"
};
var _hoisted_108 = {
  key: 0,
  "class": "p-3 text-sm text-[#6E7191]"
};
var _hoisted_109 = ["aria-labelledby"];
var _hoisted_110 = ["title", "aria-label"];
var _hoisted_111 = ["onClick", "aria-label"];
var _hoisted_112 = {
  "class": "py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FFF6EE]"
};
var _hoisted_113 = {
  "class": "flex items-center gap-1 text-[#FF8C1A]"
};
var _hoisted_114 = ["id"];
var _hoisted_115 = {
  key: 0,
  "class": "kds-source-pill kds-source-pill--queue"
};
var _hoisted_116 = {
  "class": "w-full pt-2 pb-3 px-3"
};
var _hoisted_117 = {
  "class": "text-sm font-normal leading-6 font-client capitalize text-[#6E7191]"
};
var _hoisted_118 = {
  key: 0,
  "class": "text-sm font-normal leading-6 font-client capitalize text-[#6E7191]"
};
var _hoisted_119 = {
  "class": "text-heading font-medium"
};
var _hoisted_120 = {
  key: 1,
  "class": "mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]",
  "data-testid": "kds-legacy-delivery"
};
var _hoisted_121 = {
  key: 0,
  "class": "text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1"
};
var _hoisted_122 = {
  "class": "break-words"
};
var _hoisted_123 = {
  key: 1,
  "class": "text-xs leading-snug text-[#115E59] flex items-center gap-1"
};
var _hoisted_124 = ["href"];
var _hoisted_125 = ["aria-label"];
var _hoisted_126 = {
  style: {
    "height": "0px"
  },
  "class": "overflow-hidden transition-all duration-500"
};
var _hoisted_127 = {
  "class": "text-sm font-medium shrink-0"
};
var _hoisted_128 = {
  "class": "flex-1 min-w-0"
};
var _hoisted_129 = {
  "class": "text-sm font-medium mb-1"
};
var _hoisted_130 = {
  key: 0,
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_131 = {
  key: 0
};
var _hoisted_132 = {
  key: 1,
  "class": "flex gap-1"
};
var _hoisted_133 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_134 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_135 = {
  key: 0
};
var _hoisted_136 = {
  key: 2,
  "class": "flex gap-1"
};
var _hoisted_137 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_138 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_139 = {
  key: 0
};
var _hoisted_140 = {
  key: 1
};
var _hoisted_141 = {
  "class": "flex flex-col items-end gap-1 shrink-0 pt-0.5"
};
var _hoisted_142 = ["title", "aria-label", "onClick"];
var _hoisted_143 = ["onClick"];
var _hoisted_144 = ["onClick"];
var _hoisted_145 = {
  "class": "kds-card-cta mt-2",
  "data-testid": "kds-card-cta"
};
var _hoisted_146 = ["onClick"];
var _hoisted_147 = ["onClick"];
var _hoisted_148 = {
  "class": "db-card rounded-[10px] h-fit"
};
var _hoisted_149 = {
  "class": "text-lg font-semibold"
};
var _hoisted_150 = {
  key: 0,
  "class": "p-3 text-sm text-[#6E7191]"
};
var _hoisted_151 = ["aria-labelledby"];
var _hoisted_152 = ["title", "aria-label"];
var _hoisted_153 = ["onClick", "aria-label"];
var _hoisted_154 = {
  "class": "py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#F7F0FF]"
};
var _hoisted_155 = {
  "class": "kds-card-header-meta text-[#2D1263]"
};
var _hoisted_156 = ["id"];
var _hoisted_157 = {
  key: 0,
  "class": "kds-source-pill kds-source-pill--queue"
};
var _hoisted_158 = {
  key: 1,
  "class": "kds-counter-payment-badge"
};
var _hoisted_159 = {
  "class": "w-full pt-2 pb-3 px-3"
};
var _hoisted_160 = {
  "class": "text-sm font-normal leading-6 font-client capitalize text-[#6E7191]"
};
var _hoisted_161 = {
  "class": "text-heading font-medium"
};
var _hoisted_162 = {
  key: 0,
  "class": "text-sm font-normal leading-6 font-client text-[#6E7191]"
};
var _hoisted_163 = {
  "class": "text-heading font-medium"
};
var _hoisted_164 = {
  key: 1,
  "class": "mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]",
  "data-testid": "kds-legacy-delivery"
};
var _hoisted_165 = {
  key: 0,
  "class": "text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1"
};
var _hoisted_166 = {
  "class": "break-words"
};
var _hoisted_167 = {
  key: 1,
  "class": "text-xs leading-snug text-[#115E59] flex items-center gap-1"
};
var _hoisted_168 = ["href"];
var _hoisted_169 = ["aria-label"];
var _hoisted_170 = {
  style: {
    "height": "0px"
  },
  "class": "overflow-hidden transition-all duration-500"
};
var _hoisted_171 = {
  "class": "text-sm font-medium shrink-0"
};
var _hoisted_172 = {
  "class": "flex-1 min-w-0"
};
var _hoisted_173 = {
  "class": "text-sm font-medium mb-1"
};
var _hoisted_174 = {
  key: 0,
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_175 = {
  key: 0
};
var _hoisted_176 = {
  key: 1,
  "class": "flex gap-1"
};
var _hoisted_177 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_178 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_179 = {
  key: 0
};
var _hoisted_180 = {
  key: 2,
  "class": "flex gap-1"
};
var _hoisted_181 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_182 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_183 = {
  key: 0
};
var _hoisted_184 = {
  key: 1
};
var _hoisted_185 = {
  "class": "flex flex-col items-end gap-1 shrink-0 pt-0.5"
};
var _hoisted_186 = ["title", "aria-label", "onClick"];
var _hoisted_187 = ["onClick"];
var _hoisted_188 = ["onClick"];
var _hoisted_189 = {
  "class": "kds-card-cta mt-2",
  "data-testid": "kds-card-cta"
};
var _hoisted_190 = ["onClick"];
var _hoisted_191 = ["onClick"];
var _hoisted_192 = {
  "class": "db-card rounded-[10px] h-fit"
};
var _hoisted_193 = {
  key: 0,
  "class": "inline-flex items-center justify-center w-6 h-6 rounded-full bg-[#991B1B] text-white text-[11px] font-bold"
};
var _hoisted_194 = {
  key: 0,
  "class": "p-3 text-sm text-[#6E7191]"
};
var _hoisted_195 = ["aria-labelledby"];
var _hoisted_196 = ["title", "aria-label"];
var _hoisted_197 = ["onClick", "aria-label"];
var _hoisted_198 = {
  "class": "py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FFF0EE]"
};
var _hoisted_199 = {
  "class": "kds-card-header-meta text-[#991B1B]"
};
var _hoisted_200 = ["id"];
var _hoisted_201 = {
  key: 0,
  "class": "kds-source-pill kds-source-pill--queue"
};
var _hoisted_202 = {
  key: 1,
  "class": "kds-counter-payment-badge"
};
var _hoisted_203 = {
  "class": "w-full pt-2 pb-3 px-3"
};
var _hoisted_204 = {
  key: 0,
  "class": "mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]",
  "data-testid": "kds-legacy-delivery"
};
var _hoisted_205 = {
  key: 0,
  "class": "text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1"
};
var _hoisted_206 = {
  "class": "break-words"
};
var _hoisted_207 = {
  key: 1,
  "class": "text-xs leading-snug text-[#115E59] flex items-center gap-1"
};
var _hoisted_208 = ["href"];
var _hoisted_209 = ["aria-label"];
var _hoisted_210 = {
  key: 0,
  "class": "text-xs font-medium text-heading"
};
var _hoisted_211 = {
  style: {
    "height": "0px"
  },
  "class": "overflow-hidden transition-all duration-500"
};
var _hoisted_212 = {
  "class": "text-sm font-medium shrink-0"
};
var _hoisted_213 = {
  "class": "flex-1 min-w-0"
};
var _hoisted_214 = {
  "class": "text-sm font-medium mb-1"
};
var _hoisted_215 = {
  key: 0,
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_216 = {
  key: 0
};
var _hoisted_217 = {
  key: 1,
  "class": "flex gap-1"
};
var _hoisted_218 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_219 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_220 = {
  key: 0
};
var _hoisted_221 = {
  key: 2,
  "class": "flex gap-1"
};
var _hoisted_222 = {
  "class": "capitalize text-xs w-fit whitespace-nowrap font-medium"
};
var _hoisted_223 = {
  "class": "text-xs font-normal font-client capitalize text-[#6E7191]"
};
var _hoisted_224 = {
  key: 0
};
var _hoisted_225 = {
  key: 1
};
var _hoisted_226 = {
  key: 3,
  "class": "mt-2 flex flex-wrap gap-1"
};
var _hoisted_227 = {
  "class": "flex flex-col items-end gap-1 shrink-0 pt-0.5"
};
var _hoisted_228 = ["title", "aria-label", "onClick"];
var _hoisted_229 = ["onClick"];
var _hoisted_230 = ["onClick"];
var _hoisted_231 = {
  "class": "kds-card-cta mt-2",
  "data-testid": "kds-card-cta"
};
var _hoisted_232 = ["onClick"];
var _hoisted_233 = ["onClick"];
var _hoisted_234 = {
  "class": "sr-only kds-aria-live",
  "data-testid": "kds-aria-live",
  role: "status",
  "aria-live": "polite",
  "aria-atomic": "true"
};
var _hoisted_235 = {
  "class": "kds-sync-footer"
};
var _hoisted_236 = ["title"];
var _hoisted_237 = ["aria-label"];
var _hoisted_238 = {
  "class": "kds-allergens-modal-content"
};
var _hoisted_239 = {
  "class": "kds-allergens-modal-header"
};
var _hoisted_240 = {
  "class": "kds-allergens-modal-intro"
};
var _hoisted_241 = {
  "class": "kds-allergens-modal-list"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_KdsV2Grid = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("KdsV2Grid");
  var _component_ConnectionStatusBanner = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("ConnectionStatusBanner");
  var _component_LoadingComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("LoadingComponent");
  var _component_SwiperSlide = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("SwiperSlide");
  var _component_Swiper = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("Swiper");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [kds/sprint-2 V-5] Feature-flagged V2 layout. When useV2Layout is true\n    (URL ?v2=1, localStorage 'kds.v2_enabled', or future settings flag), the\n    new single-FIFO 4×2 grid renders. Otherwise the legacy 4-column layout\n    (Dine-in / Online / Takeaway / Kiosk) stays — instant rollback by URL\n    param removal.\n  "), $options.useV2Layout ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createBlock)(_component_KdsV2Grid, {
    key: 0,
    orders: $options.orders,
    dir: $options.direction,
    "offline-since": $data.v2OfflineSince,
    "list-at-cap": $options.kdsOrderListAtCap,
    "fallback-mode": !$data.wsConnected && !$options.kdsHideFallbackBannerInLocalDev,
    "admin-polling-hint": $options.kdsIsCentralAdmin,
    "bump-local-only-notice": !$data.kdsHideBumpInfo,
    "auto-transition-enabled": $data.v2AutoTransitionEnabled,
    onChangeStatus: $options.onV2ChangeStatus,
    onAutoPromote: $options.onV2AutoPromote
  }, null, 8 /* PROPS */, ["orders", "dir", "offline-since", "list-at-cap", "fallback-mode", "admin-polling-hint", "bump-local-only-notice", "auto-transition-enabled", "onChangeStatus", "onAutoPromote"])) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 1
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [iter15-mega-fix B-003/D-002 2026-05-10] Banner consolidation: suppress the\n    global transient \"Reconnexion en cours…\" banner here because the local\n    kds-sync-mode-banner already conveys fallback polling state to staff.\n    Prevents two banners shouting the same fact (iter15 mega-audit Wave B/D).\n    Terminal session_invalid still surfaces.\n  "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_ConnectionStatusBanner, {
    "suppress-transient": ""
  }), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_LoadingComponent, {
    props: $data.loading
  }, null, 8 /* PROPS */, ["props"]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [iter15-mega-fix C-008 run-3 2026-05-10] Hide the KDS \"Mode secours actif\"\n    fallback banner in local dev where Pusher/Soketi is not running — it was\n    permanently visible across every KDS view (states 01/07/09 in iter15 mega\n    Wave B + Wave C) and pure noise. Production keeps it: kitchen needs to\n    know they are in fallback polling. Gate strictly on appEnv === 'local'\n    (NOT 'testing') so any CI suite running with APP_ENV=testing still sees\n    the banner. Existing dependent specs (audit-kds-cycle1 D1-05,\n    red-team-r4 R4-12, 04-kds-status) all use OR-fallback locators so they\n    keep passing in CI Playwright (which runs APP_ENV=local) via the sync\n    stamp / aria-live / grid alternatives.\n  "), !$data.wsConnected && !$options.kdsHideFallbackBannerInLocalDev ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_1, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_fallback_banner')), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [test-e2e round-2 cluster-1 C-001 2026-05-10] Persistent error banner.\n    Replaces (and augments) the previous ephemeral toast in\n    _refreshWithCurrentFilter / list() error paths. Visible until the next\n    successful /api/admin/kds-order poll resets it, or the operator dismisses\n    it manually with the ✕ button. data-testid lets E2E specs assert\n    visibility deterministically (no race with toast fade-out).\n  "), $data.kdsErrorBanner.visible ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_2, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($data.kdsErrorBanner.message), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    type: "button",
    "class": "kds-hint-link",
    "aria-label": _ctx.$t('label.kds_dismiss_hint'),
    onClick: _cache[0] || (_cache[0] = function () {
      return $options.dismissKdsErrorBanner && $options.dismissKdsErrorBanner.apply($options, arguments);
    })
  }, "✕", 8 /* PROPS */, _hoisted_3)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.kdsIsCentralAdmin ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_4, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.kds_admin_polling_hint")), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.kdsOrderApproachingCap ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_5, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.kds_order_cap_warning", {
    n: $options.orders.length
  })), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.kdsOrderListAtCap ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_6, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.kds_order_list_full_warning", {
    n: $options.orders.length
  })), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    type: "button",
    "class": "kds-hint-link",
    onClick: _cache[1] || (_cache[1] = function () {
      return $options.kdsOverflowSeeMore && $options.kdsOverflowSeeMore.apply($options, arguments);
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_see_more')), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), !$data.kdsHideBumpInfo ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_7, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_8, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.kds_bump_local_only_notice")), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    type: "button",
    "class": "shrink-0 text-xs font-medium underline text-[#4b5563]",
    onClick: _cache[2] || (_cache[2] = function () {
      return $options.dismissKdsBumpNotice && $options.dismissKdsBumpNotice.apply($options, arguments);
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.kds_dismiss_hint")), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_9, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_10, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", _hoisted_11, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.items_board')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", _hoisted_12, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.todays_order')), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_13, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_14, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_15, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_16, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_17, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.items_board')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_18, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.kds_items_board_scope")), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("ul", _hoisted_19, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [N7 FIX] Stable key using item_id + instruction hash instead of object reference "), ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.orderItems, function (orderItem, oIdx) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
      key: orderItem.item_id + '-' + oIdx,
      "class": "px-3 py-2 flex items-start justify-between gap-2 border-b border-[#EFF0F6] last:border-none"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h5", _hoisted_20, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(orderItem.item_name), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [AUDIT-P1] Array.isArray guard: legacy kiosk orders stored JSON objects,\n                     not arrays. Without this guard, .length on an object is undefined → Vue warning. "), Array.isArray(orderItem.item_variations) && orderItem.item_variations.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_21, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(orderItem.item_variations, function (variation, index) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
        key: index,
        "class": "text-heading"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.variation_name) + ": " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.name), 1 /* TEXT */), index + 1 < orderItem.item_variations.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_22, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
    }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(orderItem.item_extras) && orderItem.item_extras.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_23, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_24, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.extras')) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_25, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(orderItem.item_extras, function (extra, index) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
        key: index,
        "class": "text-heading"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(extra.name), 1 /* TEXT */), index + 1 < orderItem.item_extras.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_26, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
    }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(orderItem.item_addons) && orderItem.item_addons.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_27, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_28, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.addons')) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_29, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(orderItem.item_addons, function (addon, index) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
        key: index,
        "class": "text-heading"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsAddonDisplayName(addon)), 1 /* TEXT */), Number(addon.quantity || 1) > 1 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_30, " ×" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(Number(addon.quantity || 1)), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), index + 1 < orderItem.item_addons.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_31, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
    }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), orderItem.instruction ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      key: 3,
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$options.kdsInstructionClass(orderItem.instruction), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']),
      style: {
        "white-space": "pre-line"
      }
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(orderItem.instruction), 3 /* TEXT, CLASS */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_32, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(orderItem.quantity), 1 /* TEXT */)]);
  }), 128 /* KEYED_FRAGMENT */))])])])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_33, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_34, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_35, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_36, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", _hoisted_37, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_station_filter')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("select", {
    id: "kds-station-filter",
    "onUpdate:modelValue": _cache[3] || (_cache[3] = function ($event) {
      return $data.stationFilter = $event;
    }),
    onChange: _cache[4] || (_cache[4] = function () {
      return $options.persistKdsUiPrefs && $options.persistKdsUiPrefs.apply($options, arguments);
    }),
    "aria-label": _ctx.$t('label.kds_station_filter'),
    "class": "h-10 rounded-lg border border-[#D9DBE9] px-3 text-xs text-heading min-w-[10rem] bg-white"
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("option", _hoisted_39, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_all_stations')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("option", _hoisted_40, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_bar')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("option", _hoisted_41, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_cuisine_chaude')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("option", _hoisted_42, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_cuisine_froide')), 1 /* TEXT */)], 40 /* PROPS, NEED_HYDRATION */, _hoisted_38), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelSelect, $data.stationFilter]]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", _hoisted_43, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "checkbox",
    "onUpdate:modelValue": _cache[5] || (_cache[5] = function ($event) {
      return $data.groupByTable = $event;
    }),
    onChange: _cache[6] || (_cache[6] = function () {
      return $options.persistKdsUiPrefs && $options.persistKdsUiPrefs.apply($options, arguments);
    }),
    "class": "rounded border-[#D9DBE9]"
  }, null, 544 /* NEED_HYDRATION, NEED_PATCH */), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelCheckbox, $data.groupByTable]]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_group_by_table')), 1 /* TEXT */)])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_44, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_45, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_sound')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", _hoisted_46, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "checkbox",
    "onUpdate:modelValue": _cache[7] || (_cache[7] = function ($event) {
      return $data.soundEnabled = $event;
    }),
    onChange: _cache[8] || (_cache[8] = function () {
      return $options.persistKdsUiPrefs && $options.persistKdsUiPrefs.apply($options, arguments);
    }),
    "class": "rounded border-[#D9DBE9]"
  }, null, 544 /* NEED_HYDRATION, NEED_PATCH */), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelCheckbox, $data.soundEnabled]]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_47, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_sound')), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", _hoisted_48, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_49, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_volume')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "range",
    min: "0",
    max: "100",
    "onUpdate:modelValue": _cache[9] || (_cache[9] = function ($event) {
      return $data.soundVolume = $event;
    }),
    onInput: _cache[10] || (_cache[10] = function () {
      return $options.persistKdsUiPrefs && $options.persistKdsUiPrefs.apply($options, arguments);
    }),
    "aria-label": _ctx.$t('label.kds_volume'),
    "class": "w-28 accent-primary"
  }, null, 40 /* PROPS, NEED_HYDRATION */, _hoisted_50), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, $data.soundVolume, void 0, {
    number: true
  }]])])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("audio", _hoisted_51, null, 512 /* NEED_PATCH */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_52, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_53, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_Swiper, {
    dir: $options.direction,
    speed: 1000,
    slidesPerView: "auto",
    spaceBetween: 12,
    loop: false,
    "class": "md:grid sm:grid-cols-2 lg:grid-cols-4 gap-y-2 md:w-fit lg:!w-full w-full"
  }, {
    "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_SwiperSlide, {
        "class": "!w-fit"
      }, {
        "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
          return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
            type: "button",
            onClick: _cache[11] || (_cache[11] = function ($event) {
              return $options.list();
            }),
            "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]", !$data.props.search.status ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : ''])
          }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_54, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.all_orders")), 1 /* TEXT */)], 2 /* CLASS */)];
        }),
        _: 1 /* STABLE */
      }), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_SwiperSlide, {
        "class": "!w-fit"
      }, {
        "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
          return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
            type: "button",
            onClick: _cache[12] || (_cache[12] = function ($event) {
              return $options.list($data.enums.orderStatusEnum.ACCEPT);
            }),
            "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$data.props.search.status === $data.enums.orderStatusEnum.ACCEPT ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : '', "db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]"])
          }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_55, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.confirmed")), 1 /* TEXT */)], 2 /* CLASS */)];
        }),
        _: 1 /* STABLE */
      }), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_SwiperSlide, {
        "class": "!w-fit"
      }, {
        "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
          return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
            type: "button",
            onClick: _cache[13] || (_cache[13] = function ($event) {
              return $options.list($data.enums.orderStatusEnum.PREPARING);
            }),
            "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$data.props.search.status === $data.enums.orderStatusEnum.PREPARING ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : '', "db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]"])
          }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_56, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.preparing")), 1 /* TEXT */)], 2 /* CLASS */)];
        }),
        _: 1 /* STABLE */
      }), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_SwiperSlide, {
        "class": "!w-fit"
      }, {
        "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
          return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
            type: "button",
            onClick: _cache[14] || (_cache[14] = function ($event) {
              return $options.list($data.enums.orderStatusEnum.PREPARED);
            }),
            "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$data.props.search.status === $data.enums.orderStatusEnum.PREPARED ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : '', "db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]"])
          }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_57, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.done")), 1 /* TEXT */)], 2 /* CLASS */)];
        }),
        _: 1 /* STABLE */
      })];
    }),
    _: 1 /* STABLE */
  }, 8 /* PROPS */, ["dir"]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("form", {
    onSubmit: _cache[17] || (_cache[17] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
      return $options.search && $options.search.apply($options, arguments);
    }, ["prevent"])),
    "class": "header-search-group group flex items-center justify-center border border-solid gap-2 px-3 xl:!max-w-[305px] w-full h-11 rounded-lg transition border-[#D9DBE9] focus-within:bg-white focus-within:border-primary"
  }, [_cache[27] || (_cache[27] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
    "class": "lab lab-search-normal lab-font-size-16"
  }, null, -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[15] || (_cache[15] = function ($event) {
      return $data.props.search.order_serial_no = $event;
    }),
    placeholder: _ctx.$t('label.kds_search_orders'),
    "aria-label": _ctx.$t('button.search'),
    "class": "header-search-field w-full h-full text-xs appearance-none placeholder:font-normal placeholder:text-paragraph text-heading"
  }, null, 8 /* PROPS */, _hoisted_58), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, $data.props.search.order_serial_no]]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    type: "button",
    onClick: _cache[16] || (_cache[16] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
      return $options.searchReset && $options.searchReset.apply($options, arguments);
    }, ["prevent"])),
    "aria-label": _ctx.$t('button.close'),
    "class": "modal-close lab lab-close-circle-line transition invisible group-focus-within:visible"
  }, null, 8 /* PROPS */, _hoisted_59)], 32 /* NEED_HYDRATION */)])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-4",
    onClick: _cache[22] || (_cache[22] = function ($event) {
      return $options.closeFilterSlide($event);
    })
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_60, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["p-3 pb-2", $options.filteredDineinOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''])
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_61, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.dinein_orders")), 1 /* TEXT */)], 2 /* CLASS */), $options.filteredDineinOrders.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_62, " Aucune commande sur place en cours. ")) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_63, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.sortedFilteredDinein, function (dineinOrder, dIdx) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
      key: dineinOrder.id
    }, [$options.kdsDineinTableHeaderVisible(dineinOrder, dIdx) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_64, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: function onClick($event) {
        return $options.toggleTableGroup($options.dineinTableKey(dineinOrder));
      },
      "class": "w-full flex items-center justify-between gap-2 px-2 py-2 rounded-lg bg-[#F7F7FC] text-left text-sm font-semibold text-heading"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.dineinTableKey(dineinOrder)), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["fa-solid", $options.isTableGroupOpen($options.dineinTableKey(dineinOrder)) ? 'fa-chevron-up' : 'fa-chevron-down'])
    }, null, 2 /* CLASS */)], 8 /* PROPS */, _hoisted_65)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["w-full rounded-lg border transition-colors border-[#EFF0F6] relative", [$options.kdsWaitClass(dineinOrder), $data.flashTableChangeIds[dineinOrder.id] ? 'kds-table-flash' : '']]),
      role: "article",
      "aria-labelledby": 'order-' + dineinOrder.id + '-title',
      "data-kds-order-card": "dinein"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge "), $options.kdsHasOosWarning(dineinOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
      key: 0,
      "class": "kds-oos-warning-badge",
      "data-testid": "kds-oos-warning-badge",
      title: _ctx.$t('label.kds_oos_warning_tooltip'),
      "aria-label": _ctx.$t('label.kds_oos_warning_aria'),
      role: "img"
    }, "⚠ OOS", 8 /* PROPS */, _hoisted_67)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.orderHasAllergens(dineinOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      "class": "kds-allergens-badge",
      onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
        return $options.openAllergensModal(dineinOrder);
      }, ["prevent", "stop"]),
      "aria-label": _ctx.$t('label.kds_allergens_badge_aria')
    }, "⚠ " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergens_badge')), 9 /* TEXT, PROPS */, _hoisted_68)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_69, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_70, [_cache[28] || (_cache[28] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "lab lab-processing lab-font-size-16 text-[#0084FF]"
    }, null, -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      id: 'order-' + dineinOrder.id + '-title',
      "class": "text-sm font-normal"
    }, "#" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.order_serial_no), 9 /* TEXT, PROPS */, _hoisted_71), dineinOrder.queue_number ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_72, " N°" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.queue_number), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize", $options.orderStatusBadgeClasses(dineinOrder.status)])
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.status === $data.enums.orderStatusEnum.PREPARED ? _ctx.$t("label.done") : dineinOrder.status === $data.enums.orderStatusEnum.ACCEPT ? _ctx.$t("label.confirmed") : dineinOrder.status_name), 3 /* TEXT, CLASS */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_73, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_74, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.table_no")) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_75, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.table_name), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_76, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.token_no")) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_77, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.token ? dineinOrder.token : _ctx.$t("label.online")), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Sprint H4 Z3-NEW-002/003 2026-05-17] Legacy delivery\n                       block mirrored from onlineOrder lane. Helper gates on\n                       order_type === DELIVERY so this only renders for\n                       misclassified delivery orders that landed in the\n                       dine-in lane — defensive UX so chef + courier never\n                       miss a delivery on the ?v2=0 rollback path. "), $options.kdsLegacyShouldShowDelivery(dineinOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_78, [$options.kdsLegacyDeliveryAddress(dineinOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_79, [_cache[29] || (_cache[29] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📍", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_80, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsLegacyDeliveryAddress(dineinOrder)), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), dineinOrder.customer && dineinOrder.customer.name ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_81, [_cache[30] || (_cache[30] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "👤", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.customer.name), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), dineinOrder.customer && dineinOrder.customer.phone ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("a", {
      key: 2,
      href: "tel:".concat(dineinOrder.customer.phone),
      "class": "text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
    }, [_cache[31] || (_cache[31] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📱", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(dineinOrder.customer.phone), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_82)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] Chevron now exposes\n                       aria-expanded so assistive tech can announce the\n                       collapse state. The state-transition CTAs (Démarrer /\n                       Prêt) have been hoisted OUT of this accordion below so\n                       a chef in gloves can reach them in one tap without\n                       expanding line items first. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: _cache[18] || (_cache[18] = function ($event) {
        return $options.openFilterSlide($event);
      }),
      "class": "filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full",
      "aria-expanded": false,
      "aria-label": _ctx.$t('label.kds_toggle_items') || 'Afficher les articles'
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsDisplayDateTime(dineinOrder.order_datetime)), 1 /* TEXT */), _cache[32] || (_cache[32] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": "flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "icon text-[#F4501E] fa-solid fa-chevron-down"
    })], -1 /* CACHED */))], 8 /* PROPS */, _hoisted_83), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_84, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(dineinOrder.order_items, function (item, iIdx) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: item.id || iIdx,
        "class": "flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", _hoisted_85, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.quantity) + "x", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_86, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h5", _hoisted_87, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.item_name), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_variations "), Array.isArray(item.item_variations) && item.item_variations.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_88, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_variations, function (variation, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.variation_name) + ": " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.name), 1 /* TEXT */), index + 1 < item.item_variations.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_89, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [N4 FIX] Was <li> without <ul> — replaced with <div> "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_extras "), Array.isArray(item.item_extras) && item.item_extras.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_90, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_91, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.extras')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_92, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_extras, function (extra, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(extra.name), 1 /* TEXT */), index + 1 < item.item_extras.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_93, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(item.item_addons) && item.item_addons.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_94, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_95, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.addons')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_96, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_addons, function (addon, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsAddonDisplayName(addon)), 1 /* TEXT */), Number(addon.quantity || 1) > 1 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_97, " ×" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(Number(addon.quantity || 1)), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), index + 1 < item.item_addons.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_98, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [P3-1 FIX] Show instruction on dine-in cards "), item.instruction && item.instruction !== '' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: 3,
        "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$options.kdsInstructionClass(item.instruction), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']),
        style: {
          "white-space": "pre-line"
        }
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.instruction), 3 /* TEXT, CLASS */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_99, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR "), !$options.kdsIsBumped(dineinOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 0,
        type: "button",
        "class": "w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5",
        title: _ctx.$t('button.kds_bump'),
        "aria-label": "".concat(_ctx.$t('button.kds_bump'), " \u2014 ").concat(item.item_name),
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsBump(dineinOrder, item);
        }, ["prevent", "stop"])
      }, _toConsumableArray(_cache[33] || (_cache[33] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
        "class": "fa-solid fa-arrow-right",
        "aria-hidden": "true"
      }, null, -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_100)) : $options.kdsCanRecall(dineinOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 1,
        type: "button",
        "class": "text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50",
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsRecall(dineinOrder, item);
        }, ["prevent", "stop"])
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('button.kds_recall')), 9 /* TEXT, PROPS */, _hoisted_101)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])]);
    }), 128 /* KEYED_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [AUDIT-P2] Print kitchen ticket button "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: function onClick($event) {
        return $options.printKitchenTicket(dineinOrder);
      },
      "class": "rounded-lg w-full h-8 flex justify-center items-center gap-1.5 text-xs font-medium bg-[#F7F7FC] text-[#2E2F38] mb-2 hover:bg-[#EFF0F6]"
    }, _toConsumableArray(_cache[34] || (_cache[34] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "fa-solid fa-print text-xs"
    }, null, -1 /* CACHED */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" Imprimer ticket ", -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_102)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] State-transition CTAs\n                       moved OUT of the accordion. Always rendered so the chef\n                       reaches them in 1 tap on a busy kitchen tablet. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix C-041 round-8 2026-05-10] Tailwind\n                       theme.colors.primary resolves to rgb(255 0 107) (=\n                       #FF006B, hot pink/magenta) and clashes with the owner's\n                       Cayenne brand palette. Replace with arbitrary hex\n                       `bg-[#F4501E]` (Cayenne orange-red, matches mobile app\n                       and `--kiosk-bold-primary` token) on the KDS surface\n                       only, scoped to this iter15-mega Wave C round-8 fix —\n                       global Tailwind override is out of scope for this\n                       round. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_103, [dineinOrder.status === $data.enums.orderStatusEnum.ACCEPT ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 0,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(dineinOrder, $data.enums.orderStatusEnum.PREPARING);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.start_preparing")), 9 /* TEXT, PROPS */, _hoisted_104)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), dineinOrder.status === $data.enums.orderStatusEnum.PREPARING ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(dineinOrder, $data.enums.orderStatusEnum.PREPARED);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.mark_done")), 9 /* TEXT, PROPS */, _hoisted_105)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])])], 10 /* CLASS, PROPS */, _hoisted_66)], 512 /* NEED_PATCH */), [[vue__WEBPACK_IMPORTED_MODULE_0__.vShow, !$data.groupByTable || $options.isTableGroupOpen($options.dineinTableKey(dineinOrder))]])], 64 /* STABLE_FRAGMENT */);
  }), 128 /* KEYED_FRAGMENT */))]))]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_106, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["p-3 pb-2", $options.filteredOnlineOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''])
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_107, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.online_orders")), 1 /* TEXT */)], 2 /* CLASS */), $options.filteredOnlineOrders.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_108, " Aucune commande en ligne en cours. ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.filteredOnlineOrders.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 1
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.filteredOnlineOrders, function (onlineOrder) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      "class": "p-3",
      key: onlineOrder.id
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["w-full rounded-lg border transition-colors border-[#EFF0F6] relative", $options.kdsWaitClass(onlineOrder)]),
      role: "article",
      "aria-labelledby": 'order-' + onlineOrder.id + '-title',
      "data-kds-order-card": "online"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge "), $options.kdsHasOosWarning(onlineOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
      key: 0,
      "class": "kds-oos-warning-badge",
      "data-testid": "kds-oos-warning-badge",
      title: _ctx.$t('label.kds_oos_warning_tooltip'),
      "aria-label": _ctx.$t('label.kds_oos_warning_aria'),
      role: "img"
    }, "⚠ OOS", 8 /* PROPS */, _hoisted_110)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.orderHasAllergens(onlineOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      "class": "kds-allergens-badge",
      onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
        return $options.openAllergensModal(onlineOrder);
      }, ["prevent", "stop"]),
      "aria-label": _ctx.$t('label.kds_allergens_badge_aria')
    }, "⚠ " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergens_badge')), 9 /* TEXT, PROPS */, _hoisted_111)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_112, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_113, [_cache[35] || (_cache[35] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "lab lab-processing lab-font-size-16 text-[#FF8C1A]"
    }, null, -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      id: 'order-' + onlineOrder.id + '-title',
      "class": "text-sm font-normal"
    }, "#" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.order_serial_no), 9 /* TEXT, PROPS */, _hoisted_114), onlineOrder.queue_number ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_115, " N°" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.queue_number), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize", $options.orderStatusBadgeClasses(onlineOrder.status)])
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.status === $data.enums.orderStatusEnum.PREPARED ? _ctx.$t("label.done") : onlineOrder.status === $data.enums.orderStatusEnum.ACCEPT ? _ctx.$t("label.confirmed") : onlineOrder.status_name), 3 /* TEXT, CLASS */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_116, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_117, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.schedule")) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["text-heading font-medium", onlineOrder.is_advance_order === $data.enums.askEnum.YES ? '!text-[#008BBA]' : ''])
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.delivery_time), 3 /* TEXT, CLASS */)]), onlineOrder.token ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_118, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.token_no")) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_119, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.token), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Sprint 2A DEL-3 2026-05-16] Legacy delivery block —\n                       online lane = DELIVERY orders only (filter at line\n                       1690 below). Even if the operator falls back to\n                       ?v2=0, the livreur must still see address + phone +\n                       name. Backend KDSOrderDetailsResource now provides\n                       order_address + customer on every payload. "), $options.kdsLegacyShouldShowDelivery(onlineOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_120, [$options.kdsLegacyDeliveryAddress(onlineOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_121, [_cache[36] || (_cache[36] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📍", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_122, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsLegacyDeliveryAddress(onlineOrder)), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), onlineOrder.customer && onlineOrder.customer.name ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_123, [_cache[37] || (_cache[37] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "👤", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.customer.name), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), onlineOrder.customer && onlineOrder.customer.phone ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("a", {
      key: 2,
      href: "tel:".concat(onlineOrder.customer.phone),
      "class": "text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
    }, [_cache[38] || (_cache[38] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📱", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(onlineOrder.customer.phone), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_124)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] aria-expanded for SR. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: _cache[19] || (_cache[19] = function ($event) {
        return $options.openFilterSlide($event);
      }),
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["filter group text-xs font-[300] flex justify-between items-center w-full", onlineOrder.is_advance_order === $data.enums.askEnum.YES ? 'text-[#008BBA]' : 'text-[#6E7191]']),
      "aria-expanded": false,
      "aria-label": _ctx.$t('label.kds_toggle_items') || 'Afficher les articles'
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsDisplayDateTime(onlineOrder.order_datetime)), 1 /* TEXT */), _cache[39] || (_cache[39] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": "flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "icon text-[#F4501E] fa-solid fa-chevron-down"
    })], -1 /* CACHED */))], 10 /* CLASS, PROPS */, _hoisted_125), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_126, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(onlineOrder.order_items, function (item, iIdx) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: item.id || iIdx,
        "class": "flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", _hoisted_127, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.quantity) + "x", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_128, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h5", _hoisted_129, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.item_name), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_variations "), Array.isArray(item.item_variations) && item.item_variations.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_130, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_variations, function (variation, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.variation_name) + ": " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.name), 1 /* TEXT */), index + 1 < item.item_variations.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_131, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [N4 FIX] Was <li> without <ul> — replaced with <div> "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_extras "), Array.isArray(item.item_extras) && item.item_extras.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_132, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_133, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.extras')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_134, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_extras, function (extra, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(extra.name), 1 /* TEXT */), index + 1 < item.item_extras.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_135, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(item.item_addons) && item.item_addons.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_136, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_137, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.addons')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_138, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_addons, function (addon, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsAddonDisplayName(addon)), 1 /* TEXT */), Number(addon.quantity || 1) > 1 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_139, " ×" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(Number(addon.quantity || 1)), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), index + 1 < item.item_addons.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_140, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), item.instruction && item.instruction !== '' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: 3,
        "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$options.kdsInstructionClass(item.instruction), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']),
        style: {
          "white-space": "pre-line"
        }
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.instruction), 3 /* TEXT, CLASS */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_141, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR "), !$options.kdsIsBumped(onlineOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 0,
        type: "button",
        "class": "w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5",
        title: _ctx.$t('button.kds_bump'),
        "aria-label": "".concat(_ctx.$t('button.kds_bump'), " \u2014 ").concat(item.item_name),
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsBump(onlineOrder, item);
        }, ["prevent", "stop"])
      }, _toConsumableArray(_cache[40] || (_cache[40] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
        "class": "fa-solid fa-arrow-right",
        "aria-hidden": "true"
      }, null, -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_142)) : $options.kdsCanRecall(onlineOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 1,
        type: "button",
        "class": "text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50",
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsRecall(onlineOrder, item);
        }, ["prevent", "stop"])
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('button.kds_recall')), 9 /* TEXT, PROPS */, _hoisted_143)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])]);
    }), 128 /* KEYED_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [AUDIT-P2] Print kitchen ticket button "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: function onClick($event) {
        return $options.printKitchenTicket(onlineOrder);
      },
      "class": "rounded-lg w-full h-8 flex justify-center items-center gap-1.5 text-xs font-medium bg-[#F7F7FC] text-[#2E2F38] mb-2 hover:bg-[#EFF0F6]"
    }, _toConsumableArray(_cache[41] || (_cache[41] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "fa-solid fa-print text-xs"
    }, null, -1 /* CACHED */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" Imprimer ticket ", -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_144)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] CTAs hoisted out of accordion. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_145, [onlineOrder.status === $data.enums.orderStatusEnum.ACCEPT ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 0,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(onlineOrder, $data.enums.orderStatusEnum.PREPARING);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.start_preparing")), 9 /* TEXT, PROPS */, _hoisted_146)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), onlineOrder.status === $data.enums.orderStatusEnum.PREPARING ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(onlineOrder, $data.enums.orderStatusEnum.PREPARED);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.mark_done")), 9 /* TEXT, PROPS */, _hoisted_147)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])])], 10 /* CLASS, PROPS */, _hoisted_109)]);
  }), 128 /* KEYED_FRAGMENT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_148, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["p-3 pb-2", $options.filteredTakeawayOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''])
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_149, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.takeaway")), 1 /* TEXT */)], 2 /* CLASS */), $options.filteredTakeawayOrders.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_150, " Aucune commande à emporter en cours. ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.filteredTakeawayOrders.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 1
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.filteredTakeawayOrders, function (takeawayOrder) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      "class": "p-3",
      key: takeawayOrder.id
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["w-full rounded-lg border transition-colors border-[#EFF0F6] relative", $options.kdsWaitClass(takeawayOrder)]),
      role: "article",
      "aria-labelledby": 'order-' + takeawayOrder.id + '-title',
      "data-kds-order-card": "takeaway"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge "), $options.kdsHasOosWarning(takeawayOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
      key: 0,
      "class": "kds-oos-warning-badge",
      "data-testid": "kds-oos-warning-badge",
      title: _ctx.$t('label.kds_oos_warning_tooltip'),
      "aria-label": _ctx.$t('label.kds_oos_warning_aria'),
      role: "img"
    }, "⚠ OOS", 8 /* PROPS */, _hoisted_152)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.orderHasAllergens(takeawayOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      "class": "kds-allergens-badge",
      onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
        return $options.openAllergensModal(takeawayOrder);
      }, ["prevent", "stop"]),
      "aria-label": _ctx.$t('label.kds_allergens_badge_aria')
    }, "⚠ " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergens_badge')), 9 /* TEXT, PROPS */, _hoisted_153)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_154, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_155, [_cache[42] || (_cache[42] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "lab lab-processing lab-font-size-16 text-[#2D1263]"
    }, null, -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      id: 'order-' + takeawayOrder.id + '-title',
      "class": "text-sm font-normal"
    }, "#" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.order_serial_no), 9 /* TEXT, PROPS */, _hoisted_156), takeawayOrder.queue_number ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_157, " N°" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.queue_number), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.isPaymentPendingCounter(takeawayOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_158, " PAIEMENT COMPTOIR - NON REGLE ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize", $options.orderStatusBadgeClasses(takeawayOrder.status)])
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.status === $data.enums.orderStatusEnum.PREPARED ? _ctx.$t("label.done") : takeawayOrder.status === $data.enums.orderStatusEnum.ACCEPT ? _ctx.$t("label.confirmed") : takeawayOrder.status_name), 3 /* TEXT, CLASS */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_159, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_160, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.token_no")) + ": ", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_161, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.token ? takeawayOrder.token : _ctx.$t("label.online")), 1 /* TEXT */)]), takeawayOrder.queue_number ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_162, [_cache[43] || (_cache[43] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" N° file: ", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_163, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.queue_number), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Sprint H4 Z3-NEW-002/003 2026-05-17] Legacy delivery\n                       block mirrored from onlineOrder lane. Helper gates on\n                       order_type === DELIVERY so this only renders for\n                       misclassified delivery orders that landed in the\n                       takeaway lane — defensive UX for ?v2=0 rollback. "), $options.kdsLegacyShouldShowDelivery(takeawayOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_164, [$options.kdsLegacyDeliveryAddress(takeawayOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_165, [_cache[44] || (_cache[44] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📍", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_166, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsLegacyDeliveryAddress(takeawayOrder)), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), takeawayOrder.customer && takeawayOrder.customer.name ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_167, [_cache[45] || (_cache[45] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "👤", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.customer.name), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), takeawayOrder.customer && takeawayOrder.customer.phone ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("a", {
      key: 2,
      href: "tel:".concat(takeawayOrder.customer.phone),
      "class": "text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
    }, [_cache[46] || (_cache[46] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📱", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(takeawayOrder.customer.phone), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_168)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] aria-expanded for SR. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: _cache[20] || (_cache[20] = function ($event) {
        return $options.openFilterSlide($event);
      }),
      "class": "filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full",
      "aria-expanded": false,
      "aria-label": _ctx.$t('label.kds_toggle_items') || 'Afficher les articles'
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsDisplayDateTime(takeawayOrder.order_datetime)), 1 /* TEXT */), _cache[47] || (_cache[47] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": "flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "icon text-[#F4501E] fa-solid fa-chevron-down"
    })], -1 /* CACHED */))], 8 /* PROPS */, _hoisted_169), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_170, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(takeawayOrder.order_items, function (item, iIdx) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: item.id || iIdx,
        "class": "flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", _hoisted_171, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.quantity) + "x", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_172, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h5", _hoisted_173, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.item_name), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_variations "), Array.isArray(item.item_variations) && item.item_variations.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_174, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_variations, function (variation, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.variation_name) + ": " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.name), 1 /* TEXT */), index + 1 < item.item_variations.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_175, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [N4 FIX] Was <li> without <ul> — replaced with <div> "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_extras "), Array.isArray(item.item_extras) && item.item_extras.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_176, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_177, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.extras')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_178, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_extras, function (extra, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(extra.name), 1 /* TEXT */), index + 1 < item.item_extras.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_179, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(item.item_addons) && item.item_addons.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_180, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_181, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.addons')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_182, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_addons, function (addon, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsAddonDisplayName(addon)), 1 /* TEXT */), Number(addon.quantity || 1) > 1 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_183, " ×" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(Number(addon.quantity || 1)), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), index + 1 < item.item_addons.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_184, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [P3-1 FIX] Show instruction on takeaway cards "), item.instruction && item.instruction !== '' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: 3,
        "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$options.kdsInstructionClass(item.instruction), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']),
        style: {
          "white-space": "pre-line"
        }
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.instruction), 3 /* TEXT, CLASS */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_185, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR "), !$options.kdsIsBumped(takeawayOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 0,
        type: "button",
        "class": "w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5",
        title: _ctx.$t('button.kds_bump'),
        "aria-label": "".concat(_ctx.$t('button.kds_bump'), " \u2014 ").concat(item.item_name),
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsBump(takeawayOrder, item);
        }, ["prevent", "stop"])
      }, _toConsumableArray(_cache[48] || (_cache[48] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
        "class": "fa-solid fa-arrow-right",
        "aria-hidden": "true"
      }, null, -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_186)) : $options.kdsCanRecall(takeawayOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 1,
        type: "button",
        "class": "text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50",
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsRecall(takeawayOrder, item);
        }, ["prevent", "stop"])
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('button.kds_recall')), 9 /* TEXT, PROPS */, _hoisted_187)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])]);
    }), 128 /* KEYED_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [AUDIT-P2] Print kitchen ticket button "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: function onClick($event) {
        return $options.printKitchenTicket(takeawayOrder);
      },
      "class": "rounded-lg w-full h-8 flex justify-center items-center gap-1.5 text-xs font-medium bg-[#F7F7FC] text-[#2E2F38] mb-2 hover:bg-[#EFF0F6]"
    }, _toConsumableArray(_cache[49] || (_cache[49] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "fa-solid fa-print text-xs"
    }, null, -1 /* CACHED */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" Imprimer ticket ", -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_188)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] CTAs hoisted out of accordion. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_189, [takeawayOrder.status === $data.enums.orderStatusEnum.ACCEPT ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 0,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(takeawayOrder, $data.enums.orderStatusEnum.PREPARING);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.start_preparing")), 9 /* TEXT, PROPS */, _hoisted_190)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), takeawayOrder.status === $data.enums.orderStatusEnum.PREPARING ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(takeawayOrder, $data.enums.orderStatusEnum.PREPARED);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.mark_done")), 9 /* TEXT, PROPS */, _hoisted_191)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])])], 10 /* CLASS, PROPS */, _hoisted_151)]);
  }), 128 /* KEYED_FRAGMENT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Borne (Kiosk) orders column "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_192, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["p-3 pb-2 flex items-center gap-2", $options.filteredKioskOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''])
  }, [_cache[50] || (_cache[50] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", {
    "class": "text-lg font-semibold"
  }, "🖥️ Borne", -1 /* CACHED */)), $options.filteredKioskOrders.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_193, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.filteredKioskOrders.length), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)], 2 /* CLASS */), $options.filteredKioskOrders.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_194, " Aucune commande borne en cours. ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.filteredKioskOrders.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 1
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.filteredKioskOrders, function (kioskOrder) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      "class": "p-3",
      key: kioskOrder.id
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["w-full rounded-lg border transition-colors border-[#EFF0F6] relative", $options.kdsWaitClass(kioskOrder)]),
      role: "article",
      "aria-labelledby": 'order-' + kioskOrder.id + '-title',
      "data-kds-order-card": "kiosk"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge "), $options.kdsHasOosWarning(kioskOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
      key: 0,
      "class": "kds-oos-warning-badge",
      "data-testid": "kds-oos-warning-badge",
      title: _ctx.$t('label.kds_oos_warning_tooltip'),
      "aria-label": _ctx.$t('label.kds_oos_warning_aria'),
      role: "img"
    }, "⚠ OOS", 8 /* PROPS */, _hoisted_196)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.orderHasAllergens(kioskOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      "class": "kds-allergens-badge",
      onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
        return $options.openAllergensModal(kioskOrder);
      }, ["prevent", "stop"]),
      "aria-label": _ctx.$t('label.kds_allergens_badge_aria')
    }, "⚠ " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergens_badge')), 9 /* TEXT, PROPS */, _hoisted_197)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_198, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_199, [_cache[51] || (_cache[51] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "lab lab-processing lab-font-size-16 text-[#991B1B]"
    }, null, -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      id: 'order-' + kioskOrder.id + '-title',
      "class": "text-sm font-normal"
    }, "#" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(kioskOrder.order_serial_no), 9 /* TEXT, PROPS */, _hoisted_200), kioskOrder.queue_number ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_201, " N°" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(kioskOrder.queue_number), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), $options.isPaymentPendingCounter(kioskOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_202, " PAIEMENT COMPTOIR - NON REGLE ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize", $options.orderStatusBadgeClasses(kioskOrder.status)])
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(kioskOrder.status === $data.enums.orderStatusEnum.PREPARED ? _ctx.$t('label.done') : kioskOrder.status === $data.enums.orderStatusEnum.ACCEPT ? _ctx.$t('label.confirmed') : kioskOrder.status_name), 3 /* TEXT, CLASS */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_203, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Sprint H4 Z3-NEW-002/003 2026-05-17] Legacy delivery\n                       block mirrored from onlineOrder lane. Helper gates on\n                       order_type === DELIVERY so this only renders for\n                       misclassified delivery orders that landed in the\n                       kiosk lane — defensive UX for ?v2=0 rollback. "), $options.kdsLegacyShouldShowDelivery(kioskOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_204, [$options.kdsLegacyDeliveryAddress(kioskOrder) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_205, [_cache[52] || (_cache[52] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📍", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_206, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsLegacyDeliveryAddress(kioskOrder)), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), kioskOrder.customer && kioskOrder.customer.name ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_207, [_cache[53] || (_cache[53] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "👤", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(kioskOrder.customer.name), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), kioskOrder.customer && kioskOrder.customer.phone ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("a", {
      key: 2,
      href: "tel:".concat(kioskOrder.customer.phone),
      "class": "text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
    }, [_cache[54] || (_cache[54] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
      "aria-hidden": "true"
    }, "📱", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(kioskOrder.customer.phone), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_208)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] aria-expanded for SR. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: _cache[21] || (_cache[21] = function ($event) {
        return $options.openFilterSlide($event);
      }),
      "class": "filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full",
      "aria-expanded": false,
      "aria-label": _ctx.$t('label.kds_toggle_items') || 'Afficher les articles'
    }, [kioskOrder.queue_number ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_210, "N° file: " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(kioskOrder.queue_number), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsDisplayDateTime(kioskOrder.order_datetime)), 1 /* TEXT */), _cache[55] || (_cache[55] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
      "class": "flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "icon text-[#F4501E] fa-solid fa-chevron-down"
    })], -1 /* CACHED */))], 8 /* PROPS */, _hoisted_209), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_211, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(kioskOrder.order_items, function (item, iIdx) {
      return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: item.id || iIdx,
        "class": "flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none"
      }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", _hoisted_212, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.quantity) + "x", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_213, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h5", _hoisted_214, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.item_name), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_variations "), Array.isArray(item.item_variations) && item.item_variations.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_215, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_variations, function (variation, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.variation_name) + ": " + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(variation.name), 1 /* TEXT */), index + 1 < item.item_variations.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_216, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [Y2 FIX] Guard item_extras "), Array.isArray(item.item_extras) && item.item_extras.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_217, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_218, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.extras')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_219, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_extras, function (extra, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(extra.name), 1 /* TEXT */), index + 1 < item.item_extras.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_220, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(item.item_addons) && item.item_addons.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_221, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_222, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.addons')) + ":", 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_223, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.item_addons, function (addon, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: index,
          "class": "text-heading"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.kdsAddonDisplayName(addon)), 1 /* TEXT */), Number(addon.quantity || 1) > 1 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_224, " ×" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(Number(addon.quantity || 1)), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), index + 1 < item.item_addons.length ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_225, ", ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
      }), 128 /* KEYED_FRAGMENT */))])])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), Array.isArray(item.allergens_snapshot) && item.allergens_snapshot.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_226, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)(item.allergens_snapshot, function (allergen, allergenIdx) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", {
          key: "".concat(item.id || iIdx, "-allergen-").concat(allergenIdx),
          "class": "rounded-full bg-[#FFF3E8] px-2 py-0.5 text-[11px] font-medium uppercase tracking-[0.02em] text-[#C25D1B]"
        }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(allergen), 1 /* TEXT */);
      }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), item.instruction && item.instruction !== '' ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
        key: 4,
        "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([$options.kdsInstructionClass(item.instruction), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']),
        style: {
          "white-space": "pre-line"
        }
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.instruction), 3 /* TEXT, CLASS */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_227, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR "), !$options.kdsIsBumped(kioskOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 0,
        type: "button",
        "class": "w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5",
        title: _ctx.$t('button.kds_bump'),
        "aria-label": "".concat(_ctx.$t('button.kds_bump'), " \u2014 ").concat(item.item_name),
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsBump(kioskOrder, item);
        }, ["prevent", "stop"])
      }, _toConsumableArray(_cache[56] || (_cache[56] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
        "class": "fa-solid fa-arrow-right",
        "aria-hidden": "true"
      }, null, -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_228)) : $options.kdsCanRecall(kioskOrder.id, item.id) ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
        key: 1,
        type: "button",
        "class": "text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50",
        onClick: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
          return $options.kdsRecall(kioskOrder, item);
        }, ["prevent", "stop"])
      }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('button.kds_recall')), 9 /* TEXT, PROPS */, _hoisted_229)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])]);
    }), 128 /* KEYED_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [AUDIT-P2] Print kitchen ticket button "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      onClick: function onClick($event) {
        return $options.printKitchenTicket(kioskOrder);
      },
      "class": "rounded-lg w-full h-8 flex justify-center items-center gap-1.5 text-xs font-medium bg-[#F7F7FC] text-[#2E2F38] mb-2 hover:bg-[#EFF0F6]"
    }, _toConsumableArray(_cache[57] || (_cache[57] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": "fa-solid fa-print text-xs"
    }, null, -1 /* CACHED */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" Imprimer ticket ", -1 /* CACHED */)])), 8 /* PROPS */, _hoisted_230)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [iter15-mega-fix B-002 2026-05-10] CTAs hoisted out of accordion. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_231, [kioskOrder.status === $data.enums.orderStatusEnum.ACCEPT ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 0,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(kioskOrder, $data.enums.orderStatusEnum.PREPARING);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.start_preparing')), 9 /* TEXT, PROPS */, _hoisted_232)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), kioskOrder.status === $data.enums.orderStatusEnum.PREPARING ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: 1,
      type: "button",
      onClick: function onClick($event) {
        return $options.orderStatus(kioskOrder, $data.enums.orderStatusEnum.PREPARED);
      },
      "class": "rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.mark_done')), 9 /* TEXT, PROPS */, _hoisted_233)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])])], 10 /* CLASS, PROPS */, _hoisted_195)]);
  }), 128 /* KEYED_FRAGMENT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])])])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [CV1-KDS-A11Y-RICH-001] Off-screen aria-live region for transition\n         announcements (ACCEPT → PREPARING → PREPARED). Polite politeness so\n         it does not interrupt the cuisinier's current focus, and visually\n         hidden so it never reflows the kitchen layout. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_234, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($data.kdsAriaLiveMessage), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" [F-03 / Lot 1.C] Adaptive sync badge: reassures the kitchen that the\n         board is up to date even when WebSocket is degraded. Color shifts to\n         orange after 30s without sync. "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_235, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["kds-sync-stamp", {
      'kds-sync-stamp--stale': $options.syncBadgeIsStale
    }]),
    title: $options.syncBadgeText
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.syncBadgeText), 11 /* TEXT, CLASS, PROPS */, _hoisted_236)]), $data.allergensModal.open ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    key: 0,
    ref: "allergensModalRoot",
    "class": "kds-allergens-modal-overlay",
    role: "dialog",
    "aria-modal": "true",
    "aria-label": _ctx.$t('label.kds_allergens_modal_title', {
      order_id: $data.allergensModal.order && $data.allergensModal.order.id ? $data.allergensModal.order.id : ''
    }),
    tabindex: "-1",
    onClick: _cache[24] || (_cache[24] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
      return $options.closeAllergensModal && $options.closeAllergensModal.apply($options, arguments);
    }, ["self"])),
    onKeydown: [_cache[25] || (_cache[25] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)(function () {
      return $options.closeAllergensModal && $options.closeAllergensModal.apply($options, arguments);
    }, ["esc"])), _cache[26] || (_cache[26] = function () {
      return $options.onAllergensModalKeydown && $options.onAllergensModalKeydown.apply($options, arguments);
    })]
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_238, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("header", _hoisted_239, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h2", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergens_modal_title', {
    order_id: $data.allergensModal.order && $data.allergensModal.order.id ? $data.allergensModal.order.id : ''
  })), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    ref: "allergensModalCloseButton",
    type: "button",
    "class": "kds-allergens-modal-close",
    onClick: _cache[23] || (_cache[23] = function () {
      return $options.closeAllergensModal && $options.closeAllergensModal.apply($options, arguments);
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('button.kds_allergens_modal_close')), 513 /* TEXT, NEED_PATCH */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_240, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.kds_allergens_modal_intro')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("ul", _hoisted_241, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.allergensModalItems, function (orderItem, allergenIndex) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
      key: (orderItem.id || orderItem.item_id || allergenIndex) + '-' + allergenIndex,
      "class": "kds-allergens-modal-list-item"
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("strong", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(orderItem.item_name || orderItem.name || orderItem.item && orderItem.item.name || '-'), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.sortedAllergens(orderItem.allergens_snapshot).length ? $options.sortedAllergens(orderItem.allergens_snapshot).join(" \xB7 ") : _ctx.$t('label.kds_allergens_modal_none')), 1 /* TEXT */)]);
  }), 128 /* KEYED_FRAGMENT */))])])], 40 /* PROPS, NEED_HYDRATION */, _hoisted_237)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])], 64 /* STABLE_FRAGMENT */))], 2112 /* STABLE_FRAGMENT, DEV_ROOT_FRAGMENT */);
}

/***/ },

/***/ "./resources/js/helpers/kdsAllergens.js"
/*!**********************************************!*\
  !*** ./resources/js/helpers/kdsAllergens.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   normalizeAllergensForHash: () => (/* binding */ normalizeAllergensForHash),
/* harmony export */   orderHasAllergens: () => (/* binding */ orderHasAllergens),
/* harmony export */   sortedAllergens: () => (/* binding */ sortedAllergens)
/* harmony export */ });
/**
 * KDS allergens helpers (Lot 2.I / G-4 + G-5).
 *
 * Pure functions intentionally extracted from KitchenDisplaySystemComponent
 * so they can be unit-tested without mounting the heavyweight component.
 *
 * The backend (KitchenDisplaySystemOrderService::normalizeAllergensForHash)
 * applies the SAME normalization rules so that:
 *   - null and [] hash identically (no-allergens lines merge)
 *   - duplicate / unsorted entries merge ([gluten, peanuts] vs [peanuts, gluten])
 *   - non-array shapes degrade to []
 * Keep both sides in sync if you change one.
 */

/**
 * @param {*} order
 * @returns {boolean}
 */
function orderHasAllergens(order) {
  if (!order) return false;
  var items = order.orderItems || order.order_items;
  if (!Array.isArray(items)) return false;
  for (var i = 0; i < items.length; i++) {
    var snapshot = items[i] && items[i].allergens_snapshot;
    if (Array.isArray(snapshot) && snapshot.length > 0) {
      return true;
    }
  }
  return false;
}

/**
 * Deterministic display order: filter empty/null, dedupe, alphabetical sort.
 * @param {*} snapshot
 * @returns {string[]}
 */
function sortedAllergens(snapshot) {
  if (!Array.isArray(snapshot)) return [];
  var cleaned = snapshot.filter(function (v) {
    return v !== null && v !== undefined && v !== '';
  }).map(function (v) {
    return String(v);
  });
  return Array.from(new Set(cleaned)).sort();
}

/**
 * Mirror of the backend `normalizeAllergensForHash` shape (without the sha1).
 * Useful for client-side parity checks in tests.
 * @param {*} snapshot
 * @returns {string[]}
 */
function normalizeAllergensForHash(snapshot) {
  return sortedAllergens(snapshot);
}

/***/ },

/***/ "./resources/js/helpers/kdsAutoTransition.js"
/*!***************************************************!*\
  !*** ./resources/js/helpers/kdsAutoTransition.js ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   pickOldestAutoPromoteCandidate: () => (/* binding */ pickOldestAutoPromoteCandidate),
/* harmony export */   shouldAutoTransition: () => (/* binding */ shouldAutoTransition)
/* harmony export */ });
/* harmony import */ var _kdsState_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./kdsState.js */ "./resources/js/helpers/kdsState.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
/**
 * [kds/sprint-2 F-4] Conditional auto-transition rule NEW → PREPARING.
 *
 * Justification (RESEARCH_KDS_MODERN_2024_2026 §4.3): no documented modern
 * KDS auto-promotes — every system (Toast, Lightspeed 2.0, Otter, Wingstop,
 * Fresh) requires an explicit chef action. This is a deliberate departure
 * for FoodKing V1 single-chef takeaway-only:
 *   - only ONE order can physically be in prep at once
 *   - the system can infer "started prep" with near-zero false-positive risk
 *   - removes one tap per order (saves wet-glove touches)
 *   - degrades cleanly to manual the moment a second chef / second station
 *     arrives (the "zero PREPARING" condition becomes rarely satisfied)
 *
 * Feature flag: `window.kdsAutoTransitionEnabled` (default ON). Owner can
 * disable from settings menu when multi-station ships.
 *
 * Server safety: the existing OrderStateMachine::apply lockForUpdate +
 * idempotent early-return makes this safe under any race between client
 * watchers and concurrent operator action.
 */



/**
 * Decide whether `incoming` should be auto-promoted to PREPARING right now.
 *
 * @param {object} incoming         the order that just entered/refreshed
 * @param {Array}  currentQueue     all KDS orders currently visible (incl. incoming)
 * @param {boolean} [featureFlag]   default true
 * @returns {boolean}
 */
function shouldAutoTransition(incoming, currentQueue) {
  var _incoming$status;
  var featureFlag = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : true;
  if (!featureFlag) {
    return false;
  }
  if (incoming == null || _typeof(incoming) !== 'object') {
    return false;
  }
  var rawStatus = parseInt((_incoming$status = incoming.status) !== null && _incoming$status !== void 0 ? _incoming$status : incoming.rawStatus, 10);
  if (rawStatus !== _kdsState_js__WEBPACK_IMPORTED_MODULE_0__.ORDER_STATUS.ACCEPT) {
    return false;
  }
  var queue = Array.isArray(currentQueue) ? currentQueue : [];
  var anyPreparing = queue.some(function (o) {
    var _o$status;
    var s = parseInt((_o$status = o === null || o === void 0 ? void 0 : o.status) !== null && _o$status !== void 0 ? _o$status : o === null || o === void 0 ? void 0 : o.rawStatus, 10);
    return s === _kdsState_js__WEBPACK_IMPORTED_MODULE_0__.ORDER_STATUS.PREPARING;
  });
  return !anyPreparing;
}

/**
 * Pick the single oldest NEW order to auto-promote when the queue refreshes.
 * Used by the store watcher after a `lists` action returns. Stable tie-breaker:
 * created_at then id.
 *
 * @param {Array} queue
 * @returns {object|null}
 */
function pickOldestAutoPromoteCandidate(queue) {
  if (!Array.isArray(queue) || queue.length === 0) {
    return null;
  }
  var anyPreparing = queue.some(function (o) {
    return parseInt(o === null || o === void 0 ? void 0 : o.status, 10) === _kdsState_js__WEBPACK_IMPORTED_MODULE_0__.ORDER_STATUS.PREPARING;
  });
  if (anyPreparing) {
    return null;
  }
  var news = queue.filter(function (o) {
    return parseInt(o === null || o === void 0 ? void 0 : o.status, 10) === _kdsState_js__WEBPACK_IMPORTED_MODULE_0__.ORDER_STATUS.ACCEPT;
  });
  if (news.length === 0) {
    return null;
  }
  news.sort(function (a, b) {
    var ta = (a === null || a === void 0 ? void 0 : a.created_at_iso) || (a === null || a === void 0 ? void 0 : a.created_at) || (a === null || a === void 0 ? void 0 : a.order_datetime) || '';
    var tb = (b === null || b === void 0 ? void 0 : b.created_at_iso) || (b === null || b === void 0 ? void 0 : b.created_at) || (b === null || b === void 0 ? void 0 : b.order_datetime) || '';
    if (ta !== tb) {
      return ta < tb ? -1 : 1;
    }
    var ia = parseInt(a === null || a === void 0 ? void 0 : a.id, 10) || 0;
    var ib = parseInt(b === null || b === void 0 ? void 0 : b.id, 10) || 0;
    return ia - ib;
  });
  return news[0];
}

/***/ },

/***/ "./resources/js/helpers/kdsCustomization.js"
/*!**************************************************!*\
  !*** ./resources/js/helpers/kdsCustomization.js ***!
  \**************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   categorize: () => (/* binding */ categorize),
/* harmony export */   orderHasAnyAllergen: () => (/* binding */ orderHasAnyAllergen),
/* harmony export */   renderItem: () => (/* binding */ renderItem)
/* harmony export */ });
/* harmony import */ var _kdsLineSemantics_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./kdsLineSemantics.js */ "./resources/js/helpers/kdsLineSemantics.js");
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t["return"] || t["return"](); } finally { if (u) throw o; } } }; }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
/**
 * [kds/sprint-2 F-3] Adaptive customization renderer — the single source of
 * truth that converts an OrderItem (post-T07 composition_snapshot or legacy
 * item_variations/item_extras/item_addons) into a flat list of typed display
 * nodes consumed by <KdsOrderLine>.
 *
 * Vue templates MUST NOT branch per-category — they iterate `renderItem().lines`.
 * Adding a new category = one branch here + (optionally) one i18n group key.
 *
 * Per-category contract (DESIGN_SPEC_KDS_V2 §3.1):
 *   SANDWICH    : header + grouped variations (Pain/Crudités/Sauce/Cuisson)
 *                 + extras (yellow italic supplement)
 *                 + addons (menu_full/menu_frites/menu_boisson → menu_child)
 *   TACO/BURGER : same as sandwich, omit Pain group
 *   ASSIETTE    : header + comma-joined variations on one "Avec :" line
 *   MENU_FORMULE: header + addons indented as children
 *   SIDE/DRINK/DESSERT/OTHER: header + extras only, no variations grouping
 */



// Group keys are surfaced to i18n via `label.kds_group_<key>`.
// Heuristic-keyword regex per group. The first match wins.
var GROUP_PATTERNS = [{
  key: 'bread',
  re: /\bpain\b|\bbread\b/i
}, {
  key: 'crudites',
  re: /crudit|crudity|salade|legum|vegetab/i
}, {
  key: 'sauce',
  re: /\bsauce\b/i
}, {
  key: 'cooking',
  re: /cuisson|cook|temperature/i
}, {
  key: 'drink',
  re: /boisson|drink|beverage/i
}, {
  key: 'size',
  re: /\btaille\b|\bsize\b|\bportion\b/i
}];
function classifyGroup(variationName, attributeName) {
  var name = "".concat(variationName || '', " ").concat(attributeName || '');
  var _iterator = _createForOfIteratorHelper(GROUP_PATTERNS),
    _step;
  try {
    for (_iterator.s(); !(_step = _iterator.n()).done;) {
      var g = _step.value;
      if (g.re.test(name)) {
        return g.key;
      }
    }
  } catch (err) {
    _iterator.e(err);
  } finally {
    _iterator.f();
  }
  return 'other';
}

/**
 * Classify an item into a category. Server can override this by populating
 * a future `items.kds_category` column; until then, name-based fallback.
 *
 * @param {object} orderItem
 * @returns {'sandwich'|'taco'|'burger'|'assiette'|'menu_formule'|'side'|'drink'|'dessert'|'other'}
 */
function categorize(orderItem) {
  var declared = String((orderItem === null || orderItem === void 0 ? void 0 : orderItem.kds_category) || '').toLowerCase();
  if (['sandwich', 'taco', 'burger', 'assiette', 'menu_formule', 'side', 'drink', 'dessert', 'other'].includes(declared)) {
    return declared;
  }
  var name = String((orderItem === null || orderItem === void 0 ? void 0 : orderItem.item_name) || '').toLowerCase();
  if (/menu|formule/.test(name)) {
    return 'menu_formule';
  }
  if (/sandwich|kafteji|brick/.test(name)) {
    return 'sandwich';
  }
  if (/\btacos?\b/.test(name)) {
    return 'taco';
  }
  if (/burger|cheeseburger/.test(name)) {
    return 'burger';
  }
  if (/assiette|couscous|ojja|lablabi/.test(name)) {
    return 'assiette';
  }
  if (/frite|onion ring/.test(name)) {
    return 'side';
  }
  if (/coca|fanta|sprite|eau|coke|water|jus|the|thé|menthe|cafe|café/.test(name)) {
    return 'drink';
  }
  if (/dessert|crepe|crêpe|gateau|gâteau|glace|tiramisu/.test(name)) {
    return 'dessert';
  }
  return 'other';
}
function readVariations(orderItem) {
  // Post-T07: composition_snapshot.lines wins (richer shape with attribute_name).
  var snap = orderItem === null || orderItem === void 0 ? void 0 : orderItem.composition_snapshot;
  if (snap && Array.isArray(snap.lines) && snap.lines.length > 0) {
    return snap.lines;
  }
  return Array.isArray(orderItem === null || orderItem === void 0 ? void 0 : orderItem.item_variations) ? orderItem.item_variations : [];
}
function readExtras(orderItem) {
  var snap = orderItem === null || orderItem === void 0 ? void 0 : orderItem.composition_snapshot;
  if (snap && Array.isArray(snap.extras) && snap.extras.length > 0) {
    return snap.extras;
  }
  return Array.isArray(orderItem === null || orderItem === void 0 ? void 0 : orderItem.item_extras) ? orderItem.item_extras : [];
}
function readAddons(orderItem) {
  var snap = orderItem === null || orderItem === void 0 ? void 0 : orderItem.composition_snapshot;
  if (snap && Array.isArray(snap.addons) && snap.addons.length > 0) {
    return snap.addons;
  }
  return Array.isArray(orderItem === null || orderItem === void 0 ? void 0 : orderItem.item_addons) ? orderItem.item_addons : [];
}
function variationLabel(v) {
  return (v === null || v === void 0 ? void 0 : v.name) || (v === null || v === void 0 ? void 0 : v.variation_name) || '';
}
function extraLabel(e) {
  return (e === null || e === void 0 ? void 0 : e.name) || (e === null || e === void 0 ? void 0 : e.extra_name) || '';
}
function addonLabel(a) {
  return (a === null || a === void 0 ? void 0 : a.addon_name) || (a === null || a === void 0 ? void 0 : a.name) || '';
}

/**
 * Render an order item into a flat typed line list.
 *
 * Line types:
 *   'header'        — the item itself (qty + name + allergen icon)
 *   'variation'     — grouped or single variation row (Pain / Crudités / …)
 *   'variation-flat'— the assiette "Avec : a, b, c" one-liner
 *   'supplement'    — paid extra "+ Cheddar"
 *   'addon'         — generic addon (rare)
 *   'menu_child'    — addon with role startsWith 'menu_'
 *   'instruction'   — free-text note (classified note/exclusion/allergen)
 *   'allergen'      — explicit allergens_snapshot codes block
 *
 * @param {object} orderItem
 * @returns {{ category: string, hasAllergen: boolean, lines: Array<object> }}
 */
function renderItem(orderItem) {
  var _orderItem$quantity;
  var category = categorize(orderItem);
  var lines = [];
  var allergenCodes = Array.isArray(orderItem === null || orderItem === void 0 ? void 0 : orderItem.allergens_snapshot) ? orderItem.allergens_snapshot.filter(function (c) {
    return typeof c === 'string' && c.length > 0;
  }) : [];
  var itemAllergen = allergenCodes.length > 0;
  lines.push({
    type: 'header',
    qty: (_orderItem$quantity = orderItem === null || orderItem === void 0 ? void 0 : orderItem.quantity) !== null && _orderItem$quantity !== void 0 ? _orderItem$quantity : 1,
    label: (orderItem === null || orderItem === void 0 ? void 0 : orderItem.item_name) || '',
    category: category,
    hasAllergen: itemAllergen
  });
  var vars = readVariations(orderItem);
  if (vars.length > 0) {
    if (category === 'assiette') {
      var joined = vars.map(function (v) {
        var q = parseInt(v === null || v === void 0 ? void 0 : v.quantity, 10);
        var prefix = Number.isFinite(q) && q > 1 ? "".concat(q, " ") : '';
        return "".concat(prefix).concat(variationLabel(v));
      }).filter(function (s) {
        return s.trim().length > 0;
      }).join(', ');
      if (joined.length > 0) {
        lines.push({
          type: 'variation-flat',
          group: 'avec',
          label: joined
        });
      }
    } else {
      // Group by classified attribute name. SANDWICH renders Pain, others omit.
      var byGroup = new Map();
      var _iterator2 = _createForOfIteratorHelper(vars),
        _step2;
      try {
        for (_iterator2.s(); !(_step2 = _iterator2.n()).done;) {
          var v = _step2.value;
          var g = classifyGroup(v === null || v === void 0 ? void 0 : v.variation_name, v === null || v === void 0 ? void 0 : v.attribute_name);
          if (g === 'bread' && (category === 'taco' || category === 'burger')) {
            // taco/burger: skip the Pain group (no bread choice)
            continue;
          }
          if (!byGroup.has(g)) {
            byGroup.set(g, []);
          }
          byGroup.get(g).push(variationLabel(v));
        }
      } catch (err) {
        _iterator2.e(err);
      } finally {
        _iterator2.f();
      }
      var _iterator3 = _createForOfIteratorHelper(byGroup.entries()),
        _step3;
      try {
        for (_iterator3.s(); !(_step3 = _iterator3.n()).done;) {
          var _step3$value = _slicedToArray(_step3.value, 2),
            group = _step3$value[0],
            labels = _step3$value[1];
          lines.push({
            type: 'variation',
            group: group,
            label: labels.filter(function (l) {
              return l.length > 0;
            }).join(', ')
          });
        }
      } catch (err) {
        _iterator3.e(err);
      } finally {
        _iterator3.f();
      }
    }
  }

  // Paid supplements — yellow italic, "+" prefix.
  var _iterator4 = _createForOfIteratorHelper(readExtras(orderItem)),
    _step4;
  try {
    for (_iterator4.s(); !(_step4 = _iterator4.n()).done;) {
      var e = _step4.value;
      var label = extraLabel(e);
      if (!label) continue;
      var q = parseInt(e === null || e === void 0 ? void 0 : e.quantity, 10);
      var suffix = Number.isFinite(q) && q > 1 ? " \xD7".concat(q) : '';
      lines.push({
        type: 'supplement',
        label: "+ ".concat(label).concat(suffix)
      });
    }

    // Menu Formule children (composition_snapshot.addons[].role startsWith 'menu_').
  } catch (err) {
    _iterator4.e(err);
  } finally {
    _iterator4.f();
  }
  var _iterator5 = _createForOfIteratorHelper(readAddons(orderItem)),
    _step5;
  try {
    for (_iterator5.s(); !(_step5 = _iterator5.n()).done;) {
      var a = _step5.value;
      var _label = addonLabel(a);
      if (!_label) continue;
      var role = String((a === null || a === void 0 ? void 0 : a.role) || '').toLowerCase();
      var isMenuChild = role.startsWith('menu_');
      var _q = parseInt(a === null || a === void 0 ? void 0 : a.quantity, 10);
      var qtyPrefix = Number.isFinite(_q) && _q > 1 ? "".concat(_q, "\xD7 ") : '';
      lines.push({
        type: isMenuChild ? 'menu_child' : 'addon',
        label: "".concat(qtyPrefix).concat(_label),
        role: role
      });
    }

    // Free-text instruction — keyword-classified.
  } catch (err) {
    _iterator5.e(err);
  } finally {
    _iterator5.f();
  }
  var instruction = ((orderItem === null || orderItem === void 0 ? void 0 : orderItem.instruction) || '').trim();
  if (instruction.length > 0) {
    lines.push({
      type: 'instruction',
      label: instruction,
      visualClass: (0,_kdsLineSemantics_js__WEBPACK_IMPORTED_MODULE_0__.kdsInstructionVisualClass)(instruction)
    });
  }

  // Allergens_snapshot codes (separate from instruction-keyword detection).
  if (itemAllergen) {
    lines.push({
      type: 'allergen',
      codes: allergenCodes
    });
  }
  return {
    category: category,
    hasAllergen: itemAllergen,
    lines: lines
  };
}

/**
 * Aggregate `hasAllergen` across an entire order — used to flip the card-level
 * orange border override regardless of age.
 *
 * @param {Array} orderItems
 * @returns {boolean}
 */
function orderHasAnyAllergen(orderItems) {
  if (!Array.isArray(orderItems)) {
    return false;
  }
  var _iterator6 = _createForOfIteratorHelper(orderItems),
    _step6;
  try {
    for (_iterator6.s(); !(_step6 = _iterator6.n()).done;) {
      var it = _step6.value;
      var codes = Array.isArray(it === null || it === void 0 ? void 0 : it.allergens_snapshot) ? it.allergens_snapshot : [];
      if (codes.some(function (c) {
        return typeof c === 'string' && c.length > 0;
      })) {
        return true;
      }
    }
  } catch (err) {
    _iterator6.e(err);
  } finally {
    _iterator6.f();
  }
  return false;
}

/***/ },

/***/ "./resources/js/helpers/kdsDisplay.js"
/*!********************************************!*\
  !*** ./resources/js/helpers/kdsDisplay.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   KDS_AGE_CRITICAL_MS: () => (/* binding */ KDS_AGE_CRITICAL_MS),
/* harmony export */   KDS_AGE_WARNING_MS: () => (/* binding */ KDS_AGE_WARNING_MS),
/* harmony export */   KDS_NEW_ORDER_SOUND_MIN_INTERVAL_MS: () => (/* binding */ KDS_NEW_ORDER_SOUND_MIN_INTERVAL_MS),
/* harmony export */   LS_GROUP_BY_TABLE: () => (/* binding */ LS_GROUP_BY_TABLE),
/* harmony export */   LS_STATION_FILTER: () => (/* binding */ LS_STATION_FILTER),
/* harmony export */   filterOrdersByStation: () => (/* binding */ filterOrdersByStation),
/* harmony export */   getKdsAgeBucket: () => (/* binding */ getKdsAgeBucket),
/* harmony export */   getKdsEscalationClass: () => (/* binding */ getKdsEscalationClass),
/* harmony export */   kdsStationFilterStorageKey: () => (/* binding */ kdsStationFilterStorageKey),
/* harmony export */   normalizeKdsStation: () => (/* binding */ normalizeKdsStation),
/* harmony export */   orderMatchesStationFilter: () => (/* binding */ orderMatchesStationFilter),
/* harmony export */   parseOrderCreatedMs: () => (/* binding */ parseOrderCreatedMs),
/* harmony export */   shouldPlayKdsNewOrderSound: () => (/* binding */ shouldPlayKdsNewOrderSound)
/* harmony export */ });
/**
 * Pure helpers for KDS station filter + wait-time escalation styling (unit-tested).
 */

/** [Lot 2.C / F-07] Minimum gap between "new order" chimes when WS floods. */
var KDS_NEW_ORDER_SOUND_MIN_INTERVAL_MS = 2500;

/**
 * @param {number} lastPlayedAt
 * @param {number} [nowMs]
 * @returns {boolean}
 */
function shouldPlayKdsNewOrderSound(lastPlayedAt) {
  var nowMs = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : Date.now();
  if (lastPlayedAt == null || !Number.isFinite(lastPlayedAt)) {
    return true;
  }
  return nowMs - lastPlayedAt >= KDS_NEW_ORDER_SOUND_MIN_INTERVAL_MS;
}
var LS_STATION_FILTER = 'kds.station_filter';
var LS_GROUP_BY_TABLE = 'kds.group_by_table';

/**
 * [Lot 2.F / F-10] Station filter is persisted per logged-in user so two staff
 * on the same browser profile do not overwrite each other’s KDS view.
 * Falls back to the legacy unscoped key when `userId` is missing/invalid.
 */
function kdsStationFilterStorageKey(userId) {
  var n = userId == null || userId === '' ? 0 : parseInt(userId, 10);
  if (!Number.isFinite(n) || n <= 0) {
    return LS_STATION_FILTER;
  }
  return "".concat(LS_STATION_FILTER, ".u").concat(n);
}
var STATIONS = ['bar', 'cuisine_chaude', 'cuisine_froide', 'none'];
function normalizeKdsStation(raw) {
  var v = raw == null || raw === '' ? 'none' : String(raw);
  return STATIONS.includes(v) ? v : 'none';
}
function orderMatchesStationFilter(order, filter) {
  if (!filter || filter === 'all') {
    return true;
  }
  var items = order.order_items || [];
  return items.some(function (line) {
    return normalizeKdsStation(line.kds_station) === filter;
  });
}
function filterOrdersByStation(orders, filter) {
  if (!filter || filter === 'all') {
    return orders || [];
  }
  return (orders || []).filter(function (o) {
    return orderMatchesStationFilter(o, filter);
  });
}

// [kds/sprint-2 F-5] Age thresholds tightened from 5/10 min to 3/6 min for V1
// takeaway-only single-chef workflow (Innovorder publishes 3/10 for fast-food,
// our V1 narrows the red-zone because takeaway prep target is < 5 min).
// Returning the bucket name explicitly (in addition to the legacy CSS class)
// lets the new card components decide their own visual treatment.
var KDS_AGE_WARNING_MS = 3 * 60 * 1000;
var KDS_AGE_CRITICAL_MS = 6 * 60 * 1000;
function getKdsAgeBucket(createdMs) {
  var nowMs = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : Date.now();
  var age = nowMs - createdMs;
  if (age < KDS_AGE_WARNING_MS) {
    return 'fresh';
  }
  if (age < KDS_AGE_CRITICAL_MS) {
    return 'warning';
  }
  return 'critical';
}
function getKdsEscalationClass(createdMs) {
  var nowMs = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : Date.now();
  var bucket = getKdsAgeBucket(createdMs, nowMs);
  if (bucket === 'fresh') {
    return 'kds-wait-green';
  }
  if (bucket === 'warning') {
    return 'kds-wait-orange';
  }
  return 'kds-wait-red animate-pulse';
}
function parseOrderCreatedMs(order) {
  // [kds/sprint-2 F-5] Prefer created_at_iso (added to KDSOrderDetailsResource
  // in B-1) over the locale-formatted order_datetime — stable wire format,
  // no tz drift, no parsing ambiguity.
  if (order.created_at_iso) {
    var t = Date.parse(order.created_at_iso);
    if (!Number.isNaN(t)) {
      return t;
    }
  }
  if (order.created_at) {
    var _t = Date.parse(order.created_at);
    if (!Number.isNaN(_t)) {
      return _t;
    }
  }
  if (order.order_datetime) {
    var t2 = Date.parse(order.order_datetime);
    if (!Number.isNaN(t2)) {
      return t2;
    }
  }
  return Date.now();
}

/***/ },

/***/ "./resources/js/helpers/kdsLineSemantics.js"
/*!**************************************************!*\
  !*** ./resources/js/helpers/kdsLineSemantics.js ***!
  \**************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   isLikelyExclusionOrHoldInstruction: () => (/* binding */ isLikelyExclusionOrHoldInstruction),
/* harmony export */   kdsInstructionVisualClass: () => (/* binding */ kdsInstructionVisualClass)
/* harmony export */ });
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t["return"] || t["return"](); } finally { if (u) throw o; } } }; }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
/**
 * KDS: classify free-text instruction lines for *visual* emphasis (no medical decision).
 * Structured allergens_snapshot from the API always wins in the UI if shown separately;
 * this only highlights likely exclusions / hold / allergy *words* in instruction text.
 */

var EXCLUSION_RE = [/\b([s\u017F]an[s\u017F])[\t-\r \xA0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+(?:['\x2D0-9A-Za-z\xAA\xB5\xBA\xC0-\xD6\xD8-\xF6\xF8-\u02C1\u02C6-\u02D1\u02E0-\u02E4\u02EC\u02EE\u0345\u0370-\u0374\u0376\u0377\u037A-\u037D\u037F\u0386\u0388-\u038A\u038C\u038E-\u03A1\u03A3-\u03F5\u03F7-\u0481\u048A-\u052F\u0531-\u0556\u0559\u0560-\u0588\u05D0-\u05EA\u05EF-\u05F2\u0620-\u064A\u066E\u066F\u0671-\u06D3\u06D5\u06E5\u06E6\u06EE\u06EF\u06FA-\u06FC\u06FF\u0710\u0712-\u072F\u074D-\u07A5\u07B1\u07CA-\u07EA\u07F4\u07F5\u07FA\u0800-\u0815\u081A\u0824\u0828\u0840-\u0858\u0860-\u086A\u0870-\u0887\u0889-\u088F\u08A0-\u08C9\u0904-\u0939\u093D\u0950\u0958-\u0961\u0971-\u0980\u0985-\u098C\u098F\u0990\u0993-\u09A8\u09AA-\u09B0\u09B2\u09B6-\u09B9\u09BD\u09CE\u09DC\u09DD\u09DF-\u09E1\u09F0\u09F1\u09FC\u0A05-\u0A0A\u0A0F\u0A10\u0A13-\u0A28\u0A2A-\u0A30\u0A32\u0A33\u0A35\u0A36\u0A38\u0A39\u0A59-\u0A5C\u0A5E\u0A72-\u0A74\u0A85-\u0A8D\u0A8F-\u0A91\u0A93-\u0AA8\u0AAA-\u0AB0\u0AB2\u0AB3\u0AB5-\u0AB9\u0ABD\u0AD0\u0AE0\u0AE1\u0AF9\u0B05-\u0B0C\u0B0F\u0B10\u0B13-\u0B28\u0B2A-\u0B30\u0B32\u0B33\u0B35-\u0B39\u0B3D\u0B5C\u0B5D\u0B5F-\u0B61\u0B71\u0B83\u0B85-\u0B8A\u0B8E-\u0B90\u0B92-\u0B95\u0B99\u0B9A\u0B9C\u0B9E\u0B9F\u0BA3\u0BA4\u0BA8-\u0BAA\u0BAE-\u0BB9\u0BD0\u0C05-\u0C0C\u0C0E-\u0C10\u0C12-\u0C28\u0C2A-\u0C39\u0C3D\u0C58-\u0C5A\u0C5C\u0C5D\u0C60\u0C61\u0C80\u0C85-\u0C8C\u0C8E-\u0C90\u0C92-\u0CA8\u0CAA-\u0CB3\u0CB5-\u0CB9\u0CBD\u0CDC-\u0CDE\u0CE0\u0CE1\u0CF1\u0CF2\u0D04-\u0D0C\u0D0E-\u0D10\u0D12-\u0D3A\u0D3D\u0D4E\u0D54-\u0D56\u0D5F-\u0D61\u0D7A-\u0D7F\u0D85-\u0D96\u0D9A-\u0DB1\u0DB3-\u0DBB\u0DBD\u0DC0-\u0DC6\u0E01-\u0E30\u0E32\u0E33\u0E40-\u0E46\u0E81\u0E82\u0E84\u0E86-\u0E8A\u0E8C-\u0EA3\u0EA5\u0EA7-\u0EB0\u0EB2\u0EB3\u0EBD\u0EC0-\u0EC4\u0EC6\u0EDC-\u0EDF\u0F00\u0F40-\u0F47\u0F49-\u0F6C\u0F88-\u0F8C\u1000-\u102A\u103F\u1050-\u1055\u105A-\u105D\u1061\u1065\u1066\u106E-\u1070\u1075-\u1081\u108E\u10A0-\u10C5\u10C7\u10CD\u10D0-\u10FA\u10FC-\u1248\u124A-\u124D\u1250-\u1256\u1258\u125A-\u125D\u1260-\u1288\u128A-\u128D\u1290-\u12B0\u12B2-\u12B5\u12B8-\u12BE\u12C0\u12C2-\u12C5\u12C8-\u12D6\u12D8-\u1310\u1312-\u1315\u1318-\u135A\u1380-\u138F\u13A0-\u13F5\u13F8-\u13FD\u1401-\u166C\u166F-\u167F\u1681-\u169A\u16A0-\u16EA\u16F1-\u16F8\u1700-\u1711\u171F-\u1731\u1740-\u1751\u1760-\u176C\u176E-\u1770\u1780-\u17B3\u17D7\u17DC\u1820-\u1878\u1880-\u1884\u1887-\u18A8\u18AA\u18B0-\u18F5\u1900-\u191E\u1950-\u196D\u1970-\u1974\u1980-\u19AB\u19B0-\u19C9\u1A00-\u1A16\u1A20-\u1A54\u1AA7\u1B05-\u1B33\u1B45-\u1B4C\u1B83-\u1BA0\u1BAE\u1BAF\u1BBA-\u1BE5\u1C00-\u1C23\u1C4D-\u1C4F\u1C5A-\u1C7D\u1C80-\u1C8A\u1C90-\u1CBA\u1CBD-\u1CBF\u1CE9-\u1CEC\u1CEE-\u1CF3\u1CF5\u1CF6\u1CFA\u1D00-\u1DBF\u1E00-\u1F15\u1F18-\u1F1D\u1F20-\u1F45\u1F48-\u1F4D\u1F50-\u1F57\u1F59\u1F5B\u1F5D\u1F5F-\u1F7D\u1F80-\u1FB4\u1FB6-\u1FBC\u1FBE\u1FC2-\u1FC4\u1FC6-\u1FCC\u1FD0-\u1FD3\u1FD6-\u1FDB\u1FE0-\u1FEC\u1FF2-\u1FF4\u1FF6-\u1FFC\u2019\u2071\u207F\u2090-\u209C\u2102\u2107\u210A-\u2113\u2115\u2119-\u211D\u2124\u2126\u2128\u212A-\u212D\u212F-\u2139\u213C-\u213F\u2145-\u2149\u214E\u2183\u2184\u2C00-\u2CE4\u2CEB-\u2CEE\u2CF2\u2CF3\u2D00-\u2D25\u2D27\u2D2D\u2D30-\u2D67\u2D6F\u2D80-\u2D96\u2DA0-\u2DA6\u2DA8-\u2DAE\u2DB0-\u2DB6\u2DB8-\u2DBE\u2DC0-\u2DC6\u2DC8-\u2DCE\u2DD0-\u2DD6\u2DD8-\u2DDE\u2E2F\u3005\u3006\u3031-\u3035\u303B\u303C\u3041-\u3096\u309D-\u309F\u30A1-\u30FA\u30FC-\u30FF\u3105-\u312F\u3131-\u318E\u31A0-\u31BF\u31F0-\u31FF\u3400-\u4DBF\u4E00-\uA48C\uA4D0-\uA4FD\uA500-\uA60C\uA610-\uA61F\uA62A\uA62B\uA640-\uA66E\uA67F-\uA69D\uA6A0-\uA6E5\uA717-\uA71F\uA722-\uA788\uA78B-\uA7DC\uA7F1-\uA801\uA803-\uA805\uA807-\uA80A\uA80C-\uA822\uA840-\uA873\uA882-\uA8B3\uA8F2-\uA8F7\uA8FB\uA8FD\uA8FE\uA90A-\uA925\uA930-\uA946\uA960-\uA97C\uA984-\uA9B2\uA9CF\uA9E0-\uA9E4\uA9E6-\uA9EF\uA9FA-\uA9FE\uAA00-\uAA28\uAA40-\uAA42\uAA44-\uAA4B\uAA60-\uAA76\uAA7A\uAA7E-\uAAAF\uAAB1\uAAB5\uAAB6\uAAB9-\uAABD\uAAC0\uAAC2\uAADB-\uAADD\uAAE0-\uAAEA\uAAF2-\uAAF4\uAB01-\uAB06\uAB09-\uAB0E\uAB11-\uAB16\uAB20-\uAB26\uAB28-\uAB2E\uAB30-\uAB5A\uAB5C-\uAB69\uAB70-\uABE2\uAC00-\uD7A3\uD7B0-\uD7C6\uD7CB-\uD7FB\uF900-\uFA6D\uFA70-\uFAD9\uFB00-\uFB06\uFB13-\uFB17\uFB1D\uFB1F-\uFB28\uFB2A-\uFB36\uFB38-\uFB3C\uFB3E\uFB40\uFB41\uFB43\uFB44\uFB46-\uFBB1\uFBD3-\uFD3D\uFD50-\uFD8F\uFD92-\uFDC7\uFDF0-\uFDFB\uFE70-\uFE74\uFE76-\uFEFC\uFF21-\uFF3A\uFF41-\uFF5A\uFF66-\uFFBE\uFFC2-\uFFC7\uFFCA-\uFFCF\uFFD2-\uFFD7\uFFDA-\uFFDC]|\uD800[\uDC00-\uDC0B\uDC0D-\uDC26\uDC28-\uDC3A\uDC3C\uDC3D\uDC3F-\uDC4D\uDC50-\uDC5D\uDC80-\uDCFA\uDE80-\uDE9C\uDEA0-\uDED0\uDF00-\uDF1F\uDF2D-\uDF40\uDF42-\uDF49\uDF50-\uDF75\uDF80-\uDF9D\uDFA0-\uDFC3\uDFC8-\uDFCF]|\uD801[\uDC00-\uDC9D\uDCB0-\uDCD3\uDCD8-\uDCFB\uDD00-\uDD27\uDD30-\uDD63\uDD70-\uDD7A\uDD7C-\uDD8A\uDD8C-\uDD92\uDD94\uDD95\uDD97-\uDDA1\uDDA3-\uDDB1\uDDB3-\uDDB9\uDDBB\uDDBC\uDDC0-\uDDF3\uDE00-\uDF36\uDF40-\uDF55\uDF60-\uDF67\uDF80-\uDF85\uDF87-\uDFB0\uDFB2-\uDFBA]|\uD802[\uDC00-\uDC05\uDC08\uDC0A-\uDC35\uDC37\uDC38\uDC3C\uDC3F-\uDC55\uDC60-\uDC76\uDC80-\uDC9E\uDCE0-\uDCF2\uDCF4\uDCF5\uDD00-\uDD15\uDD20-\uDD39\uDD40-\uDD59\uDD80-\uDDB7\uDDBE\uDDBF\uDE00\uDE10-\uDE13\uDE15-\uDE17\uDE19-\uDE35\uDE60-\uDE7C\uDE80-\uDE9C\uDEC0-\uDEC7\uDEC9-\uDEE4\uDF00-\uDF35\uDF40-\uDF55\uDF60-\uDF72\uDF80-\uDF91]|\uD803[\uDC00-\uDC48\uDC80-\uDCB2\uDCC0-\uDCF2\uDD00-\uDD23\uDD4A-\uDD65\uDD6F-\uDD85\uDE80-\uDEA9\uDEB0\uDEB1\uDEC2-\uDEC7\uDF00-\uDF1C\uDF27\uDF30-\uDF45\uDF70-\uDF81\uDFB0-\uDFC4\uDFE0-\uDFF6]|\uD804[\uDC03-\uDC37\uDC71\uDC72\uDC75\uDC83-\uDCAF\uDCD0-\uDCE8\uDD03-\uDD26\uDD44\uDD47\uDD50-\uDD72\uDD76\uDD83-\uDDB2\uDDC1-\uDDC4\uDDDA\uDDDC\uDE00-\uDE11\uDE13-\uDE2B\uDE3F\uDE40\uDE80-\uDE86\uDE88\uDE8A-\uDE8D\uDE8F-\uDE9D\uDE9F-\uDEA8\uDEB0-\uDEDE\uDF05-\uDF0C\uDF0F\uDF10\uDF13-\uDF28\uDF2A-\uDF30\uDF32\uDF33\uDF35-\uDF39\uDF3D\uDF50\uDF5D-\uDF61\uDF80-\uDF89\uDF8B\uDF8E\uDF90-\uDFB5\uDFB7\uDFD1\uDFD3]|\uD805[\uDC00-\uDC34\uDC47-\uDC4A\uDC5F-\uDC61\uDC80-\uDCAF\uDCC4\uDCC5\uDCC7\uDD80-\uDDAE\uDDD8-\uDDDB\uDE00-\uDE2F\uDE44\uDE80-\uDEAA\uDEB8\uDF00-\uDF1A\uDF40-\uDF46]|\uD806[\uDC00-\uDC2B\uDCA0-\uDCDF\uDCFF-\uDD06\uDD09\uDD0C-\uDD13\uDD15\uDD16\uDD18-\uDD2F\uDD3F\uDD41\uDDA0-\uDDA7\uDDAA-\uDDD0\uDDE1\uDDE3\uDE00\uDE0B-\uDE32\uDE3A\uDE50\uDE5C-\uDE89\uDE9D\uDEB0-\uDEF8\uDFC0-\uDFE0]|\uD807[\uDC00-\uDC08\uDC0A-\uDC2E\uDC40\uDC72-\uDC8F\uDD00-\uDD06\uDD08\uDD09\uDD0B-\uDD30\uDD46\uDD60-\uDD65\uDD67\uDD68\uDD6A-\uDD89\uDD98\uDDB0-\uDDDB\uDEE0-\uDEF2\uDF02\uDF04-\uDF10\uDF12-\uDF33\uDFB0]|\uD808[\uDC00-\uDF99]|\uD809[\uDC80-\uDD43]|\uD80B[\uDF90-\uDFF0]|[\uD80C\uD80E\uD80F\uD81C-\uD822\uD840-\uD868\uD86A-\uD86D\uD86F-\uD872\uD874-\uD879\uD880-\uD883\uD885-\uD88C][\uDC00-\uDFFF]|\uD80D[\uDC00-\uDC2F\uDC41-\uDC46\uDC60-\uDFFF]|\uD810[\uDC00-\uDFFA]|\uD811[\uDC00-\uDE46]|\uD818[\uDD00-\uDD1D]|\uD81A[\uDC00-\uDE38\uDE40-\uDE5E\uDE70-\uDEBE\uDED0-\uDEED\uDF00-\uDF2F\uDF40-\uDF43\uDF63-\uDF77\uDF7D-\uDF8F]|\uD81B[\uDD40-\uDD6C\uDE40-\uDE7F\uDEA0-\uDEB8\uDEBB-\uDED3\uDF00-\uDF4A\uDF50\uDF93-\uDF9F\uDFE0\uDFE1\uDFE3\uDFF2\uDFF3]|\uD823[\uDC00-\uDCD5\uDCFF-\uDD1E\uDD80-\uDDF2]|\uD82B[\uDFF0-\uDFF3\uDFF5-\uDFFB\uDFFD\uDFFE]|\uD82C[\uDC00-\uDD22\uDD32\uDD50-\uDD52\uDD55\uDD64-\uDD67\uDD70-\uDEFB]|\uD82F[\uDC00-\uDC6A\uDC70-\uDC7C\uDC80-\uDC88\uDC90-\uDC99]|\uD835[\uDC00-\uDC54\uDC56-\uDC9C\uDC9E\uDC9F\uDCA2\uDCA5\uDCA6\uDCA9-\uDCAC\uDCAE-\uDCB9\uDCBB\uDCBD-\uDCC3\uDCC5-\uDD05\uDD07-\uDD0A\uDD0D-\uDD14\uDD16-\uDD1C\uDD1E-\uDD39\uDD3B-\uDD3E\uDD40-\uDD44\uDD46\uDD4A-\uDD50\uDD52-\uDEA5\uDEA8-\uDEC0\uDEC2-\uDEDA\uDEDC-\uDEFA\uDEFC-\uDF14\uDF16-\uDF34\uDF36-\uDF4E\uDF50-\uDF6E\uDF70-\uDF88\uDF8A-\uDFA8\uDFAA-\uDFC2\uDFC4-\uDFCB]|\uD837[\uDF00-\uDF1E\uDF25-\uDF2A]|\uD838[\uDC30-\uDC6D\uDD00-\uDD2C\uDD37-\uDD3D\uDD4E\uDE90-\uDEAD\uDEC0-\uDEEB]|\uD839[\uDCD0-\uDCEB\uDDD0-\uDDED\uDDF0\uDEC0-\uDEDE\uDEE0-\uDEE2\uDEE4\uDEE5\uDEE7-\uDEED\uDEF0-\uDEF4\uDEFE\uDEFF\uDFE0-\uDFE6\uDFE8-\uDFEB\uDFED\uDFEE\uDFF0-\uDFFE]|\uD83A[\uDC00-\uDCC4\uDD00-\uDD43\uDD4B]|\uD83B[\uDE00-\uDE03\uDE05-\uDE1F\uDE21\uDE22\uDE24\uDE27\uDE29-\uDE32\uDE34-\uDE37\uDE39\uDE3B\uDE42\uDE47\uDE49\uDE4B\uDE4D-\uDE4F\uDE51\uDE52\uDE54\uDE57\uDE59\uDE5B\uDE5D\uDE5F\uDE61\uDE62\uDE64\uDE67-\uDE6A\uDE6C-\uDE72\uDE74-\uDE77\uDE79-\uDE7C\uDE7E\uDE80-\uDE89\uDE8B-\uDE9B\uDEA1-\uDEA3\uDEA5-\uDEA9\uDEAB-\uDEBB]|\uD869[\uDC00-\uDEDF\uDF00-\uDFFF]|\uD86E[\uDC00-\uDC1D\uDC20-\uDFFF]|\uD873[\uDC00-\uDEAD\uDEB0-\uDFFF]|\uD87A[\uDC00-\uDFE0\uDFF0-\uDFFF]|\uD87B[\uDC00-\uDE5D]|\uD87E[\uDC00-\uDE1D]|\uD884[\uDC00-\uDF4A\uDF50-\uDFFF]|\uD88D[\uDC00-\uDC79])+/i, /\b(exclu|retrait|retirer|enlever|enlevez)\b/i, /^[\t-\r \xA0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]*[s\u017F]an[s\u017F][\t-\r \xA0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]*[,:]/i, /\bhold\b/i, /\bno[\t-\r \xA0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+(?:[A-Za-z\xAA\xB5\xBA\xC0-\xD6\xD8-\xF6\xF8-\u02C1\u02C6-\u02D1\u02E0-\u02E4\u02EC\u02EE\u0345\u0370-\u0374\u0376\u0377\u037A-\u037D\u037F\u0386\u0388-\u038A\u038C\u038E-\u03A1\u03A3-\u03F5\u03F7-\u0481\u048A-\u052F\u0531-\u0556\u0559\u0560-\u0588\u05D0-\u05EA\u05EF-\u05F2\u0620-\u064A\u066E\u066F\u0671-\u06D3\u06D5\u06E5\u06E6\u06EE\u06EF\u06FA-\u06FC\u06FF\u0710\u0712-\u072F\u074D-\u07A5\u07B1\u07CA-\u07EA\u07F4\u07F5\u07FA\u0800-\u0815\u081A\u0824\u0828\u0840-\u0858\u0860-\u086A\u0870-\u0887\u0889-\u088F\u08A0-\u08C9\u0904-\u0939\u093D\u0950\u0958-\u0961\u0971-\u0980\u0985-\u098C\u098F\u0990\u0993-\u09A8\u09AA-\u09B0\u09B2\u09B6-\u09B9\u09BD\u09CE\u09DC\u09DD\u09DF-\u09E1\u09F0\u09F1\u09FC\u0A05-\u0A0A\u0A0F\u0A10\u0A13-\u0A28\u0A2A-\u0A30\u0A32\u0A33\u0A35\u0A36\u0A38\u0A39\u0A59-\u0A5C\u0A5E\u0A72-\u0A74\u0A85-\u0A8D\u0A8F-\u0A91\u0A93-\u0AA8\u0AAA-\u0AB0\u0AB2\u0AB3\u0AB5-\u0AB9\u0ABD\u0AD0\u0AE0\u0AE1\u0AF9\u0B05-\u0B0C\u0B0F\u0B10\u0B13-\u0B28\u0B2A-\u0B30\u0B32\u0B33\u0B35-\u0B39\u0B3D\u0B5C\u0B5D\u0B5F-\u0B61\u0B71\u0B83\u0B85-\u0B8A\u0B8E-\u0B90\u0B92-\u0B95\u0B99\u0B9A\u0B9C\u0B9E\u0B9F\u0BA3\u0BA4\u0BA8-\u0BAA\u0BAE-\u0BB9\u0BD0\u0C05-\u0C0C\u0C0E-\u0C10\u0C12-\u0C28\u0C2A-\u0C39\u0C3D\u0C58-\u0C5A\u0C5C\u0C5D\u0C60\u0C61\u0C80\u0C85-\u0C8C\u0C8E-\u0C90\u0C92-\u0CA8\u0CAA-\u0CB3\u0CB5-\u0CB9\u0CBD\u0CDC-\u0CDE\u0CE0\u0CE1\u0CF1\u0CF2\u0D04-\u0D0C\u0D0E-\u0D10\u0D12-\u0D3A\u0D3D\u0D4E\u0D54-\u0D56\u0D5F-\u0D61\u0D7A-\u0D7F\u0D85-\u0D96\u0D9A-\u0DB1\u0DB3-\u0DBB\u0DBD\u0DC0-\u0DC6\u0E01-\u0E30\u0E32\u0E33\u0E40-\u0E46\u0E81\u0E82\u0E84\u0E86-\u0E8A\u0E8C-\u0EA3\u0EA5\u0EA7-\u0EB0\u0EB2\u0EB3\u0EBD\u0EC0-\u0EC4\u0EC6\u0EDC-\u0EDF\u0F00\u0F40-\u0F47\u0F49-\u0F6C\u0F88-\u0F8C\u1000-\u102A\u103F\u1050-\u1055\u105A-\u105D\u1061\u1065\u1066\u106E-\u1070\u1075-\u1081\u108E\u10A0-\u10C5\u10C7\u10CD\u10D0-\u10FA\u10FC-\u1248\u124A-\u124D\u1250-\u1256\u1258\u125A-\u125D\u1260-\u1288\u128A-\u128D\u1290-\u12B0\u12B2-\u12B5\u12B8-\u12BE\u12C0\u12C2-\u12C5\u12C8-\u12D6\u12D8-\u1310\u1312-\u1315\u1318-\u135A\u1380-\u138F\u13A0-\u13F5\u13F8-\u13FD\u1401-\u166C\u166F-\u167F\u1681-\u169A\u16A0-\u16EA\u16F1-\u16F8\u1700-\u1711\u171F-\u1731\u1740-\u1751\u1760-\u176C\u176E-\u1770\u1780-\u17B3\u17D7\u17DC\u1820-\u1878\u1880-\u1884\u1887-\u18A8\u18AA\u18B0-\u18F5\u1900-\u191E\u1950-\u196D\u1970-\u1974\u1980-\u19AB\u19B0-\u19C9\u1A00-\u1A16\u1A20-\u1A54\u1AA7\u1B05-\u1B33\u1B45-\u1B4C\u1B83-\u1BA0\u1BAE\u1BAF\u1BBA-\u1BE5\u1C00-\u1C23\u1C4D-\u1C4F\u1C5A-\u1C7D\u1C80-\u1C8A\u1C90-\u1CBA\u1CBD-\u1CBF\u1CE9-\u1CEC\u1CEE-\u1CF3\u1CF5\u1CF6\u1CFA\u1D00-\u1DBF\u1E00-\u1F15\u1F18-\u1F1D\u1F20-\u1F45\u1F48-\u1F4D\u1F50-\u1F57\u1F59\u1F5B\u1F5D\u1F5F-\u1F7D\u1F80-\u1FB4\u1FB6-\u1FBC\u1FBE\u1FC2-\u1FC4\u1FC6-\u1FCC\u1FD0-\u1FD3\u1FD6-\u1FDB\u1FE0-\u1FEC\u1FF2-\u1FF4\u1FF6-\u1FFC\u2071\u207F\u2090-\u209C\u2102\u2107\u210A-\u2113\u2115\u2119-\u211D\u2124\u2126\u2128\u212A-\u212D\u212F-\u2139\u213C-\u213F\u2145-\u2149\u214E\u2183\u2184\u2C00-\u2CE4\u2CEB-\u2CEE\u2CF2\u2CF3\u2D00-\u2D25\u2D27\u2D2D\u2D30-\u2D67\u2D6F\u2D80-\u2D96\u2DA0-\u2DA6\u2DA8-\u2DAE\u2DB0-\u2DB6\u2DB8-\u2DBE\u2DC0-\u2DC6\u2DC8-\u2DCE\u2DD0-\u2DD6\u2DD8-\u2DDE\u2E2F\u3005\u3006\u3031-\u3035\u303B\u303C\u3041-\u3096\u309D-\u309F\u30A1-\u30FA\u30FC-\u30FF\u3105-\u312F\u3131-\u318E\u31A0-\u31BF\u31F0-\u31FF\u3400-\u4DBF\u4E00-\uA48C\uA4D0-\uA4FD\uA500-\uA60C\uA610-\uA61F\uA62A\uA62B\uA640-\uA66E\uA67F-\uA69D\uA6A0-\uA6E5\uA717-\uA71F\uA722-\uA788\uA78B-\uA7DC\uA7F1-\uA801\uA803-\uA805\uA807-\uA80A\uA80C-\uA822\uA840-\uA873\uA882-\uA8B3\uA8F2-\uA8F7\uA8FB\uA8FD\uA8FE\uA90A-\uA925\uA930-\uA946\uA960-\uA97C\uA984-\uA9B2\uA9CF\uA9E0-\uA9E4\uA9E6-\uA9EF\uA9FA-\uA9FE\uAA00-\uAA28\uAA40-\uAA42\uAA44-\uAA4B\uAA60-\uAA76\uAA7A\uAA7E-\uAAAF\uAAB1\uAAB5\uAAB6\uAAB9-\uAABD\uAAC0\uAAC2\uAADB-\uAADD\uAAE0-\uAAEA\uAAF2-\uAAF4\uAB01-\uAB06\uAB09-\uAB0E\uAB11-\uAB16\uAB20-\uAB26\uAB28-\uAB2E\uAB30-\uAB5A\uAB5C-\uAB69\uAB70-\uABE2\uAC00-\uD7A3\uD7B0-\uD7C6\uD7CB-\uD7FB\uF900-\uFA6D\uFA70-\uFAD9\uFB00-\uFB06\uFB13-\uFB17\uFB1D\uFB1F-\uFB28\uFB2A-\uFB36\uFB38-\uFB3C\uFB3E\uFB40\uFB41\uFB43\uFB44\uFB46-\uFBB1\uFBD3-\uFD3D\uFD50-\uFD8F\uFD92-\uFDC7\uFDF0-\uFDFB\uFE70-\uFE74\uFE76-\uFEFC\uFF21-\uFF3A\uFF41-\uFF5A\uFF66-\uFFBE\uFFC2-\uFFC7\uFFCA-\uFFCF\uFFD2-\uFFD7\uFFDA-\uFFDC]|\uD800[\uDC00-\uDC0B\uDC0D-\uDC26\uDC28-\uDC3A\uDC3C\uDC3D\uDC3F-\uDC4D\uDC50-\uDC5D\uDC80-\uDCFA\uDE80-\uDE9C\uDEA0-\uDED0\uDF00-\uDF1F\uDF2D-\uDF40\uDF42-\uDF49\uDF50-\uDF75\uDF80-\uDF9D\uDFA0-\uDFC3\uDFC8-\uDFCF]|\uD801[\uDC00-\uDC9D\uDCB0-\uDCD3\uDCD8-\uDCFB\uDD00-\uDD27\uDD30-\uDD63\uDD70-\uDD7A\uDD7C-\uDD8A\uDD8C-\uDD92\uDD94\uDD95\uDD97-\uDDA1\uDDA3-\uDDB1\uDDB3-\uDDB9\uDDBB\uDDBC\uDDC0-\uDDF3\uDE00-\uDF36\uDF40-\uDF55\uDF60-\uDF67\uDF80-\uDF85\uDF87-\uDFB0\uDFB2-\uDFBA]|\uD802[\uDC00-\uDC05\uDC08\uDC0A-\uDC35\uDC37\uDC38\uDC3C\uDC3F-\uDC55\uDC60-\uDC76\uDC80-\uDC9E\uDCE0-\uDCF2\uDCF4\uDCF5\uDD00-\uDD15\uDD20-\uDD39\uDD40-\uDD59\uDD80-\uDDB7\uDDBE\uDDBF\uDE00\uDE10-\uDE13\uDE15-\uDE17\uDE19-\uDE35\uDE60-\uDE7C\uDE80-\uDE9C\uDEC0-\uDEC7\uDEC9-\uDEE4\uDF00-\uDF35\uDF40-\uDF55\uDF60-\uDF72\uDF80-\uDF91]|\uD803[\uDC00-\uDC48\uDC80-\uDCB2\uDCC0-\uDCF2\uDD00-\uDD23\uDD4A-\uDD65\uDD6F-\uDD85\uDE80-\uDEA9\uDEB0\uDEB1\uDEC2-\uDEC7\uDF00-\uDF1C\uDF27\uDF30-\uDF45\uDF70-\uDF81\uDFB0-\uDFC4\uDFE0-\uDFF6]|\uD804[\uDC03-\uDC37\uDC71\uDC72\uDC75\uDC83-\uDCAF\uDCD0-\uDCE8\uDD03-\uDD26\uDD44\uDD47\uDD50-\uDD72\uDD76\uDD83-\uDDB2\uDDC1-\uDDC4\uDDDA\uDDDC\uDE00-\uDE11\uDE13-\uDE2B\uDE3F\uDE40\uDE80-\uDE86\uDE88\uDE8A-\uDE8D\uDE8F-\uDE9D\uDE9F-\uDEA8\uDEB0-\uDEDE\uDF05-\uDF0C\uDF0F\uDF10\uDF13-\uDF28\uDF2A-\uDF30\uDF32\uDF33\uDF35-\uDF39\uDF3D\uDF50\uDF5D-\uDF61\uDF80-\uDF89\uDF8B\uDF8E\uDF90-\uDFB5\uDFB7\uDFD1\uDFD3]|\uD805[\uDC00-\uDC34\uDC47-\uDC4A\uDC5F-\uDC61\uDC80-\uDCAF\uDCC4\uDCC5\uDCC7\uDD80-\uDDAE\uDDD8-\uDDDB\uDE00-\uDE2F\uDE44\uDE80-\uDEAA\uDEB8\uDF00-\uDF1A\uDF40-\uDF46]|\uD806[\uDC00-\uDC2B\uDCA0-\uDCDF\uDCFF-\uDD06\uDD09\uDD0C-\uDD13\uDD15\uDD16\uDD18-\uDD2F\uDD3F\uDD41\uDDA0-\uDDA7\uDDAA-\uDDD0\uDDE1\uDDE3\uDE00\uDE0B-\uDE32\uDE3A\uDE50\uDE5C-\uDE89\uDE9D\uDEB0-\uDEF8\uDFC0-\uDFE0]|\uD807[\uDC00-\uDC08\uDC0A-\uDC2E\uDC40\uDC72-\uDC8F\uDD00-\uDD06\uDD08\uDD09\uDD0B-\uDD30\uDD46\uDD60-\uDD65\uDD67\uDD68\uDD6A-\uDD89\uDD98\uDDB0-\uDDDB\uDEE0-\uDEF2\uDF02\uDF04-\uDF10\uDF12-\uDF33\uDFB0]|\uD808[\uDC00-\uDF99]|\uD809[\uDC80-\uDD43]|\uD80B[\uDF90-\uDFF0]|[\uD80C\uD80E\uD80F\uD81C-\uD822\uD840-\uD868\uD86A-\uD86D\uD86F-\uD872\uD874-\uD879\uD880-\uD883\uD885-\uD88C][\uDC00-\uDFFF]|\uD80D[\uDC00-\uDC2F\uDC41-\uDC46\uDC60-\uDFFF]|\uD810[\uDC00-\uDFFA]|\uD811[\uDC00-\uDE46]|\uD818[\uDD00-\uDD1D]|\uD81A[\uDC00-\uDE38\uDE40-\uDE5E\uDE70-\uDEBE\uDED0-\uDEED\uDF00-\uDF2F\uDF40-\uDF43\uDF63-\uDF77\uDF7D-\uDF8F]|\uD81B[\uDD40-\uDD6C\uDE40-\uDE7F\uDEA0-\uDEB8\uDEBB-\uDED3\uDF00-\uDF4A\uDF50\uDF93-\uDF9F\uDFE0\uDFE1\uDFE3\uDFF2\uDFF3]|\uD823[\uDC00-\uDCD5\uDCFF-\uDD1E\uDD80-\uDDF2]|\uD82B[\uDFF0-\uDFF3\uDFF5-\uDFFB\uDFFD\uDFFE]|\uD82C[\uDC00-\uDD22\uDD32\uDD50-\uDD52\uDD55\uDD64-\uDD67\uDD70-\uDEFB]|\uD82F[\uDC00-\uDC6A\uDC70-\uDC7C\uDC80-\uDC88\uDC90-\uDC99]|\uD835[\uDC00-\uDC54\uDC56-\uDC9C\uDC9E\uDC9F\uDCA2\uDCA5\uDCA6\uDCA9-\uDCAC\uDCAE-\uDCB9\uDCBB\uDCBD-\uDCC3\uDCC5-\uDD05\uDD07-\uDD0A\uDD0D-\uDD14\uDD16-\uDD1C\uDD1E-\uDD39\uDD3B-\uDD3E\uDD40-\uDD44\uDD46\uDD4A-\uDD50\uDD52-\uDEA5\uDEA8-\uDEC0\uDEC2-\uDEDA\uDEDC-\uDEFA\uDEFC-\uDF14\uDF16-\uDF34\uDF36-\uDF4E\uDF50-\uDF6E\uDF70-\uDF88\uDF8A-\uDFA8\uDFAA-\uDFC2\uDFC4-\uDFCB]|\uD837[\uDF00-\uDF1E\uDF25-\uDF2A]|\uD838[\uDC30-\uDC6D\uDD00-\uDD2C\uDD37-\uDD3D\uDD4E\uDE90-\uDEAD\uDEC0-\uDEEB]|\uD839[\uDCD0-\uDCEB\uDDD0-\uDDED\uDDF0\uDEC0-\uDEDE\uDEE0-\uDEE2\uDEE4\uDEE5\uDEE7-\uDEED\uDEF0-\uDEF4\uDEFE\uDEFF\uDFE0-\uDFE6\uDFE8-\uDFEB\uDFED\uDFEE\uDFF0-\uDFFE]|\uD83A[\uDC00-\uDCC4\uDD00-\uDD43\uDD4B]|\uD83B[\uDE00-\uDE03\uDE05-\uDE1F\uDE21\uDE22\uDE24\uDE27\uDE29-\uDE32\uDE34-\uDE37\uDE39\uDE3B\uDE42\uDE47\uDE49\uDE4B\uDE4D-\uDE4F\uDE51\uDE52\uDE54\uDE57\uDE59\uDE5B\uDE5D\uDE5F\uDE61\uDE62\uDE64\uDE67-\uDE6A\uDE6C-\uDE72\uDE74-\uDE77\uDE79-\uDE7C\uDE7E\uDE80-\uDE89\uDE8B-\uDE9B\uDEA1-\uDEA3\uDEA5-\uDEA9\uDEAB-\uDEBB]|\uD869[\uDC00-\uDEDF\uDF00-\uDFFF]|\uD86E[\uDC00-\uDC1D\uDC20-\uDFFF]|\uD873[\uDC00-\uDEAD\uDEB0-\uDFFF]|\uD87A[\uDC00-\uDFE0\uDFF0-\uDFFF]|\uD87B[\uDC00-\uDE5D]|\uD87E[\uDC00-\uDE1D]|\uD884[\uDC00-\uDF4A\uDF50-\uDFFF]|\uD88D[\uDC00-\uDC79])+/i,
// "no onion" in EN menus
/\b(without|w\/o|w\/)[\t-\r \xA0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+(?:[A-Za-z\xAA\xB5\xBA\xC0-\xD6\xD8-\xF6\xF8-\u02C1\u02C6-\u02D1\u02E0-\u02E4\u02EC\u02EE\u0345\u0370-\u0374\u0376\u0377\u037A-\u037D\u037F\u0386\u0388-\u038A\u038C\u038E-\u03A1\u03A3-\u03F5\u03F7-\u0481\u048A-\u052F\u0531-\u0556\u0559\u0560-\u0588\u05D0-\u05EA\u05EF-\u05F2\u0620-\u064A\u066E\u066F\u0671-\u06D3\u06D5\u06E5\u06E6\u06EE\u06EF\u06FA-\u06FC\u06FF\u0710\u0712-\u072F\u074D-\u07A5\u07B1\u07CA-\u07EA\u07F4\u07F5\u07FA\u0800-\u0815\u081A\u0824\u0828\u0840-\u0858\u0860-\u086A\u0870-\u0887\u0889-\u088F\u08A0-\u08C9\u0904-\u0939\u093D\u0950\u0958-\u0961\u0971-\u0980\u0985-\u098C\u098F\u0990\u0993-\u09A8\u09AA-\u09B0\u09B2\u09B6-\u09B9\u09BD\u09CE\u09DC\u09DD\u09DF-\u09E1\u09F0\u09F1\u09FC\u0A05-\u0A0A\u0A0F\u0A10\u0A13-\u0A28\u0A2A-\u0A30\u0A32\u0A33\u0A35\u0A36\u0A38\u0A39\u0A59-\u0A5C\u0A5E\u0A72-\u0A74\u0A85-\u0A8D\u0A8F-\u0A91\u0A93-\u0AA8\u0AAA-\u0AB0\u0AB2\u0AB3\u0AB5-\u0AB9\u0ABD\u0AD0\u0AE0\u0AE1\u0AF9\u0B05-\u0B0C\u0B0F\u0B10\u0B13-\u0B28\u0B2A-\u0B30\u0B32\u0B33\u0B35-\u0B39\u0B3D\u0B5C\u0B5D\u0B5F-\u0B61\u0B71\u0B83\u0B85-\u0B8A\u0B8E-\u0B90\u0B92-\u0B95\u0B99\u0B9A\u0B9C\u0B9E\u0B9F\u0BA3\u0BA4\u0BA8-\u0BAA\u0BAE-\u0BB9\u0BD0\u0C05-\u0C0C\u0C0E-\u0C10\u0C12-\u0C28\u0C2A-\u0C39\u0C3D\u0C58-\u0C5A\u0C5C\u0C5D\u0C60\u0C61\u0C80\u0C85-\u0C8C\u0C8E-\u0C90\u0C92-\u0CA8\u0CAA-\u0CB3\u0CB5-\u0CB9\u0CBD\u0CDC-\u0CDE\u0CE0\u0CE1\u0CF1\u0CF2\u0D04-\u0D0C\u0D0E-\u0D10\u0D12-\u0D3A\u0D3D\u0D4E\u0D54-\u0D56\u0D5F-\u0D61\u0D7A-\u0D7F\u0D85-\u0D96\u0D9A-\u0DB1\u0DB3-\u0DBB\u0DBD\u0DC0-\u0DC6\u0E01-\u0E30\u0E32\u0E33\u0E40-\u0E46\u0E81\u0E82\u0E84\u0E86-\u0E8A\u0E8C-\u0EA3\u0EA5\u0EA7-\u0EB0\u0EB2\u0EB3\u0EBD\u0EC0-\u0EC4\u0EC6\u0EDC-\u0EDF\u0F00\u0F40-\u0F47\u0F49-\u0F6C\u0F88-\u0F8C\u1000-\u102A\u103F\u1050-\u1055\u105A-\u105D\u1061\u1065\u1066\u106E-\u1070\u1075-\u1081\u108E\u10A0-\u10C5\u10C7\u10CD\u10D0-\u10FA\u10FC-\u1248\u124A-\u124D\u1250-\u1256\u1258\u125A-\u125D\u1260-\u1288\u128A-\u128D\u1290-\u12B0\u12B2-\u12B5\u12B8-\u12BE\u12C0\u12C2-\u12C5\u12C8-\u12D6\u12D8-\u1310\u1312-\u1315\u1318-\u135A\u1380-\u138F\u13A0-\u13F5\u13F8-\u13FD\u1401-\u166C\u166F-\u167F\u1681-\u169A\u16A0-\u16EA\u16F1-\u16F8\u1700-\u1711\u171F-\u1731\u1740-\u1751\u1760-\u176C\u176E-\u1770\u1780-\u17B3\u17D7\u17DC\u1820-\u1878\u1880-\u1884\u1887-\u18A8\u18AA\u18B0-\u18F5\u1900-\u191E\u1950-\u196D\u1970-\u1974\u1980-\u19AB\u19B0-\u19C9\u1A00-\u1A16\u1A20-\u1A54\u1AA7\u1B05-\u1B33\u1B45-\u1B4C\u1B83-\u1BA0\u1BAE\u1BAF\u1BBA-\u1BE5\u1C00-\u1C23\u1C4D-\u1C4F\u1C5A-\u1C7D\u1C80-\u1C8A\u1C90-\u1CBA\u1CBD-\u1CBF\u1CE9-\u1CEC\u1CEE-\u1CF3\u1CF5\u1CF6\u1CFA\u1D00-\u1DBF\u1E00-\u1F15\u1F18-\u1F1D\u1F20-\u1F45\u1F48-\u1F4D\u1F50-\u1F57\u1F59\u1F5B\u1F5D\u1F5F-\u1F7D\u1F80-\u1FB4\u1FB6-\u1FBC\u1FBE\u1FC2-\u1FC4\u1FC6-\u1FCC\u1FD0-\u1FD3\u1FD6-\u1FDB\u1FE0-\u1FEC\u1FF2-\u1FF4\u1FF6-\u1FFC\u2071\u207F\u2090-\u209C\u2102\u2107\u210A-\u2113\u2115\u2119-\u211D\u2124\u2126\u2128\u212A-\u212D\u212F-\u2139\u213C-\u213F\u2145-\u2149\u214E\u2183\u2184\u2C00-\u2CE4\u2CEB-\u2CEE\u2CF2\u2CF3\u2D00-\u2D25\u2D27\u2D2D\u2D30-\u2D67\u2D6F\u2D80-\u2D96\u2DA0-\u2DA6\u2DA8-\u2DAE\u2DB0-\u2DB6\u2DB8-\u2DBE\u2DC0-\u2DC6\u2DC8-\u2DCE\u2DD0-\u2DD6\u2DD8-\u2DDE\u2E2F\u3005\u3006\u3031-\u3035\u303B\u303C\u3041-\u3096\u309D-\u309F\u30A1-\u30FA\u30FC-\u30FF\u3105-\u312F\u3131-\u318E\u31A0-\u31BF\u31F0-\u31FF\u3400-\u4DBF\u4E00-\uA48C\uA4D0-\uA4FD\uA500-\uA60C\uA610-\uA61F\uA62A\uA62B\uA640-\uA66E\uA67F-\uA69D\uA6A0-\uA6E5\uA717-\uA71F\uA722-\uA788\uA78B-\uA7DC\uA7F1-\uA801\uA803-\uA805\uA807-\uA80A\uA80C-\uA822\uA840-\uA873\uA882-\uA8B3\uA8F2-\uA8F7\uA8FB\uA8FD\uA8FE\uA90A-\uA925\uA930-\uA946\uA960-\uA97C\uA984-\uA9B2\uA9CF\uA9E0-\uA9E4\uA9E6-\uA9EF\uA9FA-\uA9FE\uAA00-\uAA28\uAA40-\uAA42\uAA44-\uAA4B\uAA60-\uAA76\uAA7A\uAA7E-\uAAAF\uAAB1\uAAB5\uAAB6\uAAB9-\uAABD\uAAC0\uAAC2\uAADB-\uAADD\uAAE0-\uAAEA\uAAF2-\uAAF4\uAB01-\uAB06\uAB09-\uAB0E\uAB11-\uAB16\uAB20-\uAB26\uAB28-\uAB2E\uAB30-\uAB5A\uAB5C-\uAB69\uAB70-\uABE2\uAC00-\uD7A3\uD7B0-\uD7C6\uD7CB-\uD7FB\uF900-\uFA6D\uFA70-\uFAD9\uFB00-\uFB06\uFB13-\uFB17\uFB1D\uFB1F-\uFB28\uFB2A-\uFB36\uFB38-\uFB3C\uFB3E\uFB40\uFB41\uFB43\uFB44\uFB46-\uFBB1\uFBD3-\uFD3D\uFD50-\uFD8F\uFD92-\uFDC7\uFDF0-\uFDFB\uFE70-\uFE74\uFE76-\uFEFC\uFF21-\uFF3A\uFF41-\uFF5A\uFF66-\uFFBE\uFFC2-\uFFC7\uFFCA-\uFFCF\uFFD2-\uFFD7\uFFDA-\uFFDC]|\uD800[\uDC00-\uDC0B\uDC0D-\uDC26\uDC28-\uDC3A\uDC3C\uDC3D\uDC3F-\uDC4D\uDC50-\uDC5D\uDC80-\uDCFA\uDE80-\uDE9C\uDEA0-\uDED0\uDF00-\uDF1F\uDF2D-\uDF40\uDF42-\uDF49\uDF50-\uDF75\uDF80-\uDF9D\uDFA0-\uDFC3\uDFC8-\uDFCF]|\uD801[\uDC00-\uDC9D\uDCB0-\uDCD3\uDCD8-\uDCFB\uDD00-\uDD27\uDD30-\uDD63\uDD70-\uDD7A\uDD7C-\uDD8A\uDD8C-\uDD92\uDD94\uDD95\uDD97-\uDDA1\uDDA3-\uDDB1\uDDB3-\uDDB9\uDDBB\uDDBC\uDDC0-\uDDF3\uDE00-\uDF36\uDF40-\uDF55\uDF60-\uDF67\uDF80-\uDF85\uDF87-\uDFB0\uDFB2-\uDFBA]|\uD802[\uDC00-\uDC05\uDC08\uDC0A-\uDC35\uDC37\uDC38\uDC3C\uDC3F-\uDC55\uDC60-\uDC76\uDC80-\uDC9E\uDCE0-\uDCF2\uDCF4\uDCF5\uDD00-\uDD15\uDD20-\uDD39\uDD40-\uDD59\uDD80-\uDDB7\uDDBE\uDDBF\uDE00\uDE10-\uDE13\uDE15-\uDE17\uDE19-\uDE35\uDE60-\uDE7C\uDE80-\uDE9C\uDEC0-\uDEC7\uDEC9-\uDEE4\uDF00-\uDF35\uDF40-\uDF55\uDF60-\uDF72\uDF80-\uDF91]|\uD803[\uDC00-\uDC48\uDC80-\uDCB2\uDCC0-\uDCF2\uDD00-\uDD23\uDD4A-\uDD65\uDD6F-\uDD85\uDE80-\uDEA9\uDEB0\uDEB1\uDEC2-\uDEC7\uDF00-\uDF1C\uDF27\uDF30-\uDF45\uDF70-\uDF81\uDFB0-\uDFC4\uDFE0-\uDFF6]|\uD804[\uDC03-\uDC37\uDC71\uDC72\uDC75\uDC83-\uDCAF\uDCD0-\uDCE8\uDD03-\uDD26\uDD44\uDD47\uDD50-\uDD72\uDD76\uDD83-\uDDB2\uDDC1-\uDDC4\uDDDA\uDDDC\uDE00-\uDE11\uDE13-\uDE2B\uDE3F\uDE40\uDE80-\uDE86\uDE88\uDE8A-\uDE8D\uDE8F-\uDE9D\uDE9F-\uDEA8\uDEB0-\uDEDE\uDF05-\uDF0C\uDF0F\uDF10\uDF13-\uDF28\uDF2A-\uDF30\uDF32\uDF33\uDF35-\uDF39\uDF3D\uDF50\uDF5D-\uDF61\uDF80-\uDF89\uDF8B\uDF8E\uDF90-\uDFB5\uDFB7\uDFD1\uDFD3]|\uD805[\uDC00-\uDC34\uDC47-\uDC4A\uDC5F-\uDC61\uDC80-\uDCAF\uDCC4\uDCC5\uDCC7\uDD80-\uDDAE\uDDD8-\uDDDB\uDE00-\uDE2F\uDE44\uDE80-\uDEAA\uDEB8\uDF00-\uDF1A\uDF40-\uDF46]|\uD806[\uDC00-\uDC2B\uDCA0-\uDCDF\uDCFF-\uDD06\uDD09\uDD0C-\uDD13\uDD15\uDD16\uDD18-\uDD2F\uDD3F\uDD41\uDDA0-\uDDA7\uDDAA-\uDDD0\uDDE1\uDDE3\uDE00\uDE0B-\uDE32\uDE3A\uDE50\uDE5C-\uDE89\uDE9D\uDEB0-\uDEF8\uDFC0-\uDFE0]|\uD807[\uDC00-\uDC08\uDC0A-\uDC2E\uDC40\uDC72-\uDC8F\uDD00-\uDD06\uDD08\uDD09\uDD0B-\uDD30\uDD46\uDD60-\uDD65\uDD67\uDD68\uDD6A-\uDD89\uDD98\uDDB0-\uDDDB\uDEE0-\uDEF2\uDF02\uDF04-\uDF10\uDF12-\uDF33\uDFB0]|\uD808[\uDC00-\uDF99]|\uD809[\uDC80-\uDD43]|\uD80B[\uDF90-\uDFF0]|[\uD80C\uD80E\uD80F\uD81C-\uD822\uD840-\uD868\uD86A-\uD86D\uD86F-\uD872\uD874-\uD879\uD880-\uD883\uD885-\uD88C][\uDC00-\uDFFF]|\uD80D[\uDC00-\uDC2F\uDC41-\uDC46\uDC60-\uDFFF]|\uD810[\uDC00-\uDFFA]|\uD811[\uDC00-\uDE46]|\uD818[\uDD00-\uDD1D]|\uD81A[\uDC00-\uDE38\uDE40-\uDE5E\uDE70-\uDEBE\uDED0-\uDEED\uDF00-\uDF2F\uDF40-\uDF43\uDF63-\uDF77\uDF7D-\uDF8F]|\uD81B[\uDD40-\uDD6C\uDE40-\uDE7F\uDEA0-\uDEB8\uDEBB-\uDED3\uDF00-\uDF4A\uDF50\uDF93-\uDF9F\uDFE0\uDFE1\uDFE3\uDFF2\uDFF3]|\uD823[\uDC00-\uDCD5\uDCFF-\uDD1E\uDD80-\uDDF2]|\uD82B[\uDFF0-\uDFF3\uDFF5-\uDFFB\uDFFD\uDFFE]|\uD82C[\uDC00-\uDD22\uDD32\uDD50-\uDD52\uDD55\uDD64-\uDD67\uDD70-\uDEFB]|\uD82F[\uDC00-\uDC6A\uDC70-\uDC7C\uDC80-\uDC88\uDC90-\uDC99]|\uD835[\uDC00-\uDC54\uDC56-\uDC9C\uDC9E\uDC9F\uDCA2\uDCA5\uDCA6\uDCA9-\uDCAC\uDCAE-\uDCB9\uDCBB\uDCBD-\uDCC3\uDCC5-\uDD05\uDD07-\uDD0A\uDD0D-\uDD14\uDD16-\uDD1C\uDD1E-\uDD39\uDD3B-\uDD3E\uDD40-\uDD44\uDD46\uDD4A-\uDD50\uDD52-\uDEA5\uDEA8-\uDEC0\uDEC2-\uDEDA\uDEDC-\uDEFA\uDEFC-\uDF14\uDF16-\uDF34\uDF36-\uDF4E\uDF50-\uDF6E\uDF70-\uDF88\uDF8A-\uDFA8\uDFAA-\uDFC2\uDFC4-\uDFCB]|\uD837[\uDF00-\uDF1E\uDF25-\uDF2A]|\uD838[\uDC30-\uDC6D\uDD00-\uDD2C\uDD37-\uDD3D\uDD4E\uDE90-\uDEAD\uDEC0-\uDEEB]|\uD839[\uDCD0-\uDCEB\uDDD0-\uDDED\uDDF0\uDEC0-\uDEDE\uDEE0-\uDEE2\uDEE4\uDEE5\uDEE7-\uDEED\uDEF0-\uDEF4\uDEFE\uDEFF\uDFE0-\uDFE6\uDFE8-\uDFEB\uDFED\uDFEE\uDFF0-\uDFFE]|\uD83A[\uDC00-\uDCC4\uDD00-\uDD43\uDD4B]|\uD83B[\uDE00-\uDE03\uDE05-\uDE1F\uDE21\uDE22\uDE24\uDE27\uDE29-\uDE32\uDE34-\uDE37\uDE39\uDE3B\uDE42\uDE47\uDE49\uDE4B\uDE4D-\uDE4F\uDE51\uDE52\uDE54\uDE57\uDE59\uDE5B\uDE5D\uDE5F\uDE61\uDE62\uDE64\uDE67-\uDE6A\uDE6C-\uDE72\uDE74-\uDE77\uDE79-\uDE7C\uDE7E\uDE80-\uDE89\uDE8B-\uDE9B\uDEA1-\uDEA3\uDEA5-\uDEA9\uDEAB-\uDEBB]|\uD869[\uDC00-\uDEDF\uDF00-\uDFFF]|\uD86E[\uDC00-\uDC1D\uDC20-\uDFFF]|\uD873[\uDC00-\uDEAD\uDEB0-\uDFFF]|\uD87A[\uDC00-\uDFE0\uDFF0-\uDFFF]|\uD87B[\uDC00-\uDE5D]|\uD87E[\uDC00-\uDE1D]|\uD884[\uDC00-\uDF4A\uDF50-\uDFFF]|\uD88D[\uDC00-\uDC79])+/i];

/* Prefix-friendly (e.g. allergique, arachide). Arabic forms added for RTL kitchens. */
var ALLERGEN_RE = [/\ballerg/i, /\bintol/i, /\bgluten/i, /\barach/i, /\bcacahu/i, /\blacto[s\u017F]/i, /\b[s\u017F]oja/i,
// [kds/sprint-2 F-6] Arabic allergen prefixes — حساسية (allergy), غلوتين (gluten), لاكتوز (lactose),
// فول (peanut/bean), مكسرات (nuts), صويا (soy). RTL marker-free for whole-word fallback.
/\u062D\u0633\u0627\u0633\u064A/, /\u063A\u0644\u0648\u062A\u064A\u0646/, /\u0644\u0627\u0643\u062A\u0648\u0632/, /\b\u0641\u0648\u0644\b/, /\u0645\u0643\u0633\u0631\u0627\u062A/, /\u0635\u0648\u064A\u0627/];
function matchesAllergen(text) {
  var _iterator = _createForOfIteratorHelper(ALLERGEN_RE),
    _step;
  try {
    for (_iterator.s(); !(_step = _iterator.n()).done;) {
      var re = _step.value;
      if (re.test(text)) {
        return true;
      }
    }
  } catch (err) {
    _iterator.e(err);
  } finally {
    _iterator.f();
  }
  return false;
}
function matchesExclusion(text) {
  var _iterator2 = _createForOfIteratorHelper(EXCLUSION_RE),
    _step2;
  try {
    for (_iterator2.s(); !(_step2 = _iterator2.n()).done;) {
      var re = _step2.value;
      if (re.test(text)) {
        return true;
      }
    }
  } catch (err) {
    _iterator2.e(err);
  } finally {
    _iterator2.f();
  }
  return false;
}

/**
 * @param {string|null|undefined} text
 * @returns {boolean}
 */
function isLikelyExclusionOrHoldInstruction(text) {
  if (text == null || typeof text !== 'string') {
    return false;
  }
  var t = text.trim();
  if (t.length < 2) {
    return false;
  }
  return matchesAllergen(t) || matchesExclusion(t);
}

/**
 * CSS class (scoped in KDS): allergen (strongest) > exclusion / hold > default note
 * @param {string|null|undefined} text
 * @returns {'kds-instruction--allergen' | 'kds-instruction--exclusion' | 'kds-instruction--note'}
 */
function kdsInstructionVisualClass(text) {
  if (text == null || typeof text !== 'string') {
    return 'kds-instruction--note';
  }
  var t = text.trim();
  if (t.length < 2) {
    return 'kds-instruction--note';
  }
  if (matchesAllergen(t)) {
    return 'kds-instruction--allergen';
  }
  if (matchesExclusion(t)) {
    return 'kds-instruction--exclusion';
  }
  return 'kds-instruction--note';
}

/***/ },

/***/ "./resources/js/helpers/kdsSource.js"
/*!*******************************************!*\
  !*** ./resources/js/helpers/kdsSource.js ***!
  \*******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   KDS_SOURCE: () => (/* binding */ KDS_SOURCE),
/* harmony export */   KDS_SOURCE_I18N_KEYS: () => (/* binding */ KDS_SOURCE_I18N_KEYS),
/* harmony export */   KDS_SOURCE_THEME: () => (/* binding */ KDS_SOURCE_THEME),
/* harmony export */   kdsSourceFromSurface: () => (/* binding */ kdsSourceFromSurface)
/* harmony export */ });
/**
 * [kds/sprint-2 F-2] Single source of truth for source mapping between the
 * `source_surface` string column (server) and the KDS UI chip enum.
 *
 * Adding a new source = one map entry + one i18n key + one theme entry.
 * Vue templates MUST NOT branch on rawSource — they only read `KDS_SOURCE_THEME[source]`
 * and `KDS_SOURCE_I18N_KEYS[source]`.
 */

// Canonical client-side enum.
var KDS_SOURCE = Object.freeze({
  POS: 'POS',
  KIOSK: 'KIOSK',
  DELIVERY: 'DELIVERY',
  ONLINE: 'ONLINE',
  APP: 'APP',
  DINE_IN: 'DINE_IN'
});

// Reverse map: source_surface (DB lowercase) → KDS_SOURCE.
// 'web' fallback in FrontendOrderService maps to ONLINE (web checkout).
// 'admin' (loyalty controller, manual creation) collapses to POS visually —
// the kitchen still treats it like a counter order.
var SURFACE_TO_SOURCE = {
  pos: KDS_SOURCE.POS,
  admin: KDS_SOURCE.POS,
  kiosk: KDS_SOURCE.KIOSK,
  delivery: KDS_SOURCE.DELIVERY,
  web: KDS_SOURCE.ONLINE,
  online: KDS_SOURCE.ONLINE,
  mobile: KDS_SOURCE.APP,
  app: KDS_SOURCE.APP,
  dinein: KDS_SOURCE.DINE_IN,
  dine_in: KDS_SOURCE.DINE_IN
};

/**
 * @param {string|null|undefined} rawSurface  the server column value
 * @returns {'POS'|'KIOSK'|'DELIVERY'|'ONLINE'|'APP'|'DINE_IN'}
 */
function kdsSourceFromSurface(rawSurface) {
  var _SURFACE_TO_SOURCE$ke;
  if (rawSurface == null || rawSurface === '') {
    return KDS_SOURCE.POS;
  }
  var key = String(rawSurface).toLowerCase();
  return (_SURFACE_TO_SOURCE$ke = SURFACE_TO_SOURCE[key]) !== null && _SURFACE_TO_SOURCE$ke !== void 0 ? _SURFACE_TO_SOURCE$ke : KDS_SOURCE.POS;
}

// Design tokens per `plans/DESIGN_SPEC_KDS_V2_2026-05-11.md`. The reserved
// futures (ONLINE/APP/DINE_IN) are wired now so they appear correctly when
// the data starts flowing — no template edit when the flag flips.
var KDS_SOURCE_THEME = Object.freeze({
  POS: {
    bg: '#F3F4F6',
    text: '#374151',
    icon: 'receipt'
  },
  KIOSK: {
    bg: '#EFF6FF',
    text: '#1E40AF',
    icon: 'tablet'
  },
  DELIVERY: {
    bg: '#F0FDFA',
    text: '#0F766E',
    icon: 'truck'
  },
  ONLINE: {
    bg: '#ECFDF5',
    text: '#047857',
    icon: 'globe'
  },
  APP: {
    bg: '#FDF2F8',
    text: '#BE185D',
    icon: 'phone'
  },
  DINE_IN: {
    bg: '#FEF3C7',
    text: '#B45309',
    icon: 'chair'
  }
});
var KDS_SOURCE_I18N_KEYS = Object.freeze({
  POS: 'label.kds_type_pos',
  KIOSK: 'label.kds_type_kiosk',
  DELIVERY: 'label.kds_type_delivery',
  ONLINE: 'label.kds_source_online',
  APP: 'label.kds_source_app',
  DINE_IN: 'label.kds_type_dinein'
});

/***/ },

/***/ "./resources/js/helpers/kdsState.js"
/*!******************************************!*\
  !*** ./resources/js/helpers/kdsState.js ***!
  \******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   KDS_STATE: () => (/* binding */ KDS_STATE),
/* harmony export */   KDS_STATE_I18N_KEYS: () => (/* binding */ KDS_STATE_I18N_KEYS),
/* harmony export */   KDS_STATE_THEME: () => (/* binding */ KDS_STATE_THEME),
/* harmony export */   ORDER_STATUS: () => (/* binding */ ORDER_STATUS),
/* harmony export */   kdsStateFromStatus: () => (/* binding */ kdsStateFromStatus),
/* harmony export */   statusFromKdsState: () => (/* binding */ statusFromKdsState)
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
/**
 * [kds/sprint-2 F-1] Single source of truth for state ↔ status mapping
 * between the numeric OrderStatus enum (server, app/Enums/OrderStatus.php)
 * and the canonical KdsState used by the new card grid.
 *
 * Why client-side: OrderStateMachine.php is frozen; we do not extend the
 * enum or its transitions. The UI just projects each numeric status into a
 * narrower KdsState set ('NEW' | 'PREPARING' | 'READY' | 'DONE' | 'CANCELLED')
 * with the labels the new Vue components render.
 */

// Numeric values mirror app/Enums/OrderStatus.php — DO NOT renumber.
var ORDER_STATUS = Object.freeze({
  PENDING: 1,
  ACCEPT: 4,
  PREPARING: 7,
  PREPARED: 8,
  OUT_FOR_DELIVERY: 10,
  DELIVERED: 13,
  CANCELED: 16,
  REJECTED: 19,
  RETURNED: 22
});

// KdsState — narrower set used by the new card visual layer.
var KDS_STATE = Object.freeze({
  NEW: 'NEW',
  PREPARING: 'PREPARING',
  READY: 'READY',
  DONE: 'DONE',
  CANCELLED: 'CANCELLED'
});

// Map numeric status → KdsState. Anything outside the table → null
// (the card grid filters those out; they belong in history, not on the wall).
var STATUS_TO_KDS = _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty({}, ORDER_STATUS.PENDING, KDS_STATE.NEW), ORDER_STATUS.ACCEPT, KDS_STATE.NEW), ORDER_STATUS.PREPARING, KDS_STATE.PREPARING), ORDER_STATUS.PREPARED, KDS_STATE.READY), ORDER_STATUS.OUT_FOR_DELIVERY, KDS_STATE.DONE), ORDER_STATUS.DELIVERED, KDS_STATE.DONE), ORDER_STATUS.CANCELED, KDS_STATE.CANCELLED), ORDER_STATUS.REJECTED, KDS_STATE.CANCELLED), ORDER_STATUS.RETURNED, KDS_STATE.CANCELLED);

/**
 * @param {number|string} status
 * @returns {'NEW'|'PREPARING'|'READY'|'DONE'|'CANCELLED'|null}
 */
function kdsStateFromStatus(status) {
  var _STATUS_TO_KDS$n;
  var n = parseInt(status, 10);
  if (!Number.isFinite(n)) {
    return null;
  }
  return (_STATUS_TO_KDS$n = STATUS_TO_KDS[n]) !== null && _STATUS_TO_KDS$n !== void 0 ? _STATUS_TO_KDS$n : null;
}

/**
 * Reverse mapping for KDS PATCH actions. The card grid does only two
 * transitions explicitly: NEW→PREPARING (auto-promote) and PREPARING→READY
 * (chef taps Prêt). Any other state move stays the server's responsibility.
 *
 * @param {'NEW'|'PREPARING'|'READY'|'DONE'|'CANCELLED'} kdsState
 * @returns {number|null}
 */
function statusFromKdsState(kdsState) {
  switch (kdsState) {
    case KDS_STATE.NEW:
      return ORDER_STATUS.ACCEPT;
    case KDS_STATE.PREPARING:
      return ORDER_STATUS.PREPARING;
    case KDS_STATE.READY:
      return ORDER_STATUS.PREPARED;
    case KDS_STATE.DONE:
      return ORDER_STATUS.DELIVERED;
    case KDS_STATE.CANCELLED:
      return ORDER_STATUS.CANCELED;
    default:
      return null;
  }
}

// Display-only metadata. Component code reads these to pick the pill / dot
// colors that match the design tokens in plans/DESIGN_SPEC_KDS_V2_2026-05-11.md.
var KDS_STATE_THEME = Object.freeze({
  NEW: {
    bg: '#FFFFFF',
    border: '#D1D5DB',
    dot: '#6B7280',
    text: '#374151'
  },
  PREPARING: {
    bg: '#DBEAFE',
    border: '#BFDBFE',
    dot: '#2563EB',
    text: '#1E3A8A'
  },
  READY: {
    bg: '#DCFCE7',
    border: '#BBF7D0',
    dot: '#16A34A',
    text: '#14532D'
  },
  DONE: {
    bg: '#F3F4F6',
    border: '#E5E7EB',
    dot: '#9CA3AF',
    text: '#374151'
  },
  CANCELLED: {
    bg: '#FEE2E2',
    border: '#FCA5A5',
    dot: '#DC2626',
    text: '#7F1D1D'
  }
});

// i18n key for each KdsState (extends label.kds_aria_live_*). The Vue card
// component does `$t(KDS_STATE_I18N_KEYS[state])`.
var KDS_STATE_I18N_KEYS = Object.freeze({
  NEW: 'label.kds_state_new',
  PREPARING: 'label.kds_state_preparing',
  READY: 'label.kds_state_ready',
  DONE: 'label.kds_state_done',
  CANCELLED: 'label.kds_state_cancelled'
});

/***/ },

/***/ "./resources/js/services/KdsSyncService.js"
/*!*************************************************!*\
  !*** ./resources/js/services/KdsSyncService.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   KDS_SYNC_STATE: () => (/* binding */ KDS_SYNC_STATE),
/* harmony export */   KdsSyncService: () => (/* binding */ KdsSyncService),
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _config_env__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../config/env */ "./resources/js/config/env.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function _classCallCheck(a, n) { if (!(a instanceof n)) throw new TypeError("Cannot call a class as a function"); }
function _defineProperties(e, r) { for (var t = 0; t < r.length; t++) { var o = r[t]; o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, _toPropertyKey(o.key), o); } }
function _createClass(e, r, t) { return r && _defineProperties(e.prototype, r), t && _defineProperties(e, t), Object.defineProperty(e, "prototype", { writable: !1 }), e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md
 */


var KDS_SYNC_STATE = {
  IDLE: 'IDLE',
  ACTIVE: 'ACTIVE',
  BACKOFF: 'BACKOFF',
  STOPPED: 'STOPPED'
};
var WS_CONNECTED = 'CONNECTED';
var WS_RECONNECTING = 'RECONNECTING';
var WS_DEGRADED = 'DEGRADED';
var WS_DISCONNECTED = 'DISCONNECTED';
var WS_SESSION_INVALID = 'SESSION_INVALID';
// [Wave 3 P1 / KDS-RED-09 2026-05-18] Safe cadence floor — 4 req/s max per
// station. Mirrors config/catalog_v15.php clamp; protects against owner
// misconfig (FK_CATALOG_KDS_*=10 → ~80 req/s/station DoS).
// [Wave 3b P1 / KDS-ADV3B-04 2026-05-18] Upper cap 60_000ms base / 30_000ms
// jitter protects against silent-blind misconfig (e.g. =999999999 → 11.5d).
var CADENCE_FLOOR_MS = 250;
var CADENCE_CEILING_MS = 60000;
var JITTER_CEILING_MS = 30000;
var DEFAULT_CADENCE_OPTIONS = Object.freeze({
  highActivityBaseMs: 3000,
  highActivityJitterMs: 1000,
  degradedBaseMs: 5000,
  degradedJitterMs: 2000,
  disconnectedBaseMs: 10000,
  disconnectedJitterMs: 3000
});
var KdsSyncService = /*#__PURE__*/function () {
  function KdsSyncService() {
    var _ref = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {},
      _ref$wsService = _ref.wsService,
      wsService = _ref$wsService === void 0 ? typeof window !== 'undefined' ? window._wsService : null : _ref$wsService,
      fetchFn = _ref.fetchFn,
      now = _ref.now;
    _classCallCheck(this, KdsSyncService);
    this.wsService = wsService || null;
    this.fetchFn = fetchFn || (typeof window !== 'undefined' && window.fetch ? window.fetch.bind(window) : null);
    this.now = now || function () {
      return Date.now();
    };
    this._listeners = new Map();
    this._state = KDS_SYNC_STATE.IDLE;
    this._started = false;
    this._branchId = null;
    this._lastSyncAt = null;
    this._currentIntervalMs = Infinity;
    this._timer = null;
    this._driftTimer = null;
    this._abortController = null;
    this._wsUnsubscribers = [];
    this._lastSince = null;
    this._versionMap = new Map();
    this._versionOrder = [];
    this._maxVersionEntries = 256;
    this._highActivityUntil = 0;
    this._consecutive5xx = 0;
    this._cadenceOptions = this._runtimeCadenceOptions();
  }
  return _createClass(KdsSyncService, [{
    key: "state",
    get: function get() {
      return this._state;
    }
  }, {
    key: "lastSyncAt",
    get: function get() {
      return this._lastSyncAt;
    }
  }, {
    key: "currentIntervalMs",
    get: function get() {
      return this._currentIntervalMs;
    }
  }, {
    key: "on",
    value: function on(eventName, callback) {
      if (!this._listeners.has(eventName)) {
        this._listeners.set(eventName, new Set());
      }
      var set = this._listeners.get(eventName);
      set.add(callback);
      return function () {
        set["delete"](callback);
      };
    }
  }, {
    key: "start",
    value: function start(branchId) {
      if (this._started) {
        return;
      }
      this._started = true;
      this._branchId = branchId;
      this._lastSince = this._lastSince || new Date(this.now()).toISOString();
      this._setState(KDS_SYNC_STATE.IDLE);
      this._bindWsState();
      this._recomputeCadence('initial');
    }
  }, {
    key: "stop",
    value: function stop() {
      if (!this._started && this._state === KDS_SYNC_STATE.STOPPED) {
        return;
      }
      this._started = false;
      this._branchId = null;
      this._clearTimers();
      if (this._abortController) {
        this._abortController.abort();
        this._abortController = null;
      }
      this._wsUnsubscribers.forEach(function (unsubscribe) {
        return unsubscribe && unsubscribe();
      });
      this._wsUnsubscribers = [];
      this._setState(KDS_SYNC_STATE.STOPPED);
    }
  }, {
    key: "forceSync",
    value: function () {
      var _forceSync = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var _this = this;
        var signal, branchQuery, response, payload, previousState, gatedIds, orders, syncPayload, _t;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.p = _context.n) {
            case 0:
              if (this.fetchFn) {
                _context.n = 1;
                break;
              }
              throw new Error('No fetch implementation available.');
            case 1:
              if (!this._lastSince) {
                this._lastSince = new Date(this.now()).toISOString();
              }
              if (this._abortController) {
                this._abortController.abort();
              }
              this._abortController = new AbortController();
              signal = this._abortController.signal;
              _context.p = 2;
              branchQuery = this._branchId !== null && this._branchId !== undefined ? "&branch_id=".concat(encodeURIComponent(this._branchId)) : '';
              _context.n = 3;
              return this.fetchFn("/api/admin/kds-order/sync?since=".concat(encodeURIComponent(this._lastSince)).concat(branchQuery, "&include_deleted=true"), {
                method: 'GET',
                credentials: 'same-origin',
                headers: this._authHeaders(),
                signal: signal
              });
            case 3:
              response = _context.v;
              if (!(signal.aborted || !this._started)) {
                _context.n = 4;
                break;
              }
              return _context.a(2, null);
            case 4:
              if (response.ok) {
                _context.n = 6;
                break;
              }
              _context.n = 5;
              return this._handleHttpError(response);
            case 5:
              return _context.a(2, null);
            case 6:
              _context.n = 7;
              return response.json();
            case 7:
              payload = _context.v;
              if (!(signal.aborted || !this._started)) {
                _context.n = 8;
                break;
              }
              return _context.a(2, null);
            case 8:
              this._consecutive5xx = 0;
              previousState = this._state;
              this._setState(KDS_SYNC_STATE.ACTIVE);
              if (previousState === KDS_SYNC_STATE.BACKOFF) {
                this._recomputeCadence('reset_after_200');
              }
              gatedIds = [];
              orders = (payload.orders || []).map(function (order) {
                var version = Number(order.version || 0);
                var previousVersion = _this._versionMap.get(order.id);
                var gated = previousVersion !== undefined && version <= previousVersion;
                if (gated) {
                  gatedIds.push(order.id);
                  return _objectSpread(_objectSpread({}, order), {}, {
                    versionGated: true
                  });
                }
                _this._rememberVersion(order.id, version);
                return _objectSpread(_objectSpread({}, order), {}, {
                  versionGated: false
                });
              });
              if (orders.filter(function (order) {
                return !order.versionGated;
              }).length > 5) {
                this._highActivityUntil = this.now() + 60000;
              }
              this._lastSyncAt = payload.server_now || new Date(this.now()).toISOString();
              this._lastSince = this._lastSyncAt;
              syncPayload = {
                orders: orders,
                deleted_ids: payload.deleted_ids || [],
                server_now: payload.server_now,
                lastSyncAt: this._lastSyncAt,
                gatedIds: gatedIds
              };
              this._emit('sync', syncPayload);
              this._recomputeCadence('post_sync');
              return _context.a(2, payload);
            case 9:
              _context.p = 9;
              _t = _context.v;
              if (!((_t === null || _t === void 0 ? void 0 : _t.name) === 'AbortError')) {
                _context.n = 10;
                break;
              }
              return _context.a(2, null);
            case 10:
              this._emit('error', {
                status: null,
                message: (_t === null || _t === void 0 ? void 0 : _t.message) || 'Sync failed',
                willRetryInMs: Number.isFinite(this._currentIntervalMs) ? this._currentIntervalMs : 0
              });

              // [F-03 / Lot 1.C / Audit G1 fix] Network-level errors (DNS, TLS,
              // ERR_NETWORK_CHANGED) MUST not silently halt the poll loop.
              // Re-schedule with the current cadence so the KDS self-heals once
              // connectivity returns; without this, a concurrent WS+HTTP outage
              // would leave the kitchen permanently blind.
              try {
                this._schedule();
              } catch (e) {/* defensive: never throw from catch path */}

              // Do not rethrow here: KDS sync runs as a background task and
              // bubbling network/Axios-like errors to the global scope creates
              // noisy unhandled rejections in runtime audits while auto-retry
              // is already scheduled.
              return _context.a(2, null);
          }
        }, _callee, this, [[2, 9]]);
      }));
      function forceSync() {
        return _forceSync.apply(this, arguments);
      }
      return forceSync;
    }()
  }, {
    key: "_bindWsState",
    value: function _bindWsState() {
      var _this2 = this;
      if (!this.wsService || typeof this.wsService.on !== 'function') {
        return;
      }
      var unsubscribe = this.wsService.on('state_change', function () {
        var _ref2 = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {},
          from = _ref2.from,
          to = _ref2.to;
        _this2._emit('state_change', {
          from: from,
          to: to
        });
        _this2._recomputeCadence(_this2._reasonFromWsState(to || _this2.wsService.state));
      });
      this._wsUnsubscribers.push(unsubscribe);

      // [NEW-02] React to a wsService-detected reconnect storm: bypass the
      // normal state_change cycle and run a single forceSync() so the
      // kitchen does not go blind during the cool-down window. We
      // intentionally do NOT alter the cadence formula — the next scheduled
      // poll keeps the WS_DEGRADED/WS_DISCONNECTED interval, which is
      // already short enough to bridge the breaker timeout.
      //
      // [NEW-02 audit G10] When the broadcasting server restarts, every
      // KDS station receives `reconnect_storm` simultaneously. Without
      // client-side jitter, all 50+ kitchens would hit
      // /api/admin/kds-order/sync at the same instant — server-side
      // Cache::remember softens the cost but the burst still amplifies
      // during an incident. We add a 0–500ms uniform jitter to spread
      // the herd; the breaker delay is 5–30s so 500ms is negligible from
      // the user's perspective.
      var unsubscribeStorm = this.wsService.on('reconnect_storm', function () {
        var payload = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {};
        _this2._emit('reconnect_storm', payload);
        var jitterMs = Math.floor(Math.random() * 500);
        var runForceSync = function runForceSync() {
          try {
            var result = _this2.forceSync();
            if (result && typeof result["catch"] === 'function') {
              result["catch"](function () {/* swallowed: 'error' listener handles it */});
            }
          } catch (_) {/* defensive: never propagate from event handler */}
        };
        if (jitterMs <= 0) {
          runForceSync();
        } else {
          setTimeout(runForceSync, jitterMs);
        }
      });
      this._wsUnsubscribers.push(unsubscribeStorm);
    }
  }, {
    key: "_reasonFromWsState",
    value: function _reasonFromWsState(state) {
      if (state === WS_CONNECTED) {
        return 'ws_connected';
      }
      if (state === WS_RECONNECTING || state === WS_DEGRADED) {
        return 'ws_degraded';
      }
      if (state === WS_DISCONNECTED || state === WS_SESSION_INVALID) {
        return 'ws_disconnected';
      }
      return 'ws_disconnected';
    }
  }, {
    key: "_baseCadence",
    value: function _baseCadence() {
      var _this$wsService;
      var wsState = (_this$wsService = this.wsService) === null || _this$wsService === void 0 ? void 0 : _this$wsService.state;
      var cfg = this._cadenceOptions;
      if (wsState === WS_CONNECTED) {
        return {
          interval: Infinity,
          reason: 'ws_connected'
        };
      }
      if (wsState === WS_RECONNECTING || wsState === WS_DEGRADED) {
        if (this.now() < this._highActivityUntil) {
          return {
            interval: cfg.highActivityBaseMs + this._jitter(cfg.highActivityJitterMs),
            reason: 'high_activity'
          };
        }
        return {
          interval: cfg.degradedBaseMs + this._jitter(cfg.degradedJitterMs),
          reason: 'ws_degraded'
        };
      }
      if (wsState === WS_DISCONNECTED || wsState === WS_SESSION_INVALID) {
        if (this.now() < this._highActivityUntil) {
          return {
            interval: cfg.highActivityBaseMs + this._jitter(cfg.highActivityJitterMs),
            reason: 'high_activity'
          };
        }
        return {
          interval: cfg.disconnectedBaseMs + this._jitter(cfg.disconnectedJitterMs),
          reason: 'ws_disconnected'
        };
      }
      return {
        interval: cfg.disconnectedBaseMs + this._jitter(cfg.disconnectedJitterMs),
        reason: 'ws_disconnected'
      };
    }
  }, {
    key: "_recomputeCadence",
    value: function _recomputeCadence() {
      var reasonOverride = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : null;
      if (!this._started) {
        return;
      }
      var previous = this._currentIntervalMs;
      var _this$_baseCadence = this._baseCadence(),
        interval = _this$_baseCadence.interval,
        reason = _this$_baseCadence.reason;
      var next = this._state === KDS_SYNC_STATE.BACKOFF ? Math.min(Number.isFinite(previous) ? previous * 2 : interval * 2, 30000) : interval;
      var emittedReason = this._state === KDS_SYNC_STATE.BACKOFF ? 'backoff_5xx' : reasonOverride && reasonOverride !== 'post_sync' && reasonOverride !== 'initial' ? reasonOverride : reason;
      this._currentIntervalMs = next;
      this._schedule();
      if (previous !== next) {
        this._emit('cadence_change', {
          from: previous,
          to: next,
          reason: emittedReason
        });
      }
    }
  }, {
    key: "_schedule",
    value: function _schedule() {
      var _this3 = this;
      this._clearTimers();
      if (!this._started) {
        return;
      }
      if (this._currentIntervalMs === Infinity) {
        this._driftTimer = setTimeout(function () {
          if (!_this3._started) {
            return;
          }
          _this3.forceSync()["catch"](function () {});
          _this3._schedule();
        }, 60000);
        return;
      }
      this._timer = setTimeout(function () {
        if (!_this3._started) {
          return;
        }
        _this3.forceSync()["catch"](function () {});
      }, this._currentIntervalMs);
    }
  }, {
    key: "_handleHttpError",
    value: function () {
      var _handleHttpError2 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(response) {
        var is5xx, previous, base;
        return _regenerator().w(function (_context2) {
          while (1) switch (_context2.n) {
            case 0:
              is5xx = response.status >= 500 && response.status <= 599;
              if (is5xx) {
                this._consecutive5xx += 1;
                this._setState(KDS_SYNC_STATE.BACKOFF);
                previous = this._currentIntervalMs;
                base = Number.isFinite(previous) ? previous : this._baseCadence().interval;
                this._currentIntervalMs = Math.min(base * 2, 30000);
                this._schedule();
                this._emit('cadence_change', {
                  from: previous,
                  to: this._currentIntervalMs,
                  reason: 'backoff_5xx'
                });
              }
              this._emit('error', {
                status: response.status,
                message: "KDS sync failed with status ".concat(response.status),
                willRetryInMs: Number.isFinite(this._currentIntervalMs) ? this._currentIntervalMs : 0
              });
            case 1:
              return _context2.a(2);
          }
        }, _callee2, this);
      }));
      function _handleHttpError(_x) {
        return _handleHttpError2.apply(this, arguments);
      }
      return _handleHttpError;
    }()
  }, {
    key: "_rememberVersion",
    value: function _rememberVersion(id, version) {
      if (!this._versionMap.has(id)) {
        this._versionOrder.push(id);
      }
      this._versionMap.set(id, version);
      while (this._versionOrder.length > this._maxVersionEntries) {
        var oldestId = this._versionOrder.shift();
        if (oldestId !== undefined) {
          this._versionMap["delete"](oldestId);
        }
      }
    }
  }, {
    key: "_setState",
    value: function _setState(next) {
      this._state = next;
    }
  }, {
    key: "_authHeaders",
    value: function _authHeaders() {
      var headers = {
        Accept: 'application/json',
        'x-api-key': _config_env__WEBPACK_IMPORTED_MODULE_0__["default"].API_KEY
      };
      try {
        var _vuex$kioskCart, _vuex$auth, _vuex$globalState;
        var vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
        var token = ((_vuex$kioskCart = vuex.kioskCart) === null || _vuex$kioskCart === void 0 ? void 0 : _vuex$kioskCart.kioskToken) || ((_vuex$auth = vuex.auth) === null || _vuex$auth === void 0 ? void 0 : _vuex$auth.authToken) || '';
        var language = ((_vuex$globalState = vuex.globalState) === null || _vuex$globalState === void 0 || (_vuex$globalState = _vuex$globalState.lists) === null || _vuex$globalState === void 0 ? void 0 : _vuex$globalState.language_code) || null;
        if (token) {
          headers.Authorization = "Bearer ".concat(token);
        }
        if (language) {
          headers['x-localization'] = language;
          headers['Accept-Language'] = language;
        }
      } catch (_) {/* corrupted localStorage falls back to unauthenticated headers */}
      return headers;
    }
  }, {
    key: "_clearTimers",
    value: function _clearTimers() {
      if (this._timer) {
        clearTimeout(this._timer);
        this._timer = null;
      }
      if (this._driftTimer) {
        clearTimeout(this._driftTimer);
        this._driftTimer = null;
      }
    }
  }, {
    key: "_emit",
    value: function _emit(eventName, payload) {
      var listeners = this._listeners.get(eventName);
      if (!listeners) {
        return;
      }
      listeners.forEach(function (callback) {
        return callback(payload);
      });
    }
  }, {
    key: "_jitter",
    value: function _jitter(max) {
      return Math.floor(Math.random() * (max + 1));
    }
  }, {
    key: "_runtimeCadenceOptions",
    value: function _runtimeCadenceOptions() {
      var _window$foodkingConfi;
      var cfg = typeof window !== 'undefined' && (_window$foodkingConfi = window.foodkingConfig) !== null && _window$foodkingConfi !== void 0 && _window$foodkingConfi.kdsFallbackPolling ? window.foodkingConfig.kdsFallbackPolling : {};

      // [Wave 3 P1 / KDS-RED-09 2026-05-18] Base cadences are clamped to
      // CADENCE_FLOOR_MS (250ms = 4 req/s) so an owner-misconfig like
      // window.foodkingConfig.kdsFallbackPolling.disconnectedBaseMs=10 cannot
      // weaponize the polling loop into PHP-FPM saturation. Jitters keep a
      // 0 floor since they widen — never shorten — the wait.
      // [Wave 3b P1 / KDS-ADV3B-04 2026-05-18] Upper cap CADENCE_CEILING_MS
      // (60_000ms = 1 poll/min minimum) and JITTER_CEILING_MS (30_000ms)
      // prevent the symmetric silent-blind misconfig where a runaway value
      // (e.g. disconnectedBaseMs=999999999) would stall KDS for ~11.5 days.
      var clampBase = function clampBase(value, fallback) {
        var parsed = parseInt(value, 10);
        var candidate = Number.isFinite(parsed) ? parsed : fallback;
        var floored = candidate >= CADENCE_FLOOR_MS ? candidate : CADENCE_FLOOR_MS;
        return floored <= CADENCE_CEILING_MS ? floored : CADENCE_CEILING_MS;
      };
      var clampJitter = function clampJitter(value, fallback) {
        var parsed = parseInt(value, 10);
        var candidate = Number.isFinite(parsed) ? parsed : fallback;
        var floored = candidate >= 0 ? candidate : 0;
        return floored <= JITTER_CEILING_MS ? floored : JITTER_CEILING_MS;
      };
      return {
        highActivityBaseMs: clampBase(cfg.highActivityBaseMs, DEFAULT_CADENCE_OPTIONS.highActivityBaseMs),
        highActivityJitterMs: clampJitter(cfg.highActivityJitterMs, DEFAULT_CADENCE_OPTIONS.highActivityJitterMs),
        degradedBaseMs: clampBase(cfg.degradedBaseMs, DEFAULT_CADENCE_OPTIONS.degradedBaseMs),
        degradedJitterMs: clampJitter(cfg.degradedJitterMs, DEFAULT_CADENCE_OPTIONS.degradedJitterMs),
        disconnectedBaseMs: clampBase(cfg.disconnectedBaseMs, DEFAULT_CADENCE_OPTIONS.disconnectedBaseMs),
        disconnectedJitterMs: clampJitter(cfg.disconnectedJitterMs, DEFAULT_CADENCE_OPTIONS.disconnectedJitterMs)
      };
    }
  }]);
}();
var kdsSyncService = new KdsSyncService();
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (kdsSyncService);

/***/ },

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css"
/*!**************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css ***!
  \**************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! !../../../../../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_style_index_0_id_151558cb_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! !!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css */ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css");

            

var options = {};

options.insert = "head";
options.singleton = false;

var update = _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default()(_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_style_index_0_id_151558cb_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"], options);



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_style_index_0_id_151558cb_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"].locals || {});

/***/ },

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css"
/*!**************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css ***!
  \**************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! !../../../../../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_style_index_0_id_5c6c7baf_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! !!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css */ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css");

            

var options = {};

options.insert = "head";
options.singleton = false;

var update = _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default()(_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_style_index_0_id_5c6c7baf_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"], options);



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_style_index_0_id_5c6c7baf_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"].locals || {});

/***/ },

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css"
/*!*****************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css ***!
  \*****************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! !../../../../../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_style_index_0_id_665158fe_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! !!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css */ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css");

            

var options = {};

options.insert = "head";
options.singleton = false;

var update = _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default()(_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_style_index_0_id_665158fe_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"], options);



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_style_index_0_id_665158fe_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"].locals || {});

/***/ },

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css"
/*!***********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css ***!
  \***********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! !../../../../../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_style_index_0_id_3992cfe5_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! !!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css */ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css");

            

var options = {};

options.insert = "head";
options.singleton = false;

var update = _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default()(_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_style_index_0_id_3992cfe5_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"], options);



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_style_index_0_id_3992cfe5_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"].locals || {});

/***/ },

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css"
/*!*******************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css ***!
  \*******************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! !../../../../../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_style_index_0_id_66aac04e_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! !!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css */ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css");

            

var options = {};

options.insert = "head";
options.singleton = false;

var update = _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default()(_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_style_index_0_id_66aac04e_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"], options);



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_style_index_0_id_66aac04e_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"].locals || {});

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue"
/*!*****************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue ***!
  \*****************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KdsOrderCard_vue_vue_type_template_id_151558cb_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true");
/* harmony import */ var _KdsOrderCard_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./KdsOrderCard.vue?vue&type=script&lang=js */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=script&lang=js");
/* harmony import */ var _KdsOrderCard_vue_vue_type_style_index_0_id_151558cb_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;


const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__["default"])(_KdsOrderCard_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_KdsOrderCard_vue_vue_type_template_id_151558cb_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render],['__scopeId',"data-v-151558cb"],['__file',"resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue"
/*!*****************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue ***!
  \*****************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KdsOrderLine_vue_vue_type_template_id_5c6c7baf_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true");
/* harmony import */ var _KdsOrderLine_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./KdsOrderLine.vue?vue&type=script&lang=js */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=script&lang=js");
/* harmony import */ var _KdsOrderLine_vue_vue_type_style_index_0_id_5c6c7baf_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css */ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;


const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__["default"])(_KdsOrderLine_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_KdsOrderLine_vue_vue_type_template_id_5c6c7baf_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render],['__scopeId',"data-v-5c6c7baf"],['__file',"resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue"
/*!********************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue ***!
  \********************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KdsStatusBanner_vue_vue_type_template_id_665158fe_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true */ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true");
/* harmony import */ var _KdsStatusBanner_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./KdsStatusBanner.vue?vue&type=script&lang=js */ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=script&lang=js");
/* harmony import */ var _KdsStatusBanner_vue_vue_type_style_index_0_id_665158fe_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css */ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;


const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__["default"])(_KdsStatusBanner_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_KdsStatusBanner_vue_vue_type_template_id_665158fe_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render],['__scopeId',"data-v-665158fe"],['__file',"resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue"
/*!**************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue ***!
  \**************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KdsV2Grid_vue_vue_type_template_id_3992cfe5_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true */ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true");
/* harmony import */ var _KdsV2Grid_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./KdsV2Grid.vue?vue&type=script&lang=js */ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=script&lang=js");
/* harmony import */ var _KdsV2Grid_vue_vue_type_style_index_0_id_3992cfe5_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css */ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;


const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__["default"])(_KdsV2Grid_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_KdsV2Grid_vue_vue_type_template_id_3992cfe5_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render],['__scopeId',"data-v-3992cfe5"],['__file',"resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue"
/*!**********************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue ***!
  \**********************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _KitchenDisplaySystemComponent_vue_vue_type_template_id_66aac04e_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true */ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true");
/* harmony import */ var _KitchenDisplaySystemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./KitchenDisplaySystemComponent.vue?vue&type=script&lang=js */ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=script&lang=js");
/* harmony import */ var _KitchenDisplaySystemComponent_vue_vue_type_style_index_0_id_66aac04e_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css */ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;


const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__["default"])(_KitchenDisplaySystemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_KitchenDisplaySystemComponent_vue_vue_type_template_id_66aac04e_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render],['__scopeId',"data-v-66aac04e"],['__file',"resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=script&lang=js"
/*!*****************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=script&lang=js ***!
  \*****************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderCard.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=script&lang=js"
/*!*****************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=script&lang=js ***!
  \*****************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderLine.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=script&lang=js"
/*!********************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=script&lang=js ***!
  \********************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsStatusBanner.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=script&lang=js"
/*!**************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=script&lang=js ***!
  \**************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsV2Grid.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=script&lang=js"
/*!**********************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=script&lang=js ***!
  \**********************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KitchenDisplaySystemComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true"
/*!***********************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true ***!
  \***********************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_template_id_151558cb_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_template_id_151558cb_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=template&id=151558cb&scoped=true");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true"
/*!***********************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true ***!
  \***********************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_template_id_5c6c7baf_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_template_id_5c6c7baf_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=template&id=5c6c7baf&scoped=true");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true"
/*!**************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true ***!
  \**************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_template_id_665158fe_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_template_id_665158fe_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=template&id=665158fe&scoped=true");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true"
/*!********************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true ***!
  \********************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_template_id_3992cfe5_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_template_id_3992cfe5_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=template&id=3992cfe5&scoped=true");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true"
/*!****************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true ***!
  \****************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_template_id_66aac04e_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_template_id_66aac04e_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=template&id=66aac04e&scoped=true");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css"
/*!*************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css ***!
  \*************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderCard_vue_vue_type_style_index_0_id_151558cb_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue?vue&type=style&index=0&id=151558cb&scoped=true&lang=css");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css"
/*!*************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css ***!
  \*************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsOrderLine_vue_vue_type_style_index_0_id_5c6c7baf_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue?vue&type=style&index=0&id=5c6c7baf&scoped=true&lang=css");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css"
/*!****************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css ***!
  \****************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsStatusBanner_vue_vue_type_style_index_0_id_665158fe_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue?vue&type=style&index=0&id=665158fe&scoped=true&lang=css");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css"
/*!**********************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css ***!
  \**********************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KdsV2Grid_vue_vue_type_style_index_0_id_3992cfe5_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue?vue&type=style&index=0&id=3992cfe5&scoped=true&lang=css");


/***/ },

/***/ "./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css"
/*!******************************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css ***!
  \******************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_KitchenDisplaySystemComponent_vue_vue_type_style_index_0_id_66aac04e_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue?vue&type=style&index=0&id=66aac04e&scoped=true&lang=css");


/***/ }

}]);