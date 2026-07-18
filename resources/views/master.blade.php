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
        {{-- Borne Accueil attract redesign 2026-06-28 — Bricolage Grotesque
             (display) + Hanken Grotesk (body), per owner design import. CSP
             font-src/style-src whitelistent déjà fonts.googleapis/gstatic. --}}
        <link
            href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap"
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
    {{-- [W5-PERF A2 2026-07-06] filemtime au lieu de time() : l'URL ne change QUE quand le
         fichier change → le navigateur peut enfin cacher les 41 Ko (avant : re-téléchargés à
         CHAQUE reload du POS). Le busting réel au déploiement est préservé (mtime bouge). --}}
    <link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ @filemtime(public_path('css/pos-wizard.css')) ?: 2 }}">
    <!-- PAGE TITLE -->
    <title>{{ trim((string) Settings::group('company')->get('company_name')) ?: (config('app.name') ?: 'Le Cayenne') }}</title>

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
    @php
        // [2026-05-18 PR-B P0 kiosk-creds-leak heal] Gate the SPA auto-login
        // payload by (a) path filter `/kiosk*` (legacy), (b) request IP in
        // the configured allowlist OR `APP_ENV=local` (dev bypass).
        // Without this, any unauthenticated requester to `/kiosk/idle` could
        // harvest the machine credentials in cleartext (curl public host →
        // grep kioskAutoLogin → mint a `kiosk:order` Sanctum token).
        // Production deployment MUST set KIOSK_AUTO_LOGIN_TRUSTED_IPS to the
        // LAN IPs of the physical kiosk machines, OR set
        // KIOSK_REQUIRE_MACHINE_LOGIN=true (shows a UI login form instead).
        // Sister test: tests/Feature/Kiosk/KioskAutoLoginGateTest.php
        // [BORNE-CLOUD 2026-06-27] Matching délégué à KioskAutoLoginGate
        // (IpUtils) → supporte les plages CIDR (ex. /64 IPv6 de la borne cloud,
        // robuste à la rotation d'IPv6) en plus des IP exactes. Comportement
        // sécurité inchangé : kiosk* ET (local OU IP/CIDR de confiance OU secret).
        // [BORNE-CLOUD-SEC 2026-06-27] Pour la DÉCISION DE RELEASE des creds on
        // utilise le pair TCP RÉEL (REMOTE_ADDR), PAS request()->ip() : sous
        // TrustProxies $proxies='*', request()->ip() est dérivé de X-Forwarded-For
        // → spoofable (un attaquant forge XFF avec une IP de confiance). REMOTE_ADDR
        // n'est pas falsifiable par le client (posé par nginx/php-fpm).
        $kioskAutoLoginPayload = \App\Support\KioskAutoLoginGate::resolvePayload(
            config('kiosk.spa_payload'),
            request()->is('kiosk*'),
            (bool) config('kiosk.auto_login_local_bypass', false),
            (array) config('kiosk.auto_login_trusted_ips', []),
            request()->server('REMOTE_ADDR'),
            request()->query('machine_key'),
            (string) config('kiosk.auto_login_secret', ''),
        );
    @endphp
    <script>
        window.foodkingConfig = {
            baseUrl: @json(rtrim((string) config('app.url'), '/')),
            apiKey: @json((string) config('app.api_key')),
            googleMapKey: @json((string) config('app.google_map_key')),
            demo: @json((bool) config('app.demo_mode')),
            // [BOLS/2-VIANDES 2026-06-24] La caisse v5 (PosComponent) charge
            // public/js/pos-wizard.js mais n'exposait PAS ce flag (contrairement
            // à admin-pos-v4.blade.php) → pos-wizard.js tombait sur le builder
            // LEGACY (detectCategory par nom) qui (a) crash sur les Bols
            // (attribut « Viande 1 » sans init selections.viandes pour le
            // template 'snacking'/'simple') et (b) dédupe-par-nom les 2 viandes
            // (Tacos L/Méga/Terminator → 2ᵉ viande perdue). Le builder COMPOSER
            // (item.composer_profile.steps) rend correctement bols + 2 viandes
            // distinctes (prouvé live sur /admin/pos-v4). Le flag suit la même
            // config que la v4 : FK_POS_WIZARD_COMPOSER_AWARE_ENABLED.
            posWizardComposerAware: {
                enabled: @json((bool) config('catalog_v15.pos_wizard_composer_aware.enabled', false)),
            },
            // [2026-05-18 PR-B P0 heal] Machine creds gated by IP allowlist +
            // APP_ENV=local. See @php block above. Public unauth requests now
            // get `null` even on /kiosk/* paths.
            kioskAutoLogin: @json($kioskAutoLoginPayload),
            // Langue UI borne : fr | ar | en (défaut fr) — évite anglais si le navigateur / localStorage était en "en"
            kioskDefaultLocale: @json((string) config('kiosk.default_locale', 'fr')),
            // [ADR-007 / Sprint 3D 2026-05-16] Kiosk runtime FR-immutable en V1.
            // `false` ferme le UI picker locale (KsA11ySettings) ET désactive la
            // persistance de `kioskSettings.locale` dans localStorage. Voir
            // docs/adr/ADR-007-kiosk-fr-lock.md pour relax post-V1.
            kioskLocaleSwitchAllowed: @json((bool) config('kiosk.locale_switch_allowed', false)),
            kioskMenuPricing: @json(config('kiosk.menu_pricing', [])),
            // [SUPERVISOR WAVE C Z1 2026-05-28] Plan B: route ALL kiosk payments to counter.
            // When true, KioskPaymentComponent skips method selection UI and auto-submits
            // with payment_method=CASH_ON_DELIVERY (1). Order remains PENDING_COUNTER
            // until cashier collects at POS. See config/kiosk.php for env override.
            kiosk: {
                paymentRouteAllToCounter: @json((bool) config('kiosk.payment_route_all_to_counter', true)),
            },
            // [BORNE-TICKET-SIZE 2026-06-28] Taille de police du ticket borne, pilotée
            // serveur (le pont bridge.js applique GS ! n). Owner: « bien grande » → 2×2.
            // Modifiable via config/printing.php (BORNE_TICKET_BODY_SIZE) sans toucher la borne.
            borneTicket: {
                bodySize: @json((int) config('printing.borne_ticket.body_size', 0x01)),
                titleSize: @json((int) config('printing.borne_ticket.title_size', 0x11)),
                // [TICKET-PHONE 2026-07-03] Téléphone + adresse imprimés sur le ticket borne (le
                // pont bridge.js les affiche en en-tête, design pro). Fallback config si branche vide.
                phone: @json((string) config('printing.receipt.phone', '')),
                address: @json((string) config('printing.receipt.address', '')),
                // [TICKET-BORNE-COMPACT 2026-07-03] Avance papier COURTE (8) + coupe PARTIELLE :
                // ticket compact qui ne tombe pas (reste accroché) — fini le grand espace blanc.
                feedLines: @json((int) config('printing.cut.kiosk_client_feed_lines', 8)),
                cutPartial: @json(strtolower((string) config('printing.cut.kiosk_client_mode', 'partial')) === 'partial'),
            },
            // [1000%-NO-POPUP 2026-07-03] Caisse silencieuse : true → JAMAIS window.print (popup gris).
            // Le ticket ne sort QUE via le pont RAW (octets serveur = ticket == écran). POS_PRINT_SILENT_ONLY.
            // [PRINT-INSTANT 2026-07-06] Défaut flippé à TRUE (window.print = bouton manuel only).
            posSilentPrintOnly: @json((bool) config('printing.pos_silent_only', true)),
            // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 Q2] Discretionary-discount
            // master flag, exposed so the customer UI hides coupon + loyalty-redeem
            // entries while discounts are disabled — otherwise a customer who uses them
            // hits a raw 422 dead-end (the backend gates the order). When F1 is fixed
            // and the flag flipped on, the entries reappear automatically.
            discountsEnabled: @json((bool) config('pos.manual_discount_enabled', false)),
            // [W2 audit heal 2026-06-26] DEDICATED kiosk promo/loyalty gate (default FALSE).
            // The borne promo-code + loyalty-redeem block promised a discount the backend
            // never applies (kiosk sends only kiosk_promo_code metadata, never a coupon_id →
            // cart showed "-X €" but the customer was charged full price). This flag is
            // INDEPENDENT of the shared discountsEnabled flag so POS manual discount + web
            // checkout stay untouched. Default FALSE hides the kiosk entries. See config/kiosk.php.
            kioskPromoEnabled: @json((bool) config('kiosk.promo_enabled', false)),
            // Borne : une catégorie « Nos Sandwichs » en base, deux lignes sidebar (signatures / froid)
            kioskSandwichSplit: @json(config('kiosk.sandwich_split')),
            maxItemQty: @json((int) config('kiosk.max_item_qty', 20)),
            kioskConfirmationAutoReturnSeconds: @json((int) config('kiosk.confirmation_auto_return_seconds', 30)),
            ossFallbackPolling: {
                enabled: @json((bool) config('catalog_v15.oss_fallback_polling.enabled', true)),
                intervalMsWhenConnected: @json((int) config('catalog_v15.oss_fallback_polling.interval_ms_when_connected', 60000)),
                // [test-e2e round-2 cluster-6 D-002 2026-05-10] Fallback aligned
                // with catalog_v15.php (2000ms) so the polling cadence meets the
                // SYNC-2 8s budget when WS is down.
                intervalMsWhenDisconnected: @json((int) config('catalog_v15.oss_fallback_polling.interval_ms_when_disconnected', 2000)),
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
            staffOnlyMode: @json((bool) config('features.staff_only_mode')),
            kioskUsePosWizard: @json((bool) config('kiosk.use_pos_wizard')),
            // [iter15-mega-fix C-003/A-003 2026-05-10] Expose APP_ENV so the SPA
            // can suppress the "Connexion temps réel perdue" banner in dev/local
            // environments where Pusher/Soketi is not running. Production keeps
            // the banner — it's still useful messaging during real outages.
            appEnv: @json((string) app()->environment()),
            // [Wave T R1 F3 WT-B-R1-007 2026-05-20] Expose branch count so the
            // admin SPA can hide messaging that only makes sense in a multi-
            // branch deployment (e.g. KDS "Compte central multi-succursales"
            // polling hint). Single-branch installs like Le Cayenne render
            // branch_count=1 and the KDS computes kdsIsCentralAdmin=false so
            // the misleading banner is suppressed. Cached 5min to avoid a
            // SELECT COUNT(*) on every SPA boot. NF525-irrelevant query.
            branchCount: @json((int) \Illuminate\Support\Facades\Cache::remember(
                'fk:branches:count',
                300,
                fn () => \App\Models\Branch::query()->count()
            )),
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
        // [Sprint H1 K-003 2026-05-17] Externalize FRITES_INCLUDED_CATS so DB
        // renumber/menu reset doesn't silently break wizard fries-inclusion logic.
        // Consumed by KioskWizardComponent.vue:1029 (shouldAskStep frites_style).
        window.FK_KIOSK_FRITES_CATS = @json(config('kiosk.frites_included_category_ids', []));
        // [Sprint H1 K-004 2026-05-17] Wizard template aliases (Owner G3 Option B):
        // owner-curated substring → canonical template map, consulted first by
        // KioskWizardComponent.vue:907 detectTemplateFromName so admin renames
        // don't silently break wizard template routing.
        window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES = @json(config('kiosk.wizard_template_aliases', []));
        // [Sprint H4 Z3-NEW-006 2026-05-17] KDS V2 org-wide kill-switch. Defaults
        // true (V2 is the rollout default per Wave Z 5C). Operators can rollback
        // all devices via KDS_V2_DEFAULT_ENABLED=false in .env instead of
        // per-tab localStorage flipping. Consumed by
        // KitchenDisplaySystemComponent.vue::useV2Layout (config layer between
        // localStorage and hardcoded fallback).
        window.FK_KDS_V2_DEFAULT_ENABLED = @json((bool) config('kds.v2_default_enabled', true));
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
    {{-- [W5-PERF A2 2026-07-06] filemtime au lieu de time() — voir <head> : fin des ~300 Ko
         de pos-wizard.js re-téléchargés à chaque reload (URL stable tant que le fichier ne
         change pas). NE PAS répliquer sur admin-pos-v4.blade.php (FROZEN §7). --}}
    <script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ @filemtime(public_path('js/pos-wizard.js')) ?: 9 }}"></script>

    <!-- Masquer Dine-In dans le POS : uniquement Emporter et Livraison -->
    <style>
        label[for="dinein"] {
            display: none !important;
        }

        #dine {
            display: none !important;
        }

        /* [OWNER 2026-06-30] Bug borne PORTRAIT « ça sort du tableau / scroll bizarre » :
           sur les étapes du wizard à beaucoup d'options (12 sauces, 12 suppléments…), le
           contenu dépasse la zone visible en 1080×1920. Le layout scrolle déjà proprement,
           MAIS le scrollbar était masqué (scrollbar-width:none) → le client ne voyait AUCUN
           indice et croyait les options coupées. Fix FROZEN-SAFE : surcharges globales
           !important (KioskWizardComponent.vue intouché). Indice de scroll visible + marge
           basse + fondu d'incitation au-dessus de la barre d'action. */
        .kiosk-step-content {
            scrollbar-width: thin !important;
            scrollbar-color: rgba(244, 80, 30, 0.5) transparent !important;
            padding-bottom: 36px !important;
            scroll-padding-bottom: 36px !important;
        }
        .kiosk-step-content::-webkit-scrollbar {
            display: block !important;
            width: 9px !important;
        }
        .kiosk-step-content::-webkit-scrollbar-thumb {
            background: rgba(244, 80, 30, 0.5) !important;
            border-radius: 999px !important;
        }
        .kiosk-nav {
            position: relative !important;
        }
        .kiosk-nav::before {
            content: '' !important;
            position: absolute !important;
            left: 0;
            right: 0;
            bottom: 100% !important;
            height: 44px !important;
            background: linear-gradient(to top, rgba(255, 250, 245, 0.97), rgba(255, 250, 245, 0)) !important;
            pointer-events: none !important;
            z-index: 3 !important;
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
