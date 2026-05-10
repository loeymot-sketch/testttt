"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["admin-oss"],{

/***/ "./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css":
/*!*********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css ***!
  \*********************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../../../../../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
/* harmony import */ var _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0__);
// Imports

var ___CSS_LOADER_EXPORT___ = _node_modules_css_loader_dist_runtime_api_js__WEBPACK_IMPORTED_MODULE_0___default()(function(i){return i[1]});
// Module
___CSS_LOADER_EXPORT___.push([module.id, "\n.ws-reconnect-banner[data-v-3aa5d0ca] {\n  background: #fef3c7;\n  color: #92400e;\n  text-align: center;\n  padding: 6px 12px;\n  font-size: 0.85rem;\n  font-weight: 600;\n}\n/* Slide-in for preparing column */\n.oss-slide-enter-active[data-v-3aa5d0ca] { transition: all 0.4s ease;\n}\n.oss-slide-leave-active[data-v-3aa5d0ca] { transition: all 0.3s ease;\n}\n.oss-slide-enter-from[data-v-3aa5d0ca]   { opacity: 0; transform: translateX(-20px);\n}\n.oss-slide-leave-to[data-v-3aa5d0ca]     { opacity: 0; transform: translateX(20px);\n}\n\n/* Pop-in for ready column */\n.oss-pop-enter-active[data-v-3aa5d0ca] { transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);\n}\n.oss-pop-leave-active[data-v-3aa5d0ca] { transition: all 0.3s ease;\n}\n.oss-pop-enter-from[data-v-3aa5d0ca]   { opacity: 0; transform: scale(0.6);\n}\n.oss-pop-leave-to[data-v-3aa5d0ca]     { opacity: 0; transform: scale(0.8);\n}\n\n/* Highlight for newly-ready orders */\n.oss-new-ready[data-v-3aa5d0ca] {\n  animation: oss-bounce-3aa5d0ca 0.6s ease 2;\n}\n@keyframes oss-bounce-3aa5d0ca {\n0%, 100% { transform: scale(1);\n}\n50%       { transform: scale(1.12);\n}\n}\n\n/* Flash the entire ready column green when a new order is ready */\n.oss-ready-flash[data-v-3aa5d0ca] {\n  animation: oss-flash-3aa5d0ca 0.8s ease 2;\n}\n@keyframes oss-flash-3aa5d0ca {\n0%, 100% { background-color: transparent;\n}\n50%       { background-color: rgba(26, 183, 89, 0.15);\n}\n}\n", ""]);
// Exports
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (___CSS_LOADER_EXPORT___);


/***/ }),

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js":
/*!*********************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js ***!
  \*********************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _PopularItemComponent__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./PopularItemComponent */ "./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue");
/* harmony import */ var _PreparingAndReadyComponent__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./PreparingAndReadyComponent */ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue");
/* harmony import */ var _common_ConnectionStatusBanner_vue__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../common/ConnectionStatusBanner.vue */ "./resources/js/components/common/ConnectionStatusBanner.vue");



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: "OrderStatusScreenComponent",
  components: {
    ConnectionStatusBanner: _common_ConnectionStatusBanner_vue__WEBPACK_IMPORTED_MODULE_2__["default"],
    PopularItemComponent: _PopularItemComponent__WEBPACK_IMPORTED_MODULE_0__["default"],
    PreparingAndReadyComponent: _PreparingAndReadyComponent__WEBPACK_IMPORTED_MODULE_1__["default"]
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

/***/ }),

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=script&lang=js":
/*!***************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=script&lang=js ***!
  \***************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _components_LoadingComponent__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../components/LoadingComponent */ "./resources/js/components/admin/components/LoadingComponent.vue");

/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  name: "PopularItemComponent",
  components: {
    LoadingComponent: _components_LoadingComponent__WEBPACK_IMPORTED_MODULE_0__["default"]
  },
  data: function data() {
    return {
      loading: {
        isActive: false
      }
    };
  },
  computed: {
    items: function items() {
      return this.$store.getters["orderStatusScreenOrder/mostPopularItems"];
    }
  },
  mounted: function mounted() {
    this.popularItems();
  },
  methods: {
    popularItems: function popularItems() {
      var _this = this;
      this.loading.isActive = true;
      this.$store.dispatch("orderStatusScreenOrder/mostPopularItems").then(function (res) {
        _this.loading.isActive = false;
      })["catch"](function (err) {
        _this.loading.isActive = false;
      });
    }
  }
});

/***/ }),

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js":
/*!*********************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js ***!
  \*********************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

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
      _flashTimer: null
    };
  },
  computed: {},
  mounted: function mounted() {
    this.list();
    window.addEventListener('realtime-order-update', this.list);
    this.subscribeEcho();
    this._bindWsService();
    this.startOssSync();
  },
  beforeUnmount: function beforeUnmount() {
    window.removeEventListener('realtime-order-update', this.list);
    this.unsubscribeEcho();
    this._unbindWsService();
    this.stopOssSync();
    if (this._flashTimer) clearTimeout(this._flashTimer);
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
    _bindWsService: function _bindWsService() {
      var _this = this;
      var ws = window._wsService;
      if (!ws) return;
      this._onWsConnected = function () {
        _this.wsConnected = true;
        _this.list();
      };
      this._onWsDisconnected = function () {
        _this.wsConnected = false;
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
      var _this2 = this;
      this.ossSyncUnsubscribers.push(_services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__["default"].on('sync', function (_ref) {
        var _ref$rows = _ref.rows,
          rows = _ref$rows === void 0 ? [] : _ref$rows;
        _this2._hydrateFromRows(rows);
      }));
      this.ossSyncUnsubscribers.push(_services_OssSyncService__WEBPACK_IMPORTED_MODULE_4__["default"].on('ws_state', function (_ref2) {
        var state = _ref2.state;
        _this2.wsConnected = String(state || '').toLowerCase() === 'connected';
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
      var _this3 = this;
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
              _this3._echoMarkedReady = _this3._echoMarkedReady || new Set();
              _this3._echoMarkedReady.add(oid);
              _this3._markNewReady(oid);
            }
            _this3.list();
          }
        }, {
          broadcastAs: 'OrderCreated',
          handler: function handler() {
            _this3.list();
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
    // Mark an order as newly ready: plays sound + triggers flash animation for 4s
    _markNewReady: function _markNewReady(orderId) {
      var _this4 = this;
      if (!orderId) return;
      this.newReadyIds = new Set([].concat(_toConsumableArray(this.newReadyIds), [parseInt(orderId)]));
      this._playReadySound();
      this.newReadyFlash = true;
      if (this._flashTimer) clearTimeout(this._flashTimer);
      this._flashTimer = setTimeout(function () {
        _this4.newReadyFlash = false;
        // Clear the highlight after 6s so it doesn't persist forever
        setTimeout(function () {
          var ids = new Set(_this4.newReadyIds);
          ids["delete"](parseInt(orderId));
          _this4.newReadyIds = ids;
        }, 2000);
      }, 4000);
    },
    // Splash-inspired: 3-tone ascending chime when order is ready
    _playReadySound: function _playReadySound() {
      try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
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
      } catch (_) {}
    },
    _hydrateFromRows: function _hydrateFromRows(rows) {
      var _this5 = this;
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
          _this5._markNewReady(item.id);
        }
      });
      // Clear the echo-marked set after list() processes it (one-shot guard)
      this._echoMarkedReady = new Set();
      this.preparedItems = newPrepared;
    },
    list: function list() {
      var _this6 = this;
      this.loading.isActive = true;
      this.$store.dispatch("orderStatusScreenOrder/lists").then(function (res) {
        _this6._hydrateFromRows(res.data.data || []);
        _this6.loading.isActive = false;
      })["catch"](function (err) {
        var _err$response;
        _this6.loading.isActive = false;
        _services_alertService__WEBPACK_IMPORTED_MODULE_2__["default"].error((err === null || err === void 0 || (_err$response = err.response) === null || _err$response === void 0 || (_err$response = _err$response.data) === null || _err$response === void 0 ? void 0 : _err$response.message) || _this6.$t('message.something_wrong'));
      });
    }
  }
});

/***/ }),

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df":
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-label"];
var _hoisted_2 = {
  "class": "col-span-2 grid grid-cols-2 gap-4 md:mt-0 mt-[-20px]"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_ConnectionStatusBanner = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("ConnectionStatusBanner");
  var _component_PopularItemComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("PopularItemComponent");
  var _component_PreparingAndReadyComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("PreparingAndReadyComponent");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_ConnectionStatusBanner), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "grid grid-cols-2 md:grid-cols-4 md:grid-flow-row gap-4",
    role: "main",
    "aria-label": _ctx.$t('label.oss_main_aria')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_PopularItemComponent), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_2, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_PreparingAndReadyComponent)])], 8 /* PROPS */, _hoisted_1)], 64 /* STABLE_FRAGMENT */);
}

/***/ }),

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=template&id=557dcb82":
/*!*******************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=template&id=557dcb82 ***!
  \*******************************************************************************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = ["aria-label"];
var _hoisted_2 = {
  "class": "customer-screen db-card rounded-[10px] h-screen md:h-[calc(100vh-117px)] overflow-hidden pb-20"
};
var _hoisted_3 = {
  "class": "p-3 pb-2 mb-6"
};
var _hoisted_4 = {
  "class": "text-[22px] font-semibold text-[#0057B7]"
};
var _hoisted_5 = {
  "class": "p-3 grid grid-cols-2 lg:grid-cols-3 gap-11 overflow-auto thin-scrolling h-full"
};
var _hoisted_6 = {
  "class": "max-w-[148px] w-full h-[102px] rounded-full mb-4"
};
var _hoisted_7 = ["src"];
var _hoisted_8 = {
  "class": "text-base font-medium text-[#6E7191]"
};
var _hoisted_9 = {
  "class": "text-lg font-semibold text-[#1F1F39]"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_LoadingComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("LoadingComponent");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_LoadingComponent, {
    props: $data.loading
  }, null, 8 /* PROPS */, ["props"]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "col-span-2 md:block hidden",
    role: "region",
    "aria-label": _ctx.$t('label.oss_popular_region_aria')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_2, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_3, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_4, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.popular_menu_items")), 1 /* TEXT */)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_5, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.items, function (item, index) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      "class": "flex flex-col items-center",
      key: item.id || index
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_6, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("img", {
      "class": "w-full h-full rounded-full",
      src: item.thumb,
      alt: ""
    }, null, 8 /* PROPS */, _hoisted_7)]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h6", _hoisted_8, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.name), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("p", _hoisted_9, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.currency_price), 1 /* TEXT */)]);
  }), 128 /* KEYED_FRAGMENT */))])])], 8 /* PROPS */, _hoisted_1)], 64 /* STABLE_FRAGMENT */);
}

/***/ }),

/***/ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true":
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = {
  key: 0,
  "class": "ws-reconnect-banner"
};
var _hoisted_2 = ["aria-label"];
var _hoisted_3 = {
  "class": "text-lg font-semibold text-white p-3 pb-2 bg-[#B0004D] mb-2 rounded-t-[10px] text-center"
};
var _hoisted_4 = {
  "class": "content-wrapper p-3 overflow-auto thin-scrolling h-full"
};
var _hoisted_5 = {
  key: 0,
  "class": "text-center text-[#A0A3BD] text-base mt-8"
};
var _hoisted_6 = ["aria-label"];
var _hoisted_7 = {
  "class": "text-lg font-semibold text-[#1F1F39] p-3 pb-2 bg-[#1AB759] mb-2 rounded-t-[10px] text-center"
};
var _hoisted_8 = {
  "class": "content-wrapper p-3 overflow-auto thin-scrolling h-full"
};
var _hoisted_9 = {
  key: 0,
  "class": "text-center text-[#A0A3BD] text-base mt-8"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_LoadingContentComponent = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("LoadingContentComponent");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_LoadingContentComponent, {
    props: $data.loading
  }, null, 8 /* PROPS */, ["props"]), !$data.wsConnected ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_1, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t('label.oss_fallback_banner')), 1 /* TEXT */)) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Colonne EN PRÉPARATION "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": "col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden",
    role: "region",
    "aria-label": _ctx.$t('label.preparing')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_3, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.preparing")), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_4, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(vue__WEBPACK_IMPORTED_MODULE_0__.TransitionGroup, {
    name: "oss-slide",
    tag: "ul",
    "class": "[&_li]:mb-6 [&_li]:text-[40px] [&_li]:font-semibold [&_li]:leading-10 w-full text-center text-[#1F1F39] mb-20"
  }, {
    "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.preparingItems, function (item) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
          key: item.id,
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(item.queue_number ? 'text-[#991B1B]' : 'text-[#1F1F39]')
        }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.queue_number ? 'N°' + item.queue_number : item.token), 3 /* TEXT, CLASS */);
      }), 128 /* KEYED_FRAGMENT */))];
    }),
    _: 1 /* STABLE */
  }), $data.preparingItems.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_5, "—")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])], 8 /* PROPS */, _hoisted_2), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Colonne PRÊT "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden", $data.newReadyFlash ? 'oss-ready-flash' : '']),
    role: "region",
    "aria-label": _ctx.$t('label.ready')
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h3", _hoisted_7, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(_ctx.$t("label.ready")), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_8, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(vue__WEBPACK_IMPORTED_MODULE_0__.TransitionGroup, {
    name: "oss-pop",
    tag: "ul",
    "class": "[&_li]:mb-6 [&_li]:text-[40px] [&_li]:font-semibold [&_li]:leading-10 w-full text-center text-[#1F1F39] mb-20"
  }, {
    "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.preparedItems, function (item) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("li", {
          key: item.id,
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["text-[#2AC769] font-extrabold", $data.newReadyIds.has(item.id) ? 'oss-new-ready' : ''])
        }, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.queue_number ? 'N°' + item.queue_number : item.token), 3 /* TEXT, CLASS */);
      }), 128 /* KEYED_FRAGMENT */))];
    }),
    _: 1 /* STABLE */
  }), $data.preparedItems.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_9, "—")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])], 10 /* CLASS, PROPS */, _hoisted_6)], 64 /* STABLE_FRAGMENT */);
}

/***/ }),

/***/ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css":
/*!*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

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

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue":
/*!****************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue ***!
  \****************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

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

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js":
/*!****************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js ***!
  \****************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./OrderStatusScreenComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df":
/*!**********************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df ***!
  \**********************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_template_id_3b75c5df__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_OrderStatusScreenComponent_vue_vue_type_template_id_3b75c5df__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue?vue&type=template&id=3b75c5df");


/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue":
/*!**********************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue ***!
  \**********************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _PopularItemComponent_vue_vue_type_template_id_557dcb82__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./PopularItemComponent.vue?vue&type=template&id=557dcb82 */ "./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=template&id=557dcb82");
/* harmony import */ var _PopularItemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./PopularItemComponent.vue?vue&type=script&lang=js */ "./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_PopularItemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_PopularItemComponent_vue_vue_type_template_id_557dcb82__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=script&lang=js":
/*!**********************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=script&lang=js ***!
  \**********************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PopularItemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PopularItemComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PopularItemComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=template&id=557dcb82":
/*!****************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=template&id=557dcb82 ***!
  \****************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PopularItemComponent_vue_vue_type_template_id_557dcb82__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PopularItemComponent_vue_vue_type_template_id_557dcb82__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PopularItemComponent.vue?vue&type=template&id=557dcb82 */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue?vue&type=template&id=557dcb82");


/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue":
/*!****************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue ***!
  \****************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

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

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js":
/*!****************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js ***!
  \****************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=script&lang=js */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css":
/*!************************************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css ***!
  \************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _node_modules_style_loader_dist_cjs_js_node_modules_css_loader_dist_cjs_js_clonedRuleSet_9_use_1_node_modules_vue_loader_dist_stylePostLoader_js_node_modules_postcss_loader_dist_cjs_js_clonedRuleSet_9_use_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_style_index_0_id_3aa5d0ca_scoped_true_lang_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/style-loader/dist/cjs.js!../../../../../node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!../../../../../node_modules/vue-loader/dist/stylePostLoader.js!../../../../../node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css */ "./node_modules/style-loader/dist/cjs.js!./node_modules/css-loader/dist/cjs.js??clonedRuleSet-9.use[1]!./node_modules/vue-loader/dist/stylePostLoader.js!./node_modules/postcss-loader/dist/cjs.js??clonedRuleSet-9.use[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=style&index=0&id=3aa5d0ca&scoped=true&lang=css");


/***/ }),

/***/ "./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true":
/*!**********************************************************************************************************************************!*\
  !*** ./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true ***!
  \**********************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_template_id_3aa5d0ca_scoped_true__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_laravel_mix_node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreparingAndReadyComponent_vue_vue_type_template_id_3aa5d0ca_scoped_true__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true */ "./node_modules/laravel-mix/node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue?vue&type=template&id=3aa5d0ca&scoped=true");


/***/ }),

/***/ "./resources/js/services/OssSyncService.js":
/*!*************************************************!*\
  !*** ./resources/js/services/OssSyncService.js ***!
  \*************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
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
  intervalMsWhenDisconnected: 5000,
  backoffStartMs: 5000,
  backoffCapMs: 30000,
  jitterMaxMs: 500
});
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
        intervalMsWhenConnected: this._positiveInt(cfg.intervalMsWhenConnected, DEFAULTS.intervalMsWhenConnected),
        intervalMsWhenDisconnected: this._positiveInt(cfg.intervalMsWhenDisconnected, DEFAULTS.intervalMsWhenDisconnected)
      };
    }
  }, {
    key: "_bindWebSocketState",
    value: function _bindWebSocketState() {
      var _this = this;
      var ws = this._webSocketService;
      if (!ws || typeof ws.on !== 'function') {
        this._wsState = 'disconnected';
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
        _this._wsState = next || 'unknown';
        _this._emit('ws_state', {
          state: _this._wsState
        });
        _this._state = STATE.POLLING;
        _this._currentBackoffMs = _this._opts.backoffStartMs;
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
      this._scheduleNext(base + this._jitter());
    }
  }, {
    key: "_scheduleNext",
    value: function _scheduleNext(delayMs) {
      var _this2 = this;
      this._clearTimer();
      if (!this._started) {
        return;
      }
      var delay = Math.max(0, this._positiveInt(delayMs, 0));
      this._lastScheduledDelayMs = delay;
      this._timer = setTimeout(function () {
        _this2._timer = null;
        _this2._poll()["catch"](function () {});
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


/***/ })

}]);