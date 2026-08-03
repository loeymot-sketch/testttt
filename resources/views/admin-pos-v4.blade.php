{{--
    [POS-V4 W2 #1 2026-04-26] Slim Blade view for the dedicated POS entry.
    Variant of `master.blade.php` — loads `pos-app.js` instead of `app.js`.
    See docs/design/ADR_POS_V4_DEDICATED_ENTRY.md.

    Differences vs master.blade.php (kept minimal — same DNA):
      - <script src="js/pos-app.js"> instead of <script src="js/app.js">.
      - No KioskDesignSystem CSS dependency (kiosk surface is not loaded here).
      - Same vendor.js + manifest.js order (Blade order is critical).
      - Trimmed window.foodkingConfig (POS reads only apiKey/baseUrl/locale/menu
        wizard pricing). Demo credentials block was REMOVED (was a P0 finding —
        see AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md D.1).
      - Same pos-wizard.js shim (POS V4 still relies on it for wizard UX).

    Rollback: delete this file + revert routes/web.php.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/fontawesome/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/lab/lab.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/typography/public/public.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/typography/rubik/rubik.css') }}">

    <link rel="stylesheet" href="{{ asset('themes/default/css/custom.css') }}">
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ time() }}">

    <title>POS V4 — {{ Settings::group('company')->get('company_name') }}</title>

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
        <default-component />
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

    {{-- [POS-V4 W2 #1 FIX 2026-04-26] Runtime config — STRICT MINIMUM for POS.
         Removed vs master.blade.php (audit AUDIT_W2_DEDICATED_ENTRY_CLAUDE D.1/D.2/D.7):
         - window.__FOODKING_RUNTIME__ block (demo credentials) — pos-app.js does
           NOT render the /login form; demo creds belong on the legacy Blade only.
           Removing them eliminates the P0 credential-exposure risk on this route.
         - kioskAutoLogin: null — dead config (pos-app.js never reads it).
         - staffOnlyMode + kioskUsePosWizard — used env() (broken under
           config:cache) and unread by pos-app.js. Same fix is owed to
           master.blade.php (backlog item ST-W2-ENV-1-LEGACY).
         The remaining keys (baseUrl, apiKey, googleMapKey, kioskDefaultLocale,
         kioskMenuPricing, kioskSandwichSplit, maxItemQty) ARE read by axios
         interceptors / i18n / wizard helpers — keep as-is. --}}
    <script>
        window.foodkingConfig = {
            baseUrl: @json(rtrim((string) config('app.url'), '/')),
            apiKey: @json((string) config('app.api_key')),
            googleMapKey: @json((string) config('app.google_map_key')),
            demo: @json((bool) config('app.demo_mode')),
            kioskDefaultLocale: @json((string) config('kiosk.default_locale', 'fr')),
            kioskMenuPricing: @json(config('kiosk.menu_pricing', [])),
            kioskSandwichSplit: @json(config('kiosk.sandwich_split')),
            maxItemQty: @json((int) config('kiosk.max_item_qty', 20)),
            posFallbackPolling: {
                enabled: @json((bool) config('catalog_v15.pos_fallback_polling.enabled', false)),
                intervalMsWhenDisconnected: @json((int) config('catalog_v15.pos_fallback_polling.interval_ms_when_disconnected', 30000)),
            },
            posWizardComposerAware: {
                enabled: @json((bool) config('catalog_v15.pos_wizard_composer_aware.enabled', false)),
            },
            posV4Entry: true,
        };
    </script>

    {{-- [POS-V4 W2 #1] Vendor chunking — order critical: manifest → vendor → pos-app. --}}
    {{-- pos-app.js (NOT app.js) is the dedicated POS entry. --}}
    <script src="{{ mix('js/manifest.js') }}"></script>
    <script src="{{ mix('js/vendor.js') }}"></script>
    <script src="{{ mix('js/pos-app.js') }}"></script>
    <script src="{{ asset('themes/default/js/drawer.js') }}"></script>
    <script src="{{ asset('themes/default/js/modal.js') }}"></script>
    <script src="{{ asset('themes/default/js/customScript.js') }}"></script>
    <script src="{{ asset('themes/default/js/tabs.js') }}"></script>
    <script src="{{ asset('themes/default/js/dropdown.js') }}"></script>

    {{-- POS V4 still depends on the wizard shim — keep injection identical. --}}
    <script>
        window.POS_WIZARD_CONFIG = {
            sauceExtraPrice:   {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_sauce_extra_price') ?? 0.50) }},
            viandeSupplPrice:  {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_viande_suppl_price') ?? 2.50) }},
            fritesGrandePrice: {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_frites_grande_price') ?? 1.00) }},
            fritesCheddarPrice: {{ (float) (\Smartisan\Settings\Facades\Settings::group('order_setup')->get('order_setup_frites_cheddar_price') ?? 1.00) }}
        };
    </script>
    <script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>

    {{-- Same Dine-In hide rule as master.blade.php — POS UX expectation. --}}
    <style>
        label[for="dinein"] { display: none !important; }
        #dine { display: none !important; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var checkInterval = setInterval(function () {
                var takeawayBtn = document.querySelector('label[for="takeway"]');
                var dineinBtn = document.querySelector('label[for="dinein"]');
                if (takeawayBtn) {
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
            setTimeout(function () { clearInterval(checkInterval); }, 10000);
        });
    </script>
</body>

</html>
