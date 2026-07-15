const mix = require('laravel-mix');
const webpack = require('webpack');

// [iter15-mega-fix Vue-warn-cluster round-7 2026-05-10]
// Inject Vue 3 esm-bundler compile-time feature flags so the build no longer
// emits the runtime warning:
//   "Feature flag __VUE_PROD_HYDRATION_MISMATCH_DETAILS__ is not explicitly
//    defined. You are running the esm-bundler build of Vue..."
// These globals also unlock dead-code elimination of Options API / devtools
// helpers in the production bundle (cf. https://link.vuejs.org/feature-flags).
mix.webpackConfig({
    plugins: [
        new webpack.DefinePlugin({
            __VUE_OPTIONS_API__: JSON.stringify(true),
            __VUE_PROD_DEVTOOLS__: JSON.stringify(false),
            __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
        }),
    ],
});
/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

// [POS-V4 W1-B 2026-04-26] Vendor chunking — extracts heavy stable third-party
// dependencies (Vue ecosystem + charts + UI + realtime + i18n + a11y) into a
// long-cached `vendor.js` chunk + webpack runtime `manifest.js`.
// Goal: reduce `app.js` first-paint payload and improve cache hit ratio on
// app-code releases (vendors change rarely).
// Blade order MUST be: manifest.js -> vendor.js -> app.js (cf. master.blade.php L143-148).
// Pattern: official Laravel Mix `extract()` API (https://laravel-mix.com/docs/main/extract).
// [POS-V4 W1-B+ FIX 2026-04-26] Added 7 universal libs (i18n / store / realtime /
// sanitization / loading / carousel) and content-hash versioning for CDN/proxy
// cache reliability per AUDIT_W1B_VENDOR_CHUNK_CLAUDE_2026-04-26.md Q4.
// [POS-V4 W2 #1 2026-04-26] Dedicated POS entry-point. Compiled in parallel to
// app.js. Both entries share `vendor.js` (extract list below) and the lazy
// `pos-shell` chunk (PosComponent + FloorplanComponent). Net effect: the POS
// V4 surface (URL /admin/pos-v4) loads ~318 KB pos-app.js instead of 456 KB
// app.js. Legacy /admin/pos remains untouched and continues to load app.js.
// See docs/design/ADR_POS_V4_DEDICATED_ENTRY.md for the architecture decision.
//
// [POS-V4 W2 #1 FIX D.6 2026-04-26] No `.extract()` here intentionally:
// Laravel Mix applies extract() globally across ALL `mix.js()` chains, so
// the extract list defined on the app.js chain below ALSO governs pos-app.js.
// If Mix changes its global vs per-entry semantics in a future major version,
// this entry could begin bundling vendor modules inline (silent regression).
// CI guard `tools/lint/pos_app_size.mjs` (W2 #3) will assert the gz ceiling.
// Rollback: revert this file (1 line addition) — webpack will skip pos-app.js.
mix.js('resources/js/pos-app.js', 'public/js').vue();

// [GOAL RUPTURE-CARNET 2026-07-15 / W5] Carnet — mini-app PIN standalone
// (/carnet). Partage manifest.js + vendor.js (extract global, cf. note D.6
// ci-dessus) : daily-book.blade.php charge manifest + vendor + daily-book.
mix.js('resources/js/daily-book/app.js', 'public/js/daily-book.js').vue()
    .postCss('resources/css/daily-book.css', 'public/css');

mix.js('resources/js/app.js', 'public/js')
    .vue()
    .extract([
        // Vue ecosystem (initial vendor batch)
        'vue',
        'vuex',
        'vue-router',
        'axios',
        // UI / charts (initial vendor batch)
        'vue-toastification',
        'vue3-simple-alert',
        'vue-next-select',
        'vue3-apexcharts',
        'apexcharts',
        // [W1-B+ FIX] Universal stable deps imported from app.js / store / i18n / bootstrap
        'vue-i18n',
        'vuex-persistedstate',
        'laravel-echo',
        'pusher-js',
        'dompurify',
        'vue-element-loading',
        'swiper',
    ])
    .postCss('resources/css/app.css', 'public/css', [require("tailwindcss")])
    .version();
