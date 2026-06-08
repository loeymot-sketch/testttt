"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["resources_js_components_admin_ingredients_IngredientListComponent_vue"],{

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=script&lang=js"
/*!************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=script&lang=js ***!
  \************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var vuex__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vuex */ "./node_modules/vuex/dist/vuex.esm-bundler.js");
/* harmony import */ var _IngredientUsageDrawer_vue__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./IngredientUsageDrawer.vue */ "./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }


var TYPE_LABELS = {
  attribute: 'label.ingredient.tab_attribute',
  extra: 'label.ingredient.tab_extra',
  addon: 'label.ingredient.tab_addon'
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: 'IngredientListComponent',
  components: {
    IngredientUsageDrawer: _IngredientUsageDrawer_vue__WEBPACK_IMPORTED_MODULE_1__["default"]
  },
  props: {
    type: {
      type: String,
      "default": null
    }
  },
  data: function data() {
    var _this$$route;
    return {
      activeTab: this.normalizedType(this.type || ((_this$$route = this.$route) === null || _this$$route === void 0 || (_this$$route = _this$$route.params) === null || _this$$route === void 0 ? void 0 : _this$$route.type) || 'all'),
      drawerOpen: false,
      selectedGlobalId: null,
      notification: '',
      tabs: [{
        value: 'all',
        label: 'label.ingredient.tab_all'
      }, {
        value: 'attribute',
        label: 'label.ingredient.tab_attribute'
      }, {
        value: 'extra',
        label: 'label.ingredient.tab_extra'
      }, {
        value: 'addon',
        label: 'label.ingredient.tab_addon'
      }]
    };
  },
  computed: _objectSpread(_objectSpread({}, (0,vuex__WEBPACK_IMPORTED_MODULE_0__.mapState)('ingredients', ['list', 'loading', 'error'])), {}, {
    ingredients: function ingredients() {
      return this.list;
    },
    totalCount: function totalCount() {
      return this.list.length;
    }
  }),
  watch: {
    type: function type(value) {
      this.activeTab = this.normalizedType(value || 'all');
      this.fetchIngredients();
    },
    '$route.params.type': function $routeParamsType(value) {
      this.activeTab = this.normalizedType(value || 'all');
      this.fetchIngredients();
    }
  },
  mounted: function mounted() {
    this.fetchIngredients();
  },
  methods: {
    normalizedType: function normalizedType(value) {
      return ['attribute', 'extra', 'addon'].includes(value) ? value : 'all';
    },
    fetchIngredients: function fetchIngredients() {
      var params = this.activeTab === 'all' ? {} : {
        type: this.activeTab
      };
      return this.$store.dispatch('ingredients/fetch', params);
    },
    selectTab: function selectTab(tab) {
      this.activeTab = tab;
      this.fetchIngredients();
    },
    focusTab: function focusTab(index) {
      var _this = this;
      var tab = this.tabs[index];
      if (!tab) return;
      this.selectTab(tab.value);
      this.$nextTick(function () {
        var _buttons$index;
        var buttons = Array.isArray(_this.$refs.tabButtons) ? _this.$refs.tabButtons : [_this.$refs.tabButtons].filter(Boolean);
        (_buttons$index = buttons[index]) === null || _buttons$index === void 0 || _buttons$index.focus();
      });
    },
    focusNextTab: function focusNextTab(index) {
      this.focusTab((index + 1) % this.tabs.length);
    },
    focusPrevTab: function focusPrevTab(index) {
      this.focusTab((index - 1 + this.tabs.length) % this.tabs.length);
    },
    focusFirstTab: function focusFirstTab() {
      this.focusTab(0);
    },
    focusLastTab: function focusLastTab() {
      this.focusTab(this.tabs.length - 1);
    },
    typeLabel: function typeLabel(type) {
      return this.$t(TYPE_LABELS[type] || 'label.ingredient.column_type');
    },
    openUsage: function openUsage(globalId) {
      this.selectedGlobalId = globalId;
      this.drawerOpen = true;
    },
    handleToggled: function handleToggled() {
      this.notification = this.$t('message.ingredient.toggle_success');
    },
    handleToggleError: function handleToggleError() {
      this.notification = this.$t('message.ingredient.toggle_error');
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=script&lang=js"
/*!**********************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=script&lang=js ***!
  \**********************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _services_ingredientService__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../services/ingredientService */ "./resources/js/services/ingredientService.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }

/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: 'IngredientUsageDrawer',
  props: {
    globalId: {
      type: String,
      "default": null
    },
    isOpen: {
      type: Boolean,
      "default": false
    }
  },
  emits: ['close'],
  computed: {
    // Render a human, localized ingredient-type label instead of the raw
    // internal globalId token (`type:id`, e.g. "attribute:8"). The type
    // prefix maps to the same labels as the list tabs.
    typeLabel: function typeLabel() {
      var type = String(this.globalId || '').split(':')[0];
      var map = {
        attribute: 'label.ingredient.tab_attribute',
        extra: 'label.ingredient.tab_extra',
        addon: 'label.ingredient.tab_addon'
      };
      return map[type] ? this.$t(map[type]) : '';
    }
  },
  data: function data() {
    return {
      loading: false,
      error: null,
      usedBy: [],
      usedByCount: 0,
      ingredientName: '',
      isAvailable: true,
      titleId: "ingredient-usage-title-".concat(Math.random().toString(36).slice(2))
    };
  },
  watch: {
    isOpen: {
      immediate: true,
      handler: function handler(value) {
        var _this = this;
        if (value) {
          this.$nextTick(function () {
            var _this$$refs$dialog;
            (_this$$refs$dialog = _this.$refs.dialog) === null || _this$$refs$dialog === void 0 || _this$$refs$dialog.focus();
          });
          this.loadUsage();
        }
      }
    },
    globalId: function globalId() {
      if (this.isOpen) this.loadUsage();
    }
  },
  methods: {
    loadUsage: function loadUsage() {
      var _this2 = this;
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var _response$data, response, data, _t;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.p = _context.n) {
            case 0:
              if (_this2.globalId) {
                _context.n = 1;
                break;
              }
              return _context.a(2);
            case 1:
              _this2.loading = true;
              _this2.error = null;
              _this2.usedBy = [];
              _this2.usedByCount = 0;
              _this2.ingredientName = '';
              _this2.isAvailable = true;
              _context.p = 2;
              _context.n = 3;
              return (0,_services_ingredientService__WEBPACK_IMPORTED_MODULE_0__.getIngredientUsage)(_this2.globalId);
            case 3:
              response = _context.v;
              data = (response === null || response === void 0 || (_response$data = response.data) === null || _response$data === void 0 ? void 0 : _response$data.data) || {};
              _this2.usedBy = Array.isArray(data.used_by) ? data.used_by : [];
              _this2.usedByCount = Number(data.used_by_count || 0);
              _this2.ingredientName = String(data.name || '');
              _this2.isAvailable = Boolean(data.is_available);
              _context.n = 5;
              break;
            case 4:
              _context.p = 4;
              _t = _context.v;
              _this2.error = _t;
            case 5:
              _context.p = 5;
              _this2.loading = false;
              return _context.f(5);
            case 6:
              return _context.a(2);
          }
        }, _callee, null, [[2, 4, 5, 6]]);
      }))();
    },
    close: function close() {
      this.$emit('close');
    },
    trapFocus: function trapFocus(event) {
      var _this$$refs$dialog2;
      var focusable = (_this$$refs$dialog2 = this.$refs.dialog) === null || _this$$refs$dialog2 === void 0 ? void 0 : _this$$refs$dialog2.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!focusable || focusable.length === 0) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        last.focus();
        return;
      }
      if (!event.shiftKey && document.activeElement === last) {
        first.focus();
        return;
      }
      if (!this.$refs.dialog.contains(document.activeElement)) {
        first.focus();
      }
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=template&id=7af5e774"
/*!****************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=template&id=7af5e774 ***!
  \****************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-busy"];
var _hoisted_2 = {
  "class": "rounded border border-neutral-200 bg-white p-5 shadow-sm"
};
var _hoisted_3 = {
  "class": "flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
};
var _hoisted_4 = {
  "class": "mt-1 text-xl font-semibold text-slate-900"
};
var _hoisted_5 = {
  "class": "mt-1 text-sm text-slate-500"
};
var _hoisted_6 = {
  "class": "rounded bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700"
};
var _hoisted_7 = ["aria-label"];
var _hoisted_8 = ["id", "aria-selected", "aria-controls", "tabindex", "onClick", "onKeydown"];
var _hoisted_9 = {
  key: 0,
  "class": "rounded border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700",
  role: "alert"
};
var _hoisted_10 = ["id", "aria-labelledby"];
var _hoisted_11 = {
  key: 0,
  "class": "p-6 text-sm text-slate-500"
};
var _hoisted_12 = {
  key: 1,
  "class": "p-6 text-center text-sm text-slate-500",
  "data-testid": "ingredient-empty"
};
var _hoisted_13 = {
  key: 2,
  "class": "overflow-x-auto"
};
var _hoisted_14 = {
  "class": "min-w-full divide-y divide-neutral-200",
  "aria-live": "polite"
};
var _hoisted_15 = {
  "class": "sr-only"
};
var _hoisted_16 = {
  "class": "bg-slate-50"
};
var _hoisted_17 = {
  scope: "col",
  "class": "px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
};
var _hoisted_18 = {
  scope: "col",
  "class": "px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
};
var _hoisted_19 = {
  scope: "col",
  "class": "px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
};
var _hoisted_20 = {
  scope: "col",
  "class": "px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
};
var _hoisted_21 = {
  "class": "divide-y divide-neutral-100 bg-white"
};
var _hoisted_22 = ["data-global-id"];
var _hoisted_23 = {
  scope: "row",
  "class": "px-4 py-3 text-left"
};
var _hoisted_24 = {
  "class": "text-sm font-semibold text-slate-900"
};
var _hoisted_25 = {
  key: 0,
  "class": "text-xs text-slate-500"
};
var _hoisted_26 = {
  "class": "px-4 py-3"
};
var _hoisted_27 = {
  "class": "rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700"
};
var _hoisted_28 = {
  "class": "px-4 py-3 text-sm text-slate-600"
};
var _hoisted_29 = {
  "class": "px-4 py-3"
};
var _hoisted_30 = ["onClick"];
var _hoisted_31 = {
  "class": "sr-only",
  "aria-live": "polite"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_IngredientUsageDrawer = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("IngredientUsageDrawer");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("section", {
    "class": "space-y-4",
    "data-testid": "ingredient-list",
    "aria-busy": _ctx.loading ? 'true' : 'false'
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("header", _hoisted_2, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_3, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", null, [_cache[4] || (_cache[4] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", {
    "class": "text-xs font-semibold uppercase tracking-wide text-rose-700"
  }, " FoodKing V1 ", -1 /* CACHED */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h1", _hoisted_4, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.title')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_5, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.subtitle')), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_6, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.totalCount), 1 /* TEXT */)])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("nav", {
    "class": "flex flex-wrap gap-2",
    role: "tablist",
    "aria-label": _ctx.$t('label.ingredient.tablist_label')
  }, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.tabs, function (tab, index) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("button", {
      key: tab.value,
      ref_for: true,
      ref: "tabButtons",
      type: "button",
      role: "tab",
      id: "tab-".concat(tab.value),
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["rounded border px-3 py-2 text-sm font-semibold transition", $data.activeTab === tab.value ? 'border-rose-700 bg-rose-700 text-white' : 'border-neutral-200 bg-white text-slate-600 hover:bg-slate-50']),
      "aria-selected": $data.activeTab === tab.value ? 'true' : 'false',
      "aria-controls": "panel-".concat(tab.value),
      tabindex: $data.activeTab === tab.value ? 0 : -1,
      onClick: function onClick($event) {
        return $options.selectTab(tab.value);
      },
      onKeydown: [(0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)((0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
        return $options.focusNextTab(index);
      }, ["prevent"]), ["right"]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)((0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function ($event) {
        return $options.focusPrevTab(index);
      }, ["prevent"]), ["left"]), _cache[0] || (_cache[0] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)((0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
        return $options.focusFirstTab && $options.focusFirstTab.apply($options, arguments);
      }, ["prevent"]), ["home"])), _cache[1] || (_cache[1] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)((0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
        return $options.focusLastTab && $options.focusLastTab.apply($options, arguments);
      }, ["prevent"]), ["end"]))]
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t(tab.label)), 43 /* TEXT, CLASS, PROPS, NEED_HYDRATION */, _hoisted_8);
  }), 128 /* KEYED_FRAGMENT */))], 8 /* PROPS */, _hoisted_7), _ctx.error ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_9, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.error')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    type: "button",
    "class": "mt-2 rounded bg-rose-700 px-3 py-1.5 text-white",
    onClick: _cache[2] || (_cache[2] = function () {
      return $options.fetchIngredients && $options.fetchIngredients.apply($options, arguments);
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.retry')), 1 /* TEXT */)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("article", {
    id: "panel-".concat($data.activeTab),
    "class": "overflow-hidden rounded border border-neutral-200 bg-white shadow-sm",
    role: "tabpanel",
    "aria-labelledby": "tab-".concat($data.activeTab),
    tabindex: "0"
  }, [_ctx.loading ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_11, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.loading')), 1 /* TEXT */)) : $options.ingredients.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_12, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.empty')), 1 /* TEXT */)) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_13, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("table", _hoisted_14, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("caption", _hoisted_15, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.table_caption')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("thead", _hoisted_16, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("tr", null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("th", _hoisted_17, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.column_name')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("th", _hoisted_18, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.column_type')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("th", _hoisted_19, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.column_usage')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("th", _hoisted_20, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.column_actions')), 1 /* TEXT */)])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("tbody", _hoisted_21, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.ingredients, function (ingredient) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("tr", {
      key: ingredient.global_id,
      "data-global-id": ingredient.global_id
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("th", _hoisted_23, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_24, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(ingredient.name), 1 /* TEXT */), ingredient.group_label ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_25, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(ingredient.group_label), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("td", _hoisted_26, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_27, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.typeLabel(ingredient.type)), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("td", _hoisted_28, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)((ingredient.used_by_count || 0) > 0 ? _ctx.$t('label.ingredient.usage_count', {
      count: ingredient.used_by_count
    }) : _ctx.$t('label.ingredient.usage_none')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("td", _hoisted_29, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
      type: "button",
      "class": "rounded border border-neutral-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50",
      onClick: function onClick($event) {
        return $options.openUsage(ingredient.global_id);
      }
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.view_details')), 9 /* TEXT, PROPS */, _hoisted_30)])], 8 /* PROPS */, _hoisted_22);
  }), 128 /* KEYED_FRAGMENT */))])])]))], 8 /* PROPS */, _hoisted_10), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_31, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($data.notification), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_IngredientUsageDrawer, {
    "global-id": $data.selectedGlobalId,
    "is-open": $data.drawerOpen,
    onClose: _cache[3] || (_cache[3] = function ($event) {
      return $data.drawerOpen = false;
    })
  }, null, 8 /* PROPS */, ["global-id", "is-open"])], 8 /* PROPS */, _hoisted_1);
}

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e"
/*!**************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e ***!
  \**************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-label"];
var _hoisted_2 = ["aria-labelledby"];
var _hoisted_3 = {
  "class": "flex items-center justify-between border-b border-slate-200 px-5 py-4"
};
var _hoisted_4 = ["id"];
var _hoisted_5 = {
  key: 0,
  "class": "mt-1 text-xs text-slate-500"
};
var _hoisted_6 = ["aria-label"];
var _hoisted_7 = {
  "class": "flex-1 overflow-y-auto px-5 py-4"
};
var _hoisted_8 = {
  key: 0,
  "class": "text-sm text-slate-500",
  "aria-live": "polite",
  "data-testid": "ingredient-usage-loading"
};
var _hoisted_9 = {
  key: 1,
  "class": "rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700",
  "aria-live": "polite",
  "data-testid": "ingredient-usage-error"
};
var _hoisted_10 = {
  "class": "rounded border border-slate-200 bg-slate-50 px-3 py-2"
};
var _hoisted_11 = {
  "class": "text-sm font-semibold text-slate-700",
  "data-testid": "ingredient-usage-name"
};
var _hoisted_12 = {
  key: 0,
  "class": "ml-2 inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700"
};
var _hoisted_13 = {
  "class": "mt-1 text-xs text-slate-500",
  "data-testid": "ingredient-usage-count"
};
var _hoisted_14 = {
  key: 0,
  "class": "mt-4 text-sm text-slate-500",
  "data-testid": "ingredient-usage-empty"
};
var _hoisted_15 = {
  key: 1,
  "class": "mt-4 space-y-2",
  role: "list",
  "data-testid": "ingredient-usage-list"
};
var _hoisted_16 = ["data-testid"];
var _hoisted_17 = ["href"];
var _hoisted_18 = {
  "class": "mt-1 text-xs text-slate-500"
};
var _hoisted_19 = {
  "class": "inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-xxs font-semibold uppercase tracking-wide"
};
var _hoisted_20 = {
  "class": "ml-2"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Teleport, {
    to: "body"
  }, [$props.isOpen ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    key: 0,
    "class": "fixed inset-0 z-50",
    onKeydown: [_cache[2] || (_cache[2] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)(function () {
      return $options.close && $options.close.apply($options, arguments);
    }, ["esc"])), _cache[3] || (_cache[3] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withKeys)((0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
      return $options.trapFocus && $options.trapFocus.apply($options, arguments);
    }, ["prevent"]), ["tab"]))]
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    ref: "backdrop",
    type: "button",
    "class": "absolute inset-0 h-full w-full bg-slate-900/40",
    "aria-label": _ctx.$t('label.ingredient.usage_drawer_close'),
    "data-testid": "ingredient-usage-backdrop",
    onClick: _cache[0] || (_cache[0] = function () {
      return $options.close && $options.close.apply($options, arguments);
    })
  }, null, 8 /* PROPS */, _hoisted_1), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("aside", {
    ref: "dialog",
    role: "dialog",
    "aria-modal": "true",
    "aria-labelledby": $data.titleId,
    "class": "absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-xl",
    tabindex: "-1",
    "data-testid": "ingredient-usage-drawer"
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("header", _hoisted_3, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h2", {
    id: $data.titleId,
    "class": "text-base font-semibold text-slate-900"
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.usage_drawer_title')), 9 /* TEXT, PROPS */, _hoisted_4), $options.typeLabel ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_5, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.typeLabel), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    ref: "closeButton",
    type: "button",
    "class": "rounded px-2 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-100",
    "aria-label": _ctx.$t('label.ingredient.usage_drawer_close'),
    onClick: _cache[1] || (_cache[1] = function () {
      return $options.close && $options.close.apply($options, arguments);
    })
  }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.usage_drawer_close')), 9 /* TEXT, PROPS */, _hoisted_6)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_7, [$data.loading ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_8, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.loading')), 1 /* TEXT */)) : $data.error ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_9, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.error')), 1 /* TEXT */)) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    key: 2
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" En-tête : nom + statut + count "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_10, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_11, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)((0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($data.ingredientName) + " ", 1 /* TEXT */), !$data.isAvailable ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_12, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.status_unavailable')), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_13, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($data.usedByCount > 0 ? _ctx.$t('label.ingredient.usage_count', {
    count: $data.usedByCount
  }) : _ctx.$t('label.ingredient.usage_none')), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Liste used_by "), $data.usedBy.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_14, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.ingredient.usage_empty')), 1 /* TEXT */)) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("ul", _hoisted_15, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.usedBy, function (entry) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
      key: "".concat(entry.owner_type, ":").concat(entry.owner_id, ":").concat(entry.wizard_profile_id),
      "class": "rounded border border-slate-200 bg-white px-3 py-2",
      "data-testid": "ingredient-usage-entry-".concat(entry.owner_type, "-").concat(entry.owner_id)
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("a", {
      href: entry.admin_url,
      "class": "text-sm font-semibold text-blue-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
    }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(entry.owner_name), 9 /* TEXT, PROPS */, _hoisted_17), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_18, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_19, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(entry.owner_type === 'category' ? _ctx.$t('label.ingredient.owner_category') : _ctx.$t('label.ingredient.owner_item')), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_20, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(entry.step_label), 1 /* TEXT */)])], 8 /* PROPS */, _hoisted_16);
  }), 128 /* KEYED_FRAGMENT */))]))], 64 /* STABLE_FRAGMENT */))])], 8 /* PROPS */, _hoisted_2)], 32 /* NEED_HYDRATION */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
}

/***/ },

/***/ "./resources/js/components/admin/ingredients/IngredientListComponent.vue"
/*!*******************************************************************************!*\
  !*** ./resources/js/components/admin/ingredients/IngredientListComponent.vue ***!
  \*******************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _IngredientListComponent_vue_vue_type_template_id_7af5e774__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./IngredientListComponent.vue?vue&type=template&id=7af5e774 */ "./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=template&id=7af5e774");
/* harmony import */ var _IngredientListComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./IngredientListComponent.vue?vue&type=script&lang=js */ "./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_IngredientListComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_IngredientListComponent_vue_vue_type_template_id_7af5e774__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/admin/ingredients/IngredientListComponent.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue"
/*!*****************************************************************************!*\
  !*** ./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue ***!
  \*****************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _IngredientUsageDrawer_vue_vue_type_template_id_388f3b0e__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e */ "./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e");
/* harmony import */ var _IngredientUsageDrawer_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./IngredientUsageDrawer.vue?vue&type=script&lang=js */ "./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_IngredientUsageDrawer_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_IngredientUsageDrawer_vue_vue_type_template_id_388f3b0e__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/admin/ingredients/IngredientUsageDrawer.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=script&lang=js"
/*!*******************************************************************************************************!*\
  !*** ./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=script&lang=js ***!
  \*******************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientListComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientListComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./IngredientListComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=script&lang=js"
/*!*****************************************************************************************************!*\
  !*** ./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=script&lang=js ***!
  \*****************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientUsageDrawer_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientUsageDrawer_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./IngredientUsageDrawer.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=template&id=7af5e774"
/*!*************************************************************************************************************!*\
  !*** ./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=template&id=7af5e774 ***!
  \*************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientListComponent_vue_vue_type_template_id_7af5e774__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientListComponent_vue_vue_type_template_id_7af5e774__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./IngredientListComponent.vue?vue&type=template&id=7af5e774 */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientListComponent.vue?vue&type=template&id=7af5e774");


/***/ },

/***/ "./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e"
/*!***********************************************************************************************************!*\
  !*** ./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e ***!
  \***********************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientUsageDrawer_vue_vue_type_template_id_388f3b0e__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IngredientUsageDrawer_vue_vue_type_template_id_388f3b0e__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/ingredients/IngredientUsageDrawer.vue?vue&type=template&id=388f3b0e");


/***/ }

}]);