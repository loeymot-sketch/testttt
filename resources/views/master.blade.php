<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <!-- REQUIRED META TAGS -->
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- [iter15-mega-fix C-007 2026-05-10] removed meta-CSP — middleware emits HTTP header
         The previous kiosk-only <meta http-equiv="Content-Security-Policy-Report-Only">
         was a transition-period fallback (RED-R2 §1 P2). Browsers IGNORE meta-tag
         report-only directives, so violations were silently dropped on kiosk. The
         authoritative CSP is now emitted as an HTTP response header by
         App\Http\Middleware\ContentSecurityPolicyHeader (registered in the `web`
         middleware group, applies to kiosk routes). Mode pilotable via
         CSP_ENFORCE_MODE (config/security.php). Violations are still ingested via
         /api/frontend/csp-report. See docs/runbooks/CSP_HEADER_MIGRATION.md. --}}

    <!-- FONTS — Inter pour le kiosk (Splash DNA) + existing fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    {{-- CV1-KIOSK-VISUAL-REDESIGN-001 V1.2 — Fraunces display font, kiosk-only.
         Plan : plans/PLAN_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md §1.3
         CSP : déjà autorisé via le header report-only (style-src + font-src
               whitelistent fonts.googleapis.com / fonts.gstatic.com).
         TODO V1.2.1 : self-hosted woff2 sous public/fonts/fraunces/ pour
                       offline kiosk + perf LCP + CSP strict (suppression
                       de l'allowlist Google Fonts). --}}
    @if (request()->is('kiosk*'))
        <link
            href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700;9..144,900&display=swap"
            rel="stylesheet"
        >
    @endif

    <link rel="stylesheet" href="{{ asset('themes/default/fonts/fontawesome/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/lab/lab.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/typography/public/public.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/typography/rubik/rubik.css') }}">

    <!-- CUSTOM STYLE -->
    <link rel="stylesheet" href="{{ asset('themes/default/css/custom.css') }}">
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ time() }}">
    <!-- PAGE TITLE -->
    <title>{{ trim((string) Settings::group('company')->get('company_name')) ?: (config('app.name') ?: 'FoodKing') }}</title>

    <!-- FAV ICON -->
    <link rel="icon" type="image" href="{{ $favicon }}">


    @if (!blank($analytics))
        @foreach ($analytics as $analytic)
            @if (!blank($analytic->analyticSections))
                @foreach ($analytic->analyticSections as $section)
                    @if ($section->section == \App\Enums\AnalyticSection::HEAD)
                        {!! $section->data !!}
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif
</head>

<body>
    @if (!blank($analytics))
        @foreach ($analytics as $analytic)
            @if (!blank($analytic->analyticSections))
                @foreach ($analytic->analyticSections as $section)
                    @if ($section->section == \App\Enums\AnalyticSection::BODY)
                        {!! $section->data !!}
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif

    <div id="app">
        @if (request()->is('kiosk*'))
            <router-view />
        @else
            <default-component />
        @endif
    </div>

    @if (!blank($analytics))
        @foreach ($analytics as $analytic)
            @if (!blank($analytic->analyticSections))
                @foreach ($analytic->analyticSections as $section)
                    @if ($section->section == \App\Enums\AnalyticSection::FOOTER)
                        {!! $section->data !!}
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif

    {{--
        Config runtime SPA — DOIT être AVANT app.js pour que i18n.js lise
        window.foodkingConfig.kioskDefaultLocale au moment de l'initialisation du bundle.
        (config:cache OK : on utilise config() et non env() directement)
    --}}
    <script>
        window.foodkingConfig = {
            baseUrl: @json(rtrim((string) config('app.url'), '/')),
            apiKey: @json((string) config('app.api_key')),
            googleMapKey: @json((string) config('app.google_map_key')),
            demo: @json((bool) config('app.demo_mode')),
            // Borne : n'injecter les credentials machine que sur les routes kiosk.
            kioskAutoLogin: @json(request()->is('kiosk*') ? config('kiosk.spa_payload') : null),
            // Langue UI borne : fr | ar | en (défaut fr) — évite anglais si le navigateur / localStorage était en "en"
            kioskDefaultLocale: @json((string) config('kiosk.default_locale', 'fr')),
            kioskMenuPricing: @json(config('kiosk.menu_pricing', [])),
            // Borne : une catégorie « Nos Sandwichs » en base, deux lignes sidebar (signatures / froid)
            kioskSandwichSplit: @json(config('kiosk.sandwich_split')),
            maxItemQty: @json((int) config('kiosk.max_item_qty', 20)),
            kioskConfirmationAutoReturnSeconds: @json((int) config('kiosk.confirmation_auto_return_seconds', 30)),
            ossFallbackPolling: {
                enabled: @json((bool) config('catalog_v15.oss_fallback_polling.enabled', true)),
                intervalMsWhenConnected: @json((int) config('catalog_v15.oss_fallback_polling.interval_ms_when_connected', 60000)),
                intervalMsWhenDisconnected: @json((int) config('catalog_v15.oss_fallback_polling.interval_ms_when_disconnected', 5000)),
            },
            kdsFallbackPolling: {
                highActivityBaseMs: @json((int) config('catalog_v15.kds_fallback_polling.high_activity_base_ms', 3000)),
                highActivityJitterMs: @json((int) config('catalog_v15.kds_fallback_polling.high_activity_jitter_ms', 1000)),
                degradedBaseMs: @json((int) config('catalog_v15.kds_fallback_polling.degraded_base_ms', 5000)),
                degradedJitterMs: @json((int) config('catalog_v15.kds_fallback_polling.degraded_jitter_ms', 2000)),
                disconnectedBaseMs: @json((int) config('catalog_v15.kds_fallback_polling.disconnected_base_ms', 10000)),
                disconnectedJitterMs: @json((int) config('catalog_v15.kds_fallback_polling.disconnected_jitter_ms', 3000)),
            },
            // [STAFF-ONLY-V1] Feature flags for surface restructuring
            staffOnlyMode: @json((bool) env('STAFF_ONLY_MODE', false)),
            kioskUsePosWizard: @json((bool) env('KIOSK_USE_POS_WIZARD', false)),
            // [iter15-mega-fix C-003/A-003 2026-05-10] Expose APP_ENV so the SPA
            // can suppress the "Connexion temps réel perdue" banner in dev/local
            // environments where Pusher/Soketi is not running. Production keeps
            // the banner — it's still useful messaging during real outages.
            appEnv: @json((string) app()->environment()),
            features: {
                wizard_per_item_demo: @json(\App\Support\WizardPerItemDemo::enabled(request())),
            },
            // [BYPASS-P1 + AUDIT-HEAL B8] E2E flow validation flags exposed to SPA.
            // Frontend uses these to render visible "MODE TEST" markers. Production guard
            // in AppServiceProvider::boot() prevents activation in APP_ENV=production.
            // RED-AUDIT B8 trouvé: HTML disclosure mineure → on conditionne l'injection
            // sur !production pour ne JAMAIS leak la clé en prod (même si false).
            @if (!app()->environment('production'))
            bypassMode: {
                payment: @json((bool) config('payment.bypass.enabled', false)),
                printing: @json((bool) config('printing.bypass.enabled', false)),
                printingScreenMarker: @json((string) config('printing.bypass.screen_marker_text', '🔧 MODE TEST — IMPRESSION BYPASSÉE')),
            },
            @endif
        };
        // [SEC-30-2] Demo credentials injected server-side — never hardcoded in JS bundle
        // [GAP-32-6] Use config() instead of env() — env() returns null after config:cache in production
        window.__FOODKING_RUNTIME__ = {
            demo: @json((bool) config('app.demo_mode')) ? {
                adminEmail:          @json((string) config('app.demo_credentials.admin_email')),
                adminPassword:       @json((string) config('app.demo_credentials.admin_password')),
                customerEmail:       @json((string) config('app.demo_credentials.customer_email')),
                customerPassword:    @json((string) config('app.demo_credentials.customer_password')),
                branchManagerEmail:  @json((string) config('app.demo_credentials.branch_manager_email')),
                branchManagerPassword: @json((string) config('app.demo_credentials.branch_manager_password')),
                posOperatorEmail:    @json((string) config('app.demo_credentials.pos_operator_email')),
                posOperatorPassword: @json((string) config('app.demo_credentials.pos_operator_password')),
                chefEmail:           @json((string) config('app.demo_credentials.chef_email')),
                chefPassword:        @json((string) config('app.demo_credentials.chef_password')),
            } : null,
        };
    </script>

    {{-- [POS-V4 W1-B 2026-04-26] Vendor chunking — order is critical: --}}
    {{-- manifest (webpack runtime) → vendor (third-party libs) → app (our code). --}}
    {{-- Reverting requires running `git checkout webpack.mix.js master.blade.php` then `npm run production`. --}}
    <script src="{{ mix('js/manifest.js') }}"></script>
    <script src="{{ mix('js/vendor.js') }}"></script>
    <script src="{{ mix('js/app.js') }}"></script>
    <script src="{{ asset('themes/default/js/drawer.js') }}"></script>
    <script src="{{ asset('themes/default/js/modal.js') }}"></script>
    <script src="{{ asset('themes/default/js/customScript.js') }}"></script>
    <script src="{{ asset('themes/default/js/tabs.js') }}"></script>
    <script src="{{ asset('themes/default/js/dropdown.js') }}"></script>
    {{-- [AUDIT-FIX P2-1] Wizard pricing config injected server-side — prevents hardcoded stale values --}}
    <script>
        window.POS_WIZARD_CONFIG = {
            sauceExtraPrice:   {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_sauce_extra_price') ?? 0.50) }},
            viandeSupplPrice:  {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_viande_suppl_price') ?? 2.50) }},
            fritesGrandePrice: {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_frites_grande_price') ?? 1.00) }},
            fritesCheddarPrice: {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_frites_cheddar_price') ?? 1.00) }}
        };
    </script>
    <script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>

    <!-- Masquer Dine-In dans le POS : uniquement Emporter et Livraison -->
    <style>
        label[for="dinein"] {
            display: none !important;
        }

        #dine {
            display: none !important;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Auto-sélectionner Takeaway au lieu de Dine-In
            var checkInterval = setInterval(function () {
                var takeawayBtn = document.querySelector('label[for="takeway"]');
                var dineinBtn = document.querySelector('label[for="dinein"]');
                if (takeawayBtn) {
                    // Retirer active de Dine-In, ajouter à Takeaway
                    if (dineinBtn) dineinBtn.classList.remove('active');
                    takeawayBtn.classList.add('active');
                    var takeawayInput = document.getElementById('takeway');
                    if (takeawayInput) {
                        takeawayInput.checked = true;
                        takeawayInput.dispatchEvent(new Event('change', { bubbles: true }));
                        takeawayInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    clearInterval(checkInterval);
                }
            }, 500);
            // Stop checking after 10s
            setTimeout(function () { clearInterval(checkInterval); }, 10000);
        });
    </script>
</body>

</html>
