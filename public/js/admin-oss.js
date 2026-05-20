"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["admin-oss"],{

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css"
/*!*********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css ***!
  \*********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
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
___CSS_LOADER_EXPORT___.push([module.id, "\n/* [iter15-mega-fix B-003/D-002 2026-05-10] .ws-reconnect-banner CSS removed:\n   the only consumer of this class was the duplicate banner deleted from the\n   template above. Connection status is owned by ConnectionStatusBanner.vue. */\n/* Slide-in for preparing column */\n.oss-slide-enter-active[data-v-3aa5d0ca] { transition: all 0.4s ease;\n}\n.oss-slide-leave-active[data-v-3aa5d0ca] { transition: all 0.3s ease;\n}\n.oss-slide-enter-from[data-v-3aa5d0ca]   { opacity: 0; transform: translateX(-20px);\n}\n.oss-slide-leave-to[data-v-3aa5d0ca]     { opacity: 0; transform: translateX(20px);\n}\n\n/* Pop-in for ready column */\n.oss-pop-enter-active[data-v-3aa5d0ca] { transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);\n}\n.oss-pop-leave-active[data-v-3aa5d0ca] { transition: all 0.3s ease;\n}\n.oss-pop-enter-from[data-v-3aa5d0ca]   { opacity: 0; transform: scale(0.6);\n}\n.oss-pop-leave-to[data-v-3aa5d0ca]     { opacity: 0; transform: scale(0.8);\n}\n\n/* Highlight for newly-ready orders — initial bounce burst */\n.oss-new-ready[data-v-3aa5d0ca] {\n  animation: oss-bounce-3aa5d0ca 0.6s ease 2;\n}\n@keyframes oss-bounce-3aa5d0ca {\n0%, 100% { transform: scale(1);\n}\n50%       { transform: scale(1.12);\n}\n}\n\n/* [Wave S-3 TV-optim P-OWNER 2026-05-20] Long-tail pulse to attract customer\n   attention at ≥3m for the full 10s window while the order ID is in\n   newReadyIds. Subtle scale + green halo via text-shadow — does NOT shift\n   layout (transform-only) so neighbouring items don't reflow. Pulse runs\n   alongside the initial .oss-new-ready bounce (different keyframe names,\n   no conflict) and continues as `infinite` until the class is removed by\n   the JS timeout in _markNewReady. */\n.oss-pulse-ready[data-v-3aa5d0ca] {\n  animation: oss-pulse-3aa5d0ca 1.6s ease-in-out infinite;\n}\n@keyframes oss-pulse-3aa5d0ca {\n0%, 100% {\n    transform: scale(1);\n    text-shadow: 0 0 0 rgba(14, 124, 58, 0);\n}\n50% {\n    transform: scale(1.04);\n    text-shadow: 0 0 24px rgba(14, 124, 58, 0.55);\n}\n}\n\n/* Flash the entire ready column green when a new order is ready */\n.oss-ready-flash[data-v-3aa5d0ca] {\n  animation: oss-flash-3aa5d0ca 0.8s ease 2;\n}\n@keyframes oss-flash-3aa5d0ca {\n0%, 100% { background-color: transparent;\n}\n50%       { background-color: rgba(26, 183, 89, 0.15);\n}\n}\n\n/* [Wave S-3 TV-optim P-OWNER 2026-05-20] Vertical auto-scroll loop for busy\n   columns (>8 orders). Pure-CSS keyframe — no JS RAF — so it never fights\n   <transition-group> on enter/leave. Loops every 30s with a 2s pause at the\n   start so freshly-arrived orders sit visible before scroll begins. We\n   translateY a copy-free list and rely on overflow-hidden on the parent;\n   when the column drops below the threshold the class is removed and the\n   list snaps back to translateY(0) cleanly. Limit applies to either column\n   independently. */\n.oss-order-list[data-v-3aa5d0ca] {\n  will-change: transform;\n}\n.oss-autoscroll[data-v-3aa5d0ca] {\n  animation: oss-scroll-loop-3aa5d0ca 30s linear infinite;\n}\n@keyframes oss-scroll-loop-3aa5d0ca {\n0%   { transform: translateY(0);\n}\n10%  { transform: translateY(0);\n}\n90%  { transform: translateY(-50%);\n}\n100% { transform: translateY(0);\n}\n}\n\n/* Respect operator preferences — disable motion for sensitive contexts. */\n@media (prefers-reduced-motion: reduce) {\n.oss-pulse-ready[data-v-3aa5d0ca],\n  .oss-autoscroll[data-v-3aa5d0ca],\n  .oss-new-ready[data-v-3aa5d0ca],\n  .oss-ready-flash[data-v-3aa5d0ca] {\n    animation: none !important;\n}\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js"
/*!*********************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js ***!
  \*********************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _PreparingAndReadyComponent__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./PreparingAndReadyComponent */ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue");
/* harmony import */ var _common_ConnectionStatusBanner_vue__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../../common/ConnectionStatusBanner.vue */ "./resources/js/components/common/ConnectionStatusBanner.vue");


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: "OrderStatusScreenComponent",
  components: {
    ConnectionStatusBanner: _common_ConnectionStatusBanner_vue__WEBPACK_IMPORTED_MODULE_1__["default"],
    PreparingAndReadyComponent: _PreparingAndReadyComponent__WEBPACK_IMPORTED_MODULE_0__["default"]
  },
  data: function data() {
    return {};
  },
  mounted: function mounted() {
    this.closeSidebar();
  },
  methods: {
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
      var _document3, _document4;
      (_document3 = document) === null || _document3 === void 0 || (_document3 = _document3.querySelector(".db-main")) === null || _document3 === void 0 || (_document3 = _document3.classList) === null || _document3 === void 0 || _document3.add("expand");
      // [W8 FIX] Full optional chain — querySelector can return null if .db-header is absent
      (_document4 = document) === null || _document4 === void 0 || (_document4 = _document4.querySelector('.db-header')) === null || _document4 === void 0 || (_document4 = _document4.classList) === null || _document4 === void 0 || _document4.remove("active", "hidden");
    }
  },
  beforeUnmount: function beforeUnmount() {
    this.openSidebar();
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js"
/*!*********************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js ***!
  \*********************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _components_LoadingContentComponent__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../components/LoadingContentComponent */ "./resources/js/components/admin/components/LoadingContentComponent.vue");
/* harmony import */ var _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../../../enums/modules/orderStatusEnum */ "./resources/js/enums/modules/orderStatusEnum.js");
/* harmony import */ var _services_alertService__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../services/alertService */ "./resources/js/services/alertService.js");
/* harmony import */ var _services_eventContract__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../services/eventContract */ "./resources/js/services/eventContract.js");
/* harmony import */ var _services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../../../services/OssSyncService */ "./resources/js/services/OssSyncService.js");
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// [RED-team R4 V1.0.2 2026-05-17] wakeLock screen for TV wall surfaces.
// Customer TV idles long between order events; without `navigator.wakeLock.request('screen')`
// the OS screen-saver sleeps the display, making the green flash + chime invisible/inaudible
// when a new order moves to PREPARED. Acquire on mount, re-acquire on `visibilitychange`
// (browsers auto-release on tab switch / OS lock), release on unmount. Graceful degrade on
// browsers without API (Safari iOS <16.4); feature-flag `window.foodkingConfig.ossWakeLockEnabled`
// (default true). No external deps — native browser API.





/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: "PreparingAndReadyComponent",
  components: {
    LoadingContentComponent: _components_LoadingContentComponent__WEBPACK_IMPORTED_MODULE_0__["default"]
  },
  data: function data() {
    var _window$_wsService;
    return {
      loading: {
        isActive: false
      },
      preparedItems: [],
      preparingItems: [],
      enums: {
        orderStatusEnum: _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_1__["default"]
      },
      wsConnected: !!((_window$_wsService = window._wsService) !== null && _window$_wsService !== void 0 && _window$_wsService.isConnected()),
      _eventSub: null,
      ossSyncUnsubscribers: [],
      // IDs des commandes nouvellement passées à PREPARED (pour animation)
      newReadyIds: new Set(),
      newReadyFlash: false,
      _flashTimer: null,
      // [iter15-mega-fix C-034 round-7 2026-05-10] AudioContext is now
      // lazy-initialized on the first user gesture. Prior implementation
      // created a fresh suspended context on EVERY Echo `prepared` event, which
      // flooded the customer screen console with autoplay warnings (~8x per
      // session) because Chrome blocks AudioContext until a user gesture.
      _audioCtx: null,
      _audioInitListener: null,
      // [RED-team R4 V1.0.2 2026-05-17] wakeLock sentinel + visibility handler refs
      _wakeLockSentinel: null,
      _onVisibilityChange: null
    };
  },
  computed: {},
  mounted: function mounted() {
    var _this = this;
    this.list();
    window.addEventListener('realtime-order-update', this.list);
    this.subscribeEcho();
    this._bindWsService();
    this.startOssSync();
    // [iter15-mega-fix C-034 round-7 2026-05-10] Wire a one-shot user-gesture
    // listener that creates the shared AudioContext. Until the user clicks
    // anywhere on the screen, _playReadySound() is a silent no-op so the
    // browser does not log "AudioContext was not allowed to start" warnings.
    this._audioInitListener = function () {
      try {
        var Ctor = window.AudioContext || window.webkitAudioContext;
        if (Ctor) _this._audioCtx = new Ctor();
      } catch (_) {
        _this._audioCtx = null;
      }
    };
    // [GOAL Round 2 Impl C — P0-OSS-01 2026-05-18] Skip audio-unlock listener
    // wiring on the public customer wall. A public TV wall (`authBranchId() === 0`)
    // never receives a `pointerdown` / `keydown` gesture, so the `{ once: true }`
    // listeners would sit forever and `_playReadySound()` would silently no-op
    // (Agent 4 finding `[OSS-B-02]` — chime dead on the only surface that
    // needs it). Mirror the `subscribeEcho()` early-return idiom (line ~233:
    // `if (branchId <= 0) return`). Operator-attended surfaces (admin /
    // branch staff sessions) keep the original lazy-init pattern: the
    // operator clicks Vue routes on mount, which unlocks AudioContext and
    // allows the 3-tone chime to play on PREPARED transitions. Visual
    // notification (`.oss-ready-flash` + `.oss-new-ready` bounce) remains the
    // sole feedback channel on the public wall and was attested working by
    // Agent 4 §3 — no degradation.
    if (this.authBranchId() > 0) {
      try {
        window.addEventListener('pointerdown', this._audioInitListener, {
          once: true,
          passive: true
        });
        window.addEventListener('keydown', this._audioInitListener, {
          once: true,
          passive: true
        });
      } catch (_) {/* never block mount on listener wiring */}
    }
    // [RED-team R4 V1.0.2 2026-05-17] Acquire screen wakeLock + re-acquire on visibilitychange.
    this._acquireWakeLock();
    this._onVisibilityChange = function () {
      if (document.visibilityState === 'visible') _this._acquireWakeLock();
    };
    try {
      document.addEventListener('visibilitychange', this._onVisibilityChange);
    } catch (_) {/* noop */}
  },
  beforeUnmount: function beforeUnmount() {
    window.removeEventListener('realtime-order-update', this.list);
    this.unsubscribeEcho();
    this._unbindWsService();
    this.stopOssSync();
    if (this._flashTimer) clearTimeout(this._flashTimer);
    // [iter15-mega-fix C-034 round-7 2026-05-10] Tear down audio listeners +
    // close the context so the next mount starts clean.
    try {
      if (this._audioInitListener) {
        window.removeEventListener('pointerdown', this._audioInitListener);
        window.removeEventListener('keydown', this._audioInitListener);
      }
    } catch (_) {/* noop */}
    try {
      var _this$_audioCtx, _this$_audioCtx$close;
      (_this$_audioCtx = this._audioCtx) === null || _this$_audioCtx === void 0 || (_this$_audioCtx$close = _this$_audioCtx.close) === null || _this$_audioCtx$close === void 0 || _this$_audioCtx$close.call(_this$_audioCtx);
    } catch (_) {/* noop */}
    this._audioCtx = null;
    this._audioInitListener = null;
    // [RED-team R4 V1.0.2 2026-05-17] Release wakeLock + drop visibility listener.
    try {
      if (this._onVisibilityChange) document.removeEventListener('visibilitychange', this._onVisibilityChange);
    } catch (_) {/* noop */}
    this._onVisibilityChange = null;
    this._releaseWakeLock();
  },
  methods: {
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
    // [RED-team R4 V1.0.2 2026-05-17] Best-effort screen wakeLock for TV walls.
    _acquireWakeLock: function _acquireWakeLock() {
      var _this2 = this;
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var _window, _navigator$wakeLock;
        var flag, sentinel, _sentinel$addEventLis, _t;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.p = _context.n) {
            case 0:
              flag = (_window = window) === null || _window === void 0 || (_window = _window.foodkingConfig) === null || _window === void 0 ? void 0 : _window.ossWakeLockEnabled;
              if (!(flag === false)) {
                _context.n = 1;
                break;
              }
              return _context.a(2);
            case 1:
              if (!(!('wakeLock' in navigator) || typeof ((_navigator$wakeLock = navigator.wakeLock) === null || _navigator$wakeLock === void 0 ? void 0 : _navigator$wakeLock.request) !== 'function')) {
                _context.n = 2;
                break;
              }
              return _context.a(2);
            case 2:
              if (!_this2._wakeLockSentinel) {
                _context.n = 3;
                break;
              }
              return _context.a(2);
            case 3:
              _context.p = 3;
              _context.n = 4;
              return navigator.wakeLock.request('screen');
            case 4:
              sentinel = _context.v;
              _this2._wakeLockSentinel = sentinel;
              try {
                (_sentinel$addEventLis = sentinel.addEventListener) === null || _sentinel$addEventLis === void 0 || _sentinel$addEventLis.call(sentinel, 'release', function () {
                  _this2._wakeLockSentinel = null;
                });
              } catch (_) {/* noop */}
              _context.n = 6;
              break;
            case 5:
              _context.p = 5;
              _t = _context.v;
              _this2._wakeLockSentinel = null; /* graceful degrade */
            case 6:
              return _context.a(2);
          }
        }, _callee, null, [[3, 5]]);
      }))();
    },
    _releaseWakeLock: function _releaseWakeLock() {
      var sentinel = this._wakeLockSentinel;
      this._wakeLockSentinel = null;
      if (!sentinel) return;
      try {
        var _sentinel$release;
        (_sentinel$release = sentinel.release) === null || _sentinel$release === void 0 || _sentinel$release.call(sentinel);
      } catch (_) {/* noop */}
    },
    _bindWsService: function _bindWsService() {
      var _this3 = this;
      var ws = window._wsService;
      if (!ws) return;
      this._onWsConnected = function () {
        _this3.wsConnected = true;
        _this3.list();
      };
      this._onWsDisconnected = function () {
        _this3.wsConnected = false;
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
    startOssSync: function startOssSync() {
      var _this4 = this;
      this.ossSyncUnsubscribers.push(_services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__["default"].on('sync', function (_ref) {
        var _ref$rows = _ref.rows,
          rows = _ref$rows === void 0 ? [] : _ref$rows;
        _this4._hydrateFromRows(rows);
      }));
      this.ossSyncUnsubscribers.push(_services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__["default"].on('ws_state', function (_ref2) {
        var state = _ref2.state;
        _this4.wsConnected = String(state || '').toLowerCase() === 'connected';
      }));
      _services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__["default"].start({
        store: this.$store,
        webSocketService: window._wsService
      });
    },
    stopOssSync: function stopOssSync() {
      try {
        _services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__["default"].stop();
      } catch (_) {}
      (this.ossSyncUnsubscribers || []).forEach(function (u) {
        try {
          u && u();
        } catch (_) {}
      });
      this.ossSyncUnsubscribers = [];
    },
    subscribeEcho: function subscribeEcho() {
      var _this5 = this;
      if (!window.Echo) return;
      var branchId = this.authBranchId();
      if (branchId <= 0) return;
      // [AUDIT-52-BUG2] Always unsubscribe first to prevent duplicate listeners on re-mount
      this.unsubscribeEcho();
      try {
        this._eventSub = (0,_services_eventContract__WEBPACK_IMPORTED_MODULE_3__.onEvents)(branchId, [{
          broadcastAs: 'OrderStatusChanged',
          handler: function handler(event) {
            var data = event.payload || {};
            // [AUDIT-P1] De-duplicate _markNewReady: Echo fires it here, then list() would fire it
            // again because the order is absent from prevPreparedIds (list hasn't refreshed yet).
            // Solution: pre-register the ID in _echoMarkedReady so list() skips it.
            if (parseInt(data.new_status, 10) === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_1__["default"].PREPARED) {
              var oid = parseInt(data.order_id, 10);
              _this5._echoMarkedReady = _this5._echoMarkedReady || new Set();
              _this5._echoMarkedReady.add(oid);
              _this5._markNewReady(oid);
            }
            _this5.list();
          }
        }, {
          broadcastAs: 'OrderCreated',
          handler: function handler() {
            _this5.list();
          }
        }]);
        // [P13_LOG_HYGIENE] console.log(`[OSS] Echo subscribed to branch.${branchId}`);
      } catch (e) {
        console.warn('[OSS] Echo subscription failed:', e.message);
      }
    },
    unsubscribeEcho: function unsubscribeEcho() {
      var branchId = this.authBranchId();
      if (branchId <= 0) return;
      try {
        var _this$_eventSub;
        (_this$_eventSub = this._eventSub) === null || _this$_eventSub === void 0 || _this$_eventSub.unsubscribe();
        // [P13_LOG_HYGIENE] console.log(`[OSS] Echo unsubscribed from branch.${branchId}`);
      } catch (e) {
        console.warn('[OSS] Echo unsubscribe error:', e.message);
      }
      this._eventSub = null;
    },
    // Mark an order as newly ready: plays sound + triggers flash animation.
    // [Wave S-3 TV-optim P-OWNER 2026-05-20] Window extended 6s → 10s total
    // (4s column-flash + 6s per-card pulse) per owner directive — TV walls
    // are scanned at ≥3m so attention-grabbing needs to persist long enough
    // for a customer who looks up from a 2-3s task to catch the transition.
    // CSS `.oss-pulse-ready` runs `oss-pulse 1.6s ease infinite` while the
    // class is applied (not a fixed-duration keyframe), so the visual cue
    // tracks `newReadyIds` exactly.
    _markNewReady: function _markNewReady(orderId) {
      var _this6 = this;
      if (!orderId) return;
      this.newReadyIds = new Set([].concat(_toConsumableArray(this.newReadyIds), [parseInt(orderId)]));
      this._playReadySound();
      this.newReadyFlash = true;
      if (this._flashTimer) clearTimeout(this._flashTimer);
      this._flashTimer = setTimeout(function () {
        _this6.newReadyFlash = false;
        // Clear the highlight after a further 6s so the per-card pulse
        // persists ~10s total — readable at distance, dismissable before
        // the next batch arrives.
        setTimeout(function () {
          var ids = new Set(_this6.newReadyIds);
          ids["delete"](parseInt(orderId));
          _this6.newReadyIds = ids;
        }, 6000);
      }, 4000);
    },
    // Splash-inspired: 3-tone ascending chime when order is ready
    _playReadySound: function _playReadySound() {
      // [GOAL Round 2 Impl C — P0-OSS-01 2026-05-18] Public-wall gate.
      // `authBranchId() <= 0` indicates the unauthenticated customer wall
      // (Vuex `authStatus=false` branch in `orderStatusScreenOrder.js`).
      // That surface has no operator and no audio-unlock gesture, so the
      // chime is structurally inaudible — early-return graceful skip
      // (visual `.oss-ready-flash` continues to fire from `_markNewReady()`,
      // which is the documented `[OSS-B-02]` heal path Option C). Operator-
      // attended surfaces (`authBranchId() > 0`) keep full chime behaviour.
      if (this.authBranchId() <= 0) return;
      // [iter15-mega-fix C-034 round-7 2026-05-10] Lazy-init pattern: bail out
      // silently if the user has not yet interacted with the screen. We do
      // NOT create a fresh AudioContext per call (that was flooding the
      // console with `AudioContext was not allowed to start` warnings on the
      // customer screen which never receives user gestures). When _audioCtx
      // exists but is suspended (Safari, screen-saver wake), best-effort
      // resume() before playing.
      var ctx = this._audioCtx;
      if (!ctx) return;
      try {
        if (ctx.state === 'suspended') {
          var _ctx$resume;
          // resume() returns a Promise — fire-and-forget; if it rejects we
          // skip this chime rather than spam the console.
          (_ctx$resume = ctx.resume) === null || _ctx$resume === void 0 || _ctx$resume.call(ctx)["catch"](function () {});
          if (ctx.state !== 'running') return;
        }
        [523, 659, 784, 1047].forEach(function (freq, i) {
          var osc = ctx.createOscillator();
          var gain = ctx.createGain();
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.frequency.value = freq;
          osc.type = 'sine';
          gain.gain.setValueAtTime(0.25, ctx.currentTime + i * 0.15);
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.15 + 0.35);
          osc.start(ctx.currentTime + i * 0.15);
          osc.stop(ctx.currentTime + i * 0.15 + 0.4);
        });
      } catch (_) {/* never throw from chime */}
    },
    _hydrateFromRows: function _hydrateFromRows(rows) {
      var _this7 = this;
      var prevPreparedIds = new Set(this.preparedItems.map(function (i) {
        return i.id;
      }));
      this.preparingItems = rows.filter(function (i) {
        return i.status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_1__["default"].PREPARING;
      });
      var newPrepared = rows.filter(function (i) {
        return i.status === _enums_modules_orderStatusEnum__WEBPACK_IMPORTED_MODULE_1__["default"].PREPARED;
      });

      // Detect orders that just moved to PREPARED (not in previous list).
      // [AUDIT-P1] Skip IDs already marked via Echo to prevent double chime/flash.
      var echoMarked = this._echoMarkedReady || new Set();
      newPrepared.forEach(function (item) {
        if (!prevPreparedIds.has(item.id) && !echoMarked.has(item.id)) {
          _this7._markNewReady(item.id);
        }
      });
      // Clear the echo-marked set after list() processes it (one-shot guard)
      this._echoMarkedReady = new Set();
      this.preparedItems = newPrepared;
    },
    list: function list() {
      var _this8 = this;
      this.loading.isActive = true;
      this.$store.dispatch("orderStatusScreenOrder/lists").then(function (res) {
        _this8._hydrateFromRows(res.data.data || []);
        _this8.loading.isActive = false;
      })["catch"](function (err) {
        var _err$response;
        _this8.loading.isActive = false;
        _services_alertService__WEBPACK_IMPORTED_MODULE_2__["default"].error((err === null || err === void 0 || (_err$response = err.response) === null || _err$response === void 0 || (_err$response = _err$response.data) === null || _err$response === void 0 ? void 0 : _err$response.message) || _this8.$t('message.something_wrong'));
      });
    }
  }
});

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df"
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-label"];
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_ConnectionStatusBanner = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("ConnectionStatusBanner");
  var _component_PreparingAndReadyComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("PreparingAndReadyComponent");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [iter15-mega-fix B-003/D-002 2026-05-10] suppress-transient: the global\n    \"Reconnexion en cours…\" banner is suppressed on the OSS surface because\n    the customer-facing screen already conveys the connection state via the\n    fallback polling — no need to show a permanent yellow bar in addition.\n    session_invalid (terminal) still surfaces via this component.\n  "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_ConnectionStatusBanner, {
    "suppress-transient": ""
  }), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [Wave Q-3 P-OWNER 2026-05-20] PopularItemComponent (\"Articles à préparer\"\n    sidebar) removed from OSS layout per owner directive — the customer\n    status screen must show ONLY two columns: EN PRÉPARATION (orange/red)\n    and PRÊT (green). The PopularItemComponent.vue file is kept (still\n    referenced by OssSyncService + admin PosComponent dashboard widget),\n    but it is no longer mounted on the OSS surface.\n    Grid collapsed from md:grid-cols-4 (popular=2 + columns=2) to\n    md:grid-cols-2 (preparing=1 + ready=1) — PreparingAndReadyComponent\n    is a multi-root component whose two siblings carry col-span-1 each,\n    so they map cleanly to the 2-col grid without an inner wrapper.\n  "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "grid grid-cols-2 md:grid-cols-2 md:grid-flow-row gap-4",
    role: "main",
    "aria-label": _ctx.$t('label.oss_main_aria')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_PreparingAndReadyComponent)], 8 /* PROPS */, _hoisted_1)], 64 /* STABLE_FRAGMENT */);
}

/***/ },

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true"
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-label"];
var _hoisted_2 = {
  "class": "oss-column-header text-[40px] font-bold text-white p-4 pb-3 bg-[#B0004D] mb-2 rounded-t-[10px] text-center tracking-wide"
};
var _hoisted_3 = {
  "class": "content-wrapper p-3 overflow-hidden thin-scrolling h-full"
};
var _hoisted_4 = {
  key: 0,
  "class": "text-center text-[#A0A3BD] text-[28px] mt-12"
};
var _hoisted_5 = ["aria-label"];
var _hoisted_6 = {
  "class": "oss-column-header text-[40px] font-bold text-[#1F1F39] p-4 pb-3 bg-[#1AB759] mb-2 rounded-t-[10px] text-center tracking-wide"
};
var _hoisted_7 = {
  "class": "content-wrapper p-3 overflow-hidden thin-scrolling h-full"
};
var _hoisted_8 = {
  key: 0,
  "class": "text-center text-[#A0A3BD] text-[28px] mt-12"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_LoadingContentComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("LoadingContentComponent");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_LoadingContentComponent, {
    props: $data.loading
  }, null, 8 /* PROPS */, ["props"]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [iter15-mega-fix B-003/D-002 2026-05-10] Local ws-reconnect-banner removed —\n    duplicate of the global ConnectionStatusBanner mounted by the parent\n    OrderStatusScreenComponent. Showing two banners simultaneously\n    (\"Reconnexion en cours…\" + \"Mode secours actif\") was UX clutter flagged\n    in iter15 mega-audit Wave B/D. The global banner debounces 5s and is\n    hidden in dev via foodkingConfig.appEnv.\n  "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Colonne EN PRÉPARATION "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("\n    [Wave S-3 TV-optim P-OWNER 2026-05-20] Customer wall must be readable from ≥3m.\n    - Header bumped text-lg (~18px) → text-[40px] (40px) to surface column intent at distance.\n    - Order tokens bumped text-[40px] → text-[56px] for triple-distance comfort margin (>= 40px mandate).\n    - Brand colors #B0004D (preparing) / #1AB759 (ready) preserved per CLAUDE.md flat/organized doctrine\n      + previous Wave Q-3 attestation (red-600/green-600 hint in spec = intent = already met).\n    - Auto-scroll: items.length > 8 toggles `.oss-autoscroll` (pure-CSS keyframe loop, no JS RAF\n      to avoid fighting transition-group on enter/leave).\n  "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden",
    role: "region",
    "aria-label": _ctx.$t('label.preparing')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_2, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.preparing")), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_3, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(vue__WEBPACK_IMPORTED_MODULE_0__.TransitionGroup, {
    name: "oss-slide",
    tag: "ul",
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(['oss-order-list', $data.preparingItems.length > 8 ? 'oss-autoscroll' : '', '[&_li]:mb-8 [&_li]:text-[56px] [&_li]:font-extrabold [&_li]:leading-[1.1] w-full text-center text-[#1F1F39] mb-20'])
  }, {
    "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.preparingItems, function (item) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
          key: item.id,
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["oss-order-number", item.queue_number ? 'text-[#991B1B]' : 'text-[#1F1F39]'])
        }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.queue_number ? 'N°' + item.queue_number : item.token), 3 /* TEXT, CLASS */);
      }), 128 /* KEYED_FRAGMENT */))];
    }),
    _: 1 /* STABLE */
  }, 8 /* PROPS */, ["class"]), $data.preparingItems.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_4, "—")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])], 8 /* PROPS */, _hoisted_1), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Colonne PRÊT "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden", $data.newReadyFlash ? 'oss-ready-flash' : '']),
    role: "region",
    "aria-label": _ctx.$t('label.ready')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_6, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.ready")), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_7, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(vue__WEBPACK_IMPORTED_MODULE_0__.TransitionGroup, {
    name: "oss-pop",
    tag: "ul",
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(['oss-order-list', $data.preparedItems.length > 8 ? 'oss-autoscroll' : '', '[&_li]:mb-8 [&_li]:text-[56px] [&_li]:font-extrabold [&_li]:leading-[1.1] w-full text-center text-[#1F1F39] mb-20'])
  }, {
    "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.preparedItems, function (item) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
          key: item.id,
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["oss-order-number text-[#0E7C3A] font-extrabold", $data.newReadyIds.has(item.id) ? 'oss-new-ready oss-pulse-ready' : ''])
        }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.queue_number ? 'N°' + item.queue_number : item.token), 3 /* TEXT, CLASS */);
      }), 128 /* KEYED_FRAGMENT */))];
    }),
    _: 1 /* STABLE */
  }, 8 /* PROPS */, ["class"]), $data.preparedItems.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_8, "—")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])], 10 /* CLASS, PROPS */, _hoisted_5)], 64 /* STABLE_FRAGMENT */);
}

/***/ },

/***/ "./resources/js/services/OssSyncService.js"
/*!*************************************************!*\
  !*** ./resources/js/services/OssSyncService.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CADENCE_CEILING_MS: () => (/* binding */ CADENCE_CEILING_MS),
/* harmony export */   CADENCE_FLOOR_MS: () => (/* binding */ CADENCE_FLOOR_MS),
/* harmony export */   DEFAULTS: () => (/* binding */ DEFAULTS),
/* harmony export */   OssSyncService: () => (/* binding */ OssSyncService),
/* harmony export */   STATE: () => (/* binding */ STATE),
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _classCallCheck(a, n) { if (!(a instanceof n)) throw new TypeError("Cannot call a class as a function"); }
function _defineProperties(e, r) { for (var t = 0; t < r.length; t++) { var o = r[t]; o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, _toPropertyKey(o.key), o); } }
function _createClass(e, r, t) { return r && _defineProperties(e.prototype, r), t && _defineProperties(e, t), Object.defineProperty(e, "prototype", { writable: !1 }), e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
var STATE = Object.freeze({
  IDLE: 'idle',
  POLLING: 'polling',
  BACKOFF: 'backoff',
  STOPPED: 'stopped'
});
var DEFAULTS = Object.freeze({
  intervalMsWhenConnected: 60000,
  // [test-e2e round-2 cluster-6 D-002 2026-05-10] Tightened from 5000 → 2000
  // so that the SYNC-2 8s budget (POS pay → OSS visible) is met by the
  // polling fallback alone when the broadcast queue is idle in dev
  // (BROADCAST_DRIVER=pusher + WS port 6001 down + no queue worker).
  // Production still uses Echo/Pusher live so this fallback is essentially
  // unused there; tightening it costs nothing in prod and saves ~3s in dev.
  intervalMsWhenDisconnected: 2000,
  backoffStartMs: 5000,
  backoffCapMs: 30000,
  jitterMaxMs: 500,
  // [test-e2e round-2 cluster-6 D-002 2026-05-10] Visibility burst-poll
  // throttle. When the tab regains visibility, OssSyncService fires an
  // immediate poll unless one fired within this window — protects against
  // a stream of focus/blur events spamming the backend.
  visibilityBurstMinIntervalMs: 1000,
  // Sustained-disconnect dev warning threshold (silent in prod).
  devWarnAfterDisconnectMs: 10000
});

// [Wave 3c KDS-ADV3C-08 P1 2026-05-18] Cadence upper cap symmetric with
// Wave 2c KDS heal (9ff26e12b) and sibling PosSyncService heal. Without
// it, owner-misconfig like FK_CATALOG_OSS_FALLBACK_CONNECTED_INTERVAL_MS=
// 999999999 would freeze the customer wall, blowing the SYNC-2 8s budget
// (POS pay → OSS visible). 60s = 1 poll/min minimum.
var CADENCE_CEILING_MS = 60000;
var CADENCE_FLOOR_MS = 250;
var OssSyncService = /*#__PURE__*/function () {
  function OssSyncService() {
    _classCallCheck(this, OssSyncService);
    this._state = STATE.IDLE;
    this._timer = null;
    this._abortController = null;
    this._wsUnsubscribe = null;
    this._wsState = 'unknown';
    this._listeners = new Map();
    this._started = false;
    this._store = null;
    this._opts = _objectSpread({}, DEFAULTS);
    this._currentBackoffMs = DEFAULTS.backoffStartMs;
    this._lastScheduledDelayMs = null;
    // [test-e2e round-2 cluster-6 D-002 2026-05-10] Burst-poll plumbing.
    this._visibilityHandler = null;
    this._lastBurstPollAt = 0;
    this._disconnectedSinceMs = null;
    this._devWarnedDisconnected = false;
  }
  return _createClass(OssSyncService, [{
    key: "start",
    value: function start() {
      var ctx = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {};
      this._started = false;
      this._cleanup({
        unsubscribe: true
      });
      var runtimeConfig = this._runtimeConfig();
      if (!runtimeConfig.enabled) {
        this._state = STATE.IDLE;
        return;
      }
      if (!ctx.store || typeof ctx.store.dispatch !== 'function') {
        this._state = STATE.IDLE;
        return;
      }
      this._opts = _objectSpread(_objectSpread({}, DEFAULTS), {}, {
        intervalMsWhenConnected: runtimeConfig.intervalMsWhenConnected,
        intervalMsWhenDisconnected: runtimeConfig.intervalMsWhenDisconnected
      }, ctx.options || {});
      this._currentBackoffMs = this._opts.backoffStartMs;
      this._store = ctx.store;
      this._webSocketService = ctx.webSocketService || null;
      this._wsState = 'unknown';
      this._started = true;
      this._state = STATE.POLLING;
      this._bindWebSocketState();
      this._bindVisibility();
      this._scheduleNext(this._jitter());
    }
  }, {
    key: "stop",
    value: function stop() {
      this._started = false;
      this._cleanup({
        unsubscribe: true
      });
      this._state = STATE.STOPPED;
    }
  }, {
    key: "on",
    value: function on(eventName, handler) {
      if (!this._listeners.has(eventName)) {
        this._listeners.set(eventName, new Set());
      }
      var set = this._listeners.get(eventName);
      set.add(handler);
      return function () {
        return set["delete"](handler);
      };
    }
  }, {
    key: "state",
    value: function state() {
      return this._state;
    }
  }, {
    key: "_runtimeConfig",
    value: function _runtimeConfig() {
      var _window$foodkingConfi;
      var cfg = typeof window !== 'undefined' ? ((_window$foodkingConfi = window.foodkingConfig) === null || _window$foodkingConfi === void 0 ? void 0 : _window$foodkingConfi.ossFallbackPolling) || {} : {};
      return {
        enabled: cfg.enabled !== false && cfg.enabled !== 0 && cfg.enabled !== '0',
        // [Wave 3c KDS-ADV3C-08 P1 2026-05-18] Was `_positiveInt` —
        // accepted any positive int incl. 999999999 (freeze 11.5 days).
        // Now clamped to [250ms, 60_000ms] symmetric with KDS+POS.
        intervalMsWhenConnected: this._clampCadence(cfg.intervalMsWhenConnected, DEFAULTS.intervalMsWhenConnected),
        intervalMsWhenDisconnected: this._clampCadence(cfg.intervalMsWhenDisconnected, DEFAULTS.intervalMsWhenDisconnected)
      };
    }
  }, {
    key: "_bindWebSocketState",
    value: function _bindWebSocketState() {
      var _this = this;
      var ws = this._webSocketService;
      if (!ws || typeof ws.on !== 'function') {
        this._wsState = 'disconnected';
        // [test-e2e round-2 cluster-6 D-002 2026-05-10] Seed disconnect
        // timestamp so the dev-only warn triggers when WS is never wired
        // (most common case in local dev: BROADCAST_DRIVER=pusher, port
        // 6001 down, _wsService never reaches 'connected').
        this._disconnectedSinceMs = Date.now();
        return;
      }
      var unsubscribers = [];
      var listen = function listen(eventName, callback) {
        var unsubscribe = ws.on(eventName, callback);
        if (typeof unsubscribe === 'function') {
          unsubscribers.push(unsubscribe);
          return;
        }
        if (typeof ws.off === 'function') {
          unsubscribers.push(function () {
            return ws.off(eventName, callback);
          });
        }
      };
      var handleState = function handleState(next) {
        var previousWsState = _this._wsState;
        _this._wsState = next || 'unknown';
        _this._emit('ws_state', {
          state: _this._wsState
        });
        _this._state = STATE.POLLING;
        _this._currentBackoffMs = _this._opts.backoffStartMs;
        // [test-e2e round-2 cluster-6 D-002 2026-05-10] Track sustained
        // disconnect for the dev-only console warn (see _maybeWarnDisconnect).
        var isConnectedNow = String(_this._wsState).toLowerCase() === 'connected';
        if (isConnectedNow) {
          _this._disconnectedSinceMs = null;
          _this._devWarnedDisconnected = false;
        } else if (!_this._disconnectedSinceMs) {
          _this._disconnectedSinceMs = Date.now();
        }
        // If we just transitioned from disconnected → connected, fire an
        // immediate poll so the OSS catches up with whatever piled up
        // during the WS outage instead of waiting for the next tick.
        if (isConnectedNow && previousWsState && String(previousWsState).toLowerCase() !== 'connected') {
          _this._burstPoll('ws_reconnected');
          return;
        }
        _this._scheduleNormalCadence();
      };
      listen('connected', function () {
        return handleState('connected');
      });
      listen('disconnected', function () {
        return handleState('disconnected');
      });
      listen('reconnect_storm', function () {
        return handleState('disconnected');
      });
      listen('state_change', function () {
        var payload = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {};
        var next = payload.current || payload.to || payload.state || payload.next || null;
        if (next) {
          handleState(next);
        }
      });
      this._wsUnsubscribe = function () {
        unsubscribers.splice(0).forEach(function (unsubscribe) {
          try {
            unsubscribe();
          } catch (_) {/* ignore cleanup errors */}
        });
      };
    }

    // [test-e2e round-2 cluster-6 D-002 2026-05-10] Burst-poll on tab visibility.
    // Spec captures showed POS pay → OSS lag of 14.4s when the OSS tab was
    // backgrounded between actions: setTimeout intervals throttle to ~1s when a
    // tab is hidden, but visibilitychange fires immediately on focus regain.
    // Triggering an instant fetch on `visible` collapses worst-case lag to one
    // round-trip + render. Throttled by visibilityBurstMinIntervalMs.
  }, {
    key: "_bindVisibility",
    value: function _bindVisibility() {
      var _this2 = this;
      if (typeof document === 'undefined' || typeof document.addEventListener !== 'function') {
        return;
      }
      this._visibilityHandler = function () {
        if (!_this2._started) return;
        if (document.visibilityState !== 'visible') return;
        _this2._burstPoll('visibility');
      };
      try {
        document.addEventListener('visibilitychange', this._visibilityHandler);
      } catch (_) {/* never block start on listener wiring */}
    }
  }, {
    key: "_unbindVisibility",
    value: function _unbindVisibility() {
      if (this._visibilityHandler && typeof document !== 'undefined') {
        try {
          document.removeEventListener('visibilitychange', this._visibilityHandler);
        } catch (_) {/* noop */}
      }
      this._visibilityHandler = null;
    }
  }, {
    key: "_burstPoll",
    value: function _burstPoll() {
      var reason = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : 'manual';
      if (!this._started) return;
      var now = Date.now();
      var minGap = this._opts.visibilityBurstMinIntervalMs || 0;
      if (this._lastBurstPollAt && now - this._lastBurstPollAt < minGap) {
        return;
      }
      this._lastBurstPollAt = now;
      // Maybe-warn here too: the user just brought the tab forward and the
      // WS has been down for a while → surface a dev-only diagnostic.
      this._maybeWarnDisconnect();
      // Cancel the scheduled timer and trigger an immediate fetch. _poll()
      // re-schedules normal cadence on completion.
      this._clearTimer();
      this._poll()["catch"](function () {});
    }
  }, {
    key: "_maybeWarnDisconnect",
    value: function _maybeWarnDisconnect() {
      var _window$foodkingConfi2;
      if (this._devWarnedDisconnected) return;
      if (!this._disconnectedSinceMs) return;
      var threshold = this._opts.devWarnAfterDisconnectMs || 0;
      if (threshold <= 0) return;
      var elapsed = Date.now() - this._disconnectedSinceMs;
      if (elapsed < threshold) return;
      var isDev = typeof window !== 'undefined' && ((_window$foodkingConfi2 = window.foodkingConfig) === null || _window$foodkingConfi2 === void 0 ? void 0 : _window$foodkingConfi2.appEnv) && window.foodkingConfig.appEnv !== 'production';
      if (!isDev) return;
      this._devWarnedDisconnected = true;
      try {
        // Single warn per disconnect window so the console isn't spammed.
        // eslint-disable-next-line no-console
        console.warn('[OSS] Realtime broadcast unavailable for ' + Math.round(elapsed / 1000) + 's — polling fallback active. SYNC latency may exceed live cadence.');
      } catch (_) {/* noop */}
    }
  }, {
    key: "_poll",
    value: function () {
      var _poll2 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var controller, _result$data, result, status, rows, _status, _t;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.p = _context.n) {
            case 0:
              if (!(!this._started || !this._store)) {
                _context.n = 1;
                break;
              }
              return _context.a(2);
            case 1:
              this._abortInFlight();
              controller = new AbortController();
              this._abortController = controller;
              _context.p = 2;
              _context.n = 3;
              return this._store.dispatch('orderStatusScreenOrder/lists');
            case 3:
              result = _context.v;
              if (!(controller.signal.aborted || !this._started)) {
                _context.n = 4;
                break;
              }
              return _context.a(2);
            case 4:
              status = this._statusFromResult(result);
              if (!(status >= 500 && status <= 599)) {
                _context.n = 5;
                break;
              }
              this._handle5xx();
              return _context.a(2);
            case 5:
              rows = (result === null || result === void 0 || (_result$data = result.data) === null || _result$data === void 0 ? void 0 : _result$data.data) || [];
              this._state = STATE.POLLING;
              this._currentBackoffMs = this._opts.backoffStartMs;
              this._emit('sync', {
                rows: rows,
                status: status
              });
              this._scheduleNormalCadence();
              _context.n = 9;
              break;
            case 6:
              _context.p = 6;
              _t = _context.v;
              if (!(controller.signal.aborted || (_t === null || _t === void 0 ? void 0 : _t.name) === 'AbortError' || (_t === null || _t === void 0 ? void 0 : _t.code) === 'ERR_CANCELED')) {
                _context.n = 7;
                break;
              }
              return _context.a(2);
            case 7:
              _status = this._statusFromError(_t);
              if (!(_status >= 500 && _status <= 599)) {
                _context.n = 8;
                break;
              }
              this._handle5xx();
              return _context.a(2);
            case 8:
              this._state = STATE.POLLING;
              this._emit('error', {
                status: _status,
                error: _t
              });
              this._scheduleNormalCadence();
            case 9:
              _context.p = 9;
              if (this._abortController === controller) {
                this._abortController = null;
              }
              return _context.f(9);
            case 10:
              return _context.a(2);
          }
        }, _callee, this, [[2, 6, 9, 10]]);
      }));
      function _poll() {
        return _poll2.apply(this, arguments);
      }
      return _poll;
    }()
  }, {
    key: "_handle5xx",
    value: function _handle5xx() {
      if (!this._started) {
        return;
      }
      this._state = STATE.BACKOFF;
      var delay = this._currentBackoffMs;
      this._currentBackoffMs = Math.min(this._currentBackoffMs * 2, this._opts.backoffCapMs);
      this._emit('error', {
        status: 500,
        backoffMs: delay
      });
      this._scheduleNext(delay);
    }
  }, {
    key: "_scheduleNormalCadence",
    value: function _scheduleNormalCadence() {
      var state = this._readWsState();
      var isConnected = String(state || '').toLowerCase() === 'connected';
      var base = isConnected ? this._opts.intervalMsWhenConnected : this._opts.intervalMsWhenDisconnected;

      // [test-e2e round-2 cluster-6 D-002 2026-05-10] Surface sustained
      // disconnect once we've been polling in fallback long enough — this
      // hooks into the normal cadence path so even backgrounded tabs warn.
      this._maybeWarnDisconnect();
      this._scheduleNext(base + this._jitter());
    }
  }, {
    key: "_scheduleNext",
    value: function _scheduleNext(delayMs) {
      var _this3 = this;
      this._clearTimer();
      if (!this._started) {
        return;
      }
      var delay = Math.max(0, this._positiveInt(delayMs, 0));
      this._lastScheduledDelayMs = delay;
      this._timer = setTimeout(function () {
        _this3._timer = null;
        _this3._poll()["catch"](function () {});
      }, delay);
    }
  }, {
    key: "_cleanup",
    value: function _cleanup() {
      var _ref = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {},
        _ref$unsubscribe = _ref.unsubscribe,
        unsubscribe = _ref$unsubscribe === void 0 ? false : _ref$unsubscribe;
      this._clearTimer();
      this._abortInFlight();
      this._lastScheduledDelayMs = null;
      this._store = null;
      if (unsubscribe && this._wsUnsubscribe) {
        this._wsUnsubscribe();
        this._wsUnsubscribe = null;
      }
      // [test-e2e round-2 cluster-6 D-002 2026-05-10] Always tear down the
      // visibility listener — leaking it would burst-poll a stopped service.
      this._unbindVisibility();
      this._disconnectedSinceMs = null;
      this._devWarnedDisconnected = false;
      this._lastBurstPollAt = 0;
    }
  }, {
    key: "_clearTimer",
    value: function _clearTimer() {
      if (this._timer) {
        clearTimeout(this._timer);
        this._timer = null;
      }
    }
  }, {
    key: "_abortInFlight",
    value: function _abortInFlight() {
      if (this._abortController) {
        this._abortController.abort();
        this._abortController = null;
      }
    }
  }, {
    key: "_readWsState",
    value: function _readWsState() {
      if (this._wsState && this._wsState !== 'unknown') {
        return this._wsState;
      }
      var ws = this._webSocketService;
      if (ws && typeof ws.getState === 'function') {
        return ws.getState();
      }
      if (ws && typeof ws.state !== 'undefined') {
        return ws.state;
      }
      if (ws && typeof ws.isConnected === 'function') {
        return ws.isConnected() ? 'connected' : 'disconnected';
      }
      return 'disconnected';
    }
  }, {
    key: "_statusFromResult",
    value: function _statusFromResult(result) {
      var _result$response;
      return Number((result === null || result === void 0 ? void 0 : result.status) || (result === null || result === void 0 || (_result$response = result.response) === null || _result$response === void 0 ? void 0 : _result$response.status) || 200);
    }
  }, {
    key: "_statusFromError",
    value: function _statusFromError(error) {
      var _error$response;
      return Number((error === null || error === void 0 || (_error$response = error.response) === null || _error$response === void 0 ? void 0 : _error$response.status) || (error === null || error === void 0 ? void 0 : error.status) || 0);
    }
  }, {
    key: "_positiveInt",
    value: function _positiveInt(value, fallback) {
      var parsed = parseInt(value, 10);
      return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    }

    /**
     * [Wave 3c KDS-ADV3C-08 P1 2026-05-18] Clamp a cadence value to
     * [CADENCE_FLOOR_MS, CADENCE_CEILING_MS]. Non-numeric → fallback.
     * Protects against silent-blind misconfig (e.g. CDN-pushed config
     * with intervalMsWhenConnected=999999999 freezing the customer wall).
     */
  }, {
    key: "_clampCadence",
    value: function _clampCadence(value, fallback) {
      var parsed = parseInt(value, 10);
      var candidate = Number.isFinite(parsed) ? parsed : fallback;
      var floored = candidate >= CADENCE_FLOOR_MS ? candidate : CADENCE_FLOOR_MS;
      return floored <= CADENCE_CEILING_MS ? floored : CADENCE_CEILING_MS;
    }
  }, {
    key: "_jitter",
    value: function _jitter() {
      return Math.floor(Math.random() * this._opts.jitterMaxMs);
    }
  }, {
    key: "_emit",
    value: function _emit(eventName, payload) {
      var listeners = this._listeners.get(eventName);
      if (!listeners) {
        return;
      }
      listeners.forEach(function (handler) {
        return handler(payload);
      });
    }
  }], [{
    key: "STATES",
    get: function get() {
      return STATE;
    }
  }, {
    key: "DEFAULTS",
    get: function get() {
      return DEFAULTS;
    }
  }]);
}();
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (new OssSyncService());


/***/ },

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css"
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! !../../../../../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
/* harmony import */ var _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_style_index_0_id_3aa5d0ca_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! !!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css */ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css");

            

var options = {};

options.insert = "head";
options.singleton = false;

var update = _node_modules_style_loader_dist_runtime_injectStylesIntoStyleTag_js__WEBPACK_IMPORTED_MODULE_0___default()(_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_style_index_0_id_3aa5d0ca_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"], options);



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_style_index_0_id_3aa5d0ca_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_1__["default"].locals || {});

/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue"
/*!****************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue ***!
  \****************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _OrderStatusScreenComponent_vue_vue_type_template_id_3b75c5df__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df */ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df");
/* harmony import */ var _OrderStatusScreenComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./OrderStatusScreenComponent.vue?vue&type=script&lang=js */ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_OrderStatusScreenComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_OrderStatusScreenComponent_vue_vue_type_template_id_3b75c5df__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue"
/*!****************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue ***!
  \****************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _PreparingAndReadyComponent_vue_vue_type_template_id_3aa5d0ca_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true */ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true");
/* harmony import */ var _PreparingAndReadyComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./PreparingAndReadyComponent.vue?vue&type=script&lang=js */ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js");
/* harmony import */ var _PreparingAndReadyComponent_vue_vue_type_style_index_0_id_3aa5d0ca_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css */ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;


const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_3__["default"])(_PreparingAndReadyComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_PreparingAndReadyComponent_vue_vue_type_template_id_3aa5d0ca_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render],['__scopeId',"data-v-3aa5d0ca"],['__file',"resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js"
/*!****************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js ***!
  \****************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./OrderStatusScreenComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js"
/*!****************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js ***!
  \****************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js");
 

/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df"
/*!**********************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df ***!
  \**********************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_template_id_3b75c5df__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_template_id_3b75c5df__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df");


/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true"
/*!**********************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true ***!
  \**********************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_template_id_3aa5d0ca_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_template_id_3aa5d0ca_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true");


/***/ },

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css"
/*!************************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css ***!
  \************************************************************************************************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_style_index_0_id_3aa5d0ca_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css");


/***/ }

}]);