<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <!-- REQUIRED META TAGS -->
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- FONTS -->
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/fontawesome/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/lab/lab.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/typography/public/public.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/default/fonts/typography/rubik/rubik.css') }}">

    <!-- CUSTOM STYLE -->
    <link rel="stylesheet" href="{{ asset('themes/default/css/custom.css') }}">
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ time() }}">
    <!-- PAGE TITLE -->
    <title>{{ Settings::group('company')->get('company_name') }}</title>

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

    {{-- Config runtime SPA : toujours alignée sur Laravel (config:cache OK). Le bundle Mix peut avoir des MIX_* obsolètes. --}}
    <script>
        window.foodkingConfig = {
            baseUrl: @json(rtrim((string) config('app.url'), '/')),
            apiKey: @json((string) config('app.api_key')),
            googleMapKey: @json((string) config('app.google_map_key')),
            demo: @json((bool) config('app.demo_mode')),
        };
    </script>

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