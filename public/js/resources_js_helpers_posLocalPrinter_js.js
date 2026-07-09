"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["resources_js_helpers_posLocalPrinter_js"],{

/***/ "./resources/js/helpers/posLocalPrinter.js"
/*!*************************************************!*\
  !*** ./resources/js/helpers/posLocalPrinter.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CAISSE_BRIDGE_URL: () => (/* binding */ CAISSE_BRIDGE_URL),
/* harmony export */   _resetCaisseBridgeHealthCache: () => (/* binding */ _resetCaisseBridgeHealthCache),
/* harmony export */   _resetPrintedCaisse: () => (/* binding */ _resetPrintedCaisse),
/* harmony export */   b64ToBytes: () => (/* binding */ b64ToBytes),
/* harmony export */   isCaisseBridgeAvailable: () => (/* binding */ isCaisseBridgeAvailable),
/* harmony export */   markPrintedOnceCaisse: () => (/* binding */ markPrintedOnceCaisse),
/* harmony export */   printEscPosViaCaisseBridge: () => (/* binding */ printEscPosViaCaisseBridge)
/* harmony export */ });
/* provided dependency */ var Buffer = __webpack_require__(/*! buffer */ "./node_modules/buffer/index.js")["Buffer"];
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
/**
 * posLocalPrinter.js — [CAISSE-BRIDGE 2026-06-28] Impression SILENCIEUSE de la caisse
 * via un pont local node sur le PC caisse (miroir du pont borne).
 *
 * Topologie : Laravel = cloud Linux → ne peut PAS sortir sur l'USB du SAGA caisse.
 * Le serveur RIND les octets ESC/POS (SSOT NF525, endpoint GET orders/{id}/escpos →
 * escpos_b64) ; ce helper les décode et les POSTe TELS QUELS au pont local
 * `http://127.0.0.1:9100/raw` → le SAGA imprime sans aucune fenêtre Chrome.
 *
 * Différence avec la borne (kioskPrinter.js) : la borne envoie un JSON que le pont
 * reconstruit en ESC/POS (ASCII-fold) ; ici on envoie les OCTETS FISCAUX EXACTS
 * (passthrough RAW), donc le ticket papier == le rendu serveur (NF525-fidèle).
 *
 * `http://127.0.0.1` est un secure-context → fetch autorisé depuis une page HTTPS
 * (exempté du blocage mixed-content). Sur la VRAIE caisse, le flag Chrome
 * `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
 * est requis (cf docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md).
 */

function caisseBridgeUrl() {
  try {
    var u = window.foodkingConfig && window.foodkingConfig.caisseBridgeUrl;
    if (typeof u === 'string' && u) return u.replace(/\/+$/, '');
  } catch (_) {/* défaut ci-dessous */}
  return 'http://127.0.0.1:9100';
}
var CAISSE_BRIDGE_URL = caisseBridgeUrl();
function fetchWithTimeout(url, opts, timeoutMs) {
  if (typeof fetch !== 'function') return Promise.reject(new Error('no fetch'));
  var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
  var t = ctrl ? setTimeout(function () {
    return ctrl.abort();
  }, timeoutMs) : null;
  var o = Object.assign({}, opts);
  if (ctrl) o.signal = ctrl.signal;
  return fetch(url, o)["finally"](function () {
    if (t) clearTimeout(t);
  });
}

/** Décode une chaîne base64 → Uint8Array (octets fiscaux bruts). */
function b64ToBytes(b64) {
  var bin = typeof atob === 'function' ? atob(String(b64 || '')) : Buffer.from(String(b64 || ''), 'base64').toString('binary');
  var out = new Uint8Array(bin.length);
  for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i) & 0xff;
  return out;
}

// [PRINT-INSTANT 2026-07-06] Health check MÉMOÏSÉ : le health frappait le pont à
// CHAQUE impression (jusqu'à 800 ms de latence ajoutée par ticket). On met le
// résultat en cache TTL court : positif 20 s (pont stable), négatif 5 s (un pont
// qui démarre redevient visible vite). `force:true` bypass le cache (re-print manuel).
var HEALTH_TTL_OK_MS = 20000;
var HEALTH_TTL_KO_MS = 5000;
var _healthCache = {
  ok: false,
  at: 0
};
/** Test-only : purge le cache health. */
function _resetCaisseBridgeHealthCache() {
  _healthCache = {
    ok: false,
    at: 0
  };
}

/** True si le pont caisse répond /health → "UP". Timeout court, jamais throw. */
function isCaisseBridgeAvailable() {
  return _isCaisseBridgeAvailable.apply(this, arguments);
}

/**
 * POSTe les octets ESC/POS (depuis le base64 serveur) au pont local en passthrough RAW.
 * Renvoie {ok:true} si imprimé, sinon null (le caller retombe sur window.print). Jamais throw.
 */
// [PRINT-INSTANT 2026-07-06] Timeout /raw configurable (window.foodkingConfig
// .caisseBridgeRawTimeoutMs), défaut 3000 ms : le pont répond désormais 202
// {queued:true} DÈS réception (impression async côté pont) → 3 s suffisent
// largement, et un abort tardif ne fabrique plus de faux « échec » alors que
// le papier sort (l'ancien timeout 5 s couvrait la compile winspool à chaque job).
function _isCaisseBridgeAvailable() {
  _isCaisseBridgeAvailable = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
    var timeoutMs,
      opts,
      now,
      ttl,
      ok,
      res,
      txt,
      _args = arguments,
      _t;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.p = _context.n) {
        case 0:
          timeoutMs = _args.length > 0 && _args[0] !== undefined ? _args[0] : 800;
          opts = _args.length > 1 && _args[1] !== undefined ? _args[1] : {};
          now = Date.now();
          ttl = _healthCache.ok ? HEALTH_TTL_OK_MS : HEALTH_TTL_KO_MS;
          if (!(!opts.force && _healthCache.at && now - _healthCache.at < ttl)) {
            _context.n = 1;
            break;
          }
          return _context.a(2, _healthCache.ok);
        case 1:
          ok = false;
          _context.p = 2;
          _context.n = 3;
          return fetchWithTimeout(caisseBridgeUrl() + '/health', {}, timeoutMs);
        case 3:
          res = _context.v;
          if (!(res && res.ok)) {
            _context.n = 5;
            break;
          }
          _context.n = 4;
          return res.text();
        case 4:
          txt = _context.v;
          ok = /UP/i.test(txt);
        case 5:
          _context.n = 7;
          break;
        case 6:
          _context.p = 6;
          _t = _context.v;
          ok = false;
        case 7:
          _healthCache = {
            ok: ok,
            at: Date.now()
          };
          return _context.a(2, ok);
      }
    }, _callee, null, [[2, 6]]);
  }));
  return _isCaisseBridgeAvailable.apply(this, arguments);
}
function rawTimeoutMs() {
  var opts = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {};
  if (Number.isFinite(opts.timeoutMs) && opts.timeoutMs > 0) return opts.timeoutMs;
  try {
    var t = window.foodkingConfig && window.foodkingConfig.caisseBridgeRawTimeoutMs;
    if (Number.isFinite(t) && t > 0) return t;
  } catch (_) {/* défaut ci-dessous */}
  return 3000;
}
function printEscPosViaCaisseBridge(_x) {
  return _printEscPosViaCaisseBridge.apply(this, arguments);
}

// [ANTI-DOUBLE 2026-06-28] Garde 1-ticket-par-(commande,type) persistée localStorage
// (survit au F5 / re-montage). Clé = orderRef|ticket|jour.
function _printEscPosViaCaisseBridge() {
  _printEscPosViaCaisseBridge = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(escposB64) {
    var opts,
      bytes,
      res,
      _args2 = arguments,
      _t2;
    return _regenerator().w(function (_context2) {
      while (1) switch (_context2.p = _context2.n) {
        case 0:
          opts = _args2.length > 1 && _args2[1] !== undefined ? _args2[1] : {};
          _context2.p = 1;
          if (escposB64) {
            _context2.n = 2;
            break;
          }
          return _context2.a(2, null);
        case 2:
          bytes = b64ToBytes(escposB64);
          _context2.n = 3;
          return fetchWithTimeout(caisseBridgeUrl() + '/raw', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/octet-stream'
            },
            body: bytes
          }, rawTimeoutMs(opts));
        case 3:
          res = _context2.v;
          return _context2.a(2, res && res.ok ? {
            ok: true,
            method: 'caisse-bridge'
          } : null);
        case 4:
          _context2.p = 4;
          _t2 = _context2.v;
          return _context2.a(2, null);
      }
    }, _callee2, null, [[1, 4]]);
  }));
  return _printEscPosViaCaisseBridge.apply(this, arguments);
}
var PRINTED_LS_KEY = 'pos_printed_tickets_v1';
var _printed = null;
function _load() {
  if (_printed) return _printed;
  _printed = new Set();
  try {
    var raw = window.localStorage.getItem(PRINTED_LS_KEY);
    if (raw) JSON.parse(raw).forEach(function (k) {
      return _printed.add(k);
    });
  } catch (_) {/* mémoire seule */}
  return _printed;
}
function markPrintedOnceCaisse(orderRef) {
  var ticket = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 'client';
  var day = function () {
    try {
      return new Date().toISOString().slice(0, 10);
    } catch (_) {
      return '';
    }
  }();
  var k = "".concat(orderRef == null ? '' : orderRef, "|").concat(ticket, "|").concat(day).trim();
  if (k.startsWith('|')) return false; // pas de ref
  var set = _load();
  if (set.has(k)) return false;
  set.add(k);
  try {
    window.localStorage.setItem(PRINTED_LS_KEY, JSON.stringify(Array.from(set).slice(-300)));
  } catch (_) {}
  return true;
}
/** Test-only : réinitialise la garde. */
function _resetPrintedCaisse() {
  _printed = null;
}

/***/ }

}]);