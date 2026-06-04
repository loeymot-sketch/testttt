<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags (V1 LOCAL Le Cayenne)
    |--------------------------------------------------------------------------
    */

    /*
    | offers_enabled — the admin "Offres" promo module.
    |
    | [GOAL-GOLIVE-VAT10 / S1 2026-05-30] DISABLED for V1. The Offers feature
    | DISPLAYS a discounted price to the customer/cashier (ItemResource
    | convert_price, rendered on kiosk + POS wizard) but PricingService
    | (FROZEN, SSOT) does NOT apply the discount — it charges full price. So an
    | active offer = "shows X, charges Y" (consumer-law/dispute risk, receipt
    | mismatch). Dormant today (0 offers exist). Until PricingService is wired
    | to apply offers server-side under a lock-plan, creating an offer is
    | blocked (offer create/update/item-assign routes → 403) and the nav entry
    | is hidden. Read routes stay open so existing data is never orphaned.
    |
    | Re-enable (FEATURE_OFFERS_ENABLED=true) ONLY after PricingService applies
    | active offers + a behavioral test proves charged == displayed.
    */
    'offers_enabled' => filter_var(env('FEATURE_OFFERS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | staff_only_mode — desactive la vitrine client "marketplace livraison".
    |
    | [STAFF-ONLY-V1 / ST-W2-ENV-1-LEGACY 2026-06-04] FoodKing V1 LOCAL Le Cayenne
    | est un SYSTEME DE CAISSE : l'entree est le Dashboard admin / login, puis
    | POS (page principale) / Kiosk / KDS (Cuisine). L'ancienne vitrine client
    | (/home, /menu, /offers, /checkout, /search ...) est ABANDONNEE et ne doit
    | pas etre servie.
    |
    | Quand true, le garde Vue Router (resources/js/router/index.js beforeEach)
    | redirige toute route meta.isFrontend===true vers /admin/dashboard (authentifie)
    | ou /login (anonyme). La borne /kiosk reste publique autonome (exemptee).
    |
    | Lue depuis un FICHIER config (et non env() en Blade) pour survivre a
    | `php artisan config:cache` en production — l'ancien env('STAFF_ONLY_MODE', false)
    | dans master.blade.php retournait false des que la config etait cachee
    | (bug ST-W2-ENV-1-LEGACY, desormais corrige).
    |
    | Defaut true : produit POS-only, fail-secure (la vitrine reste eteinte meme
    | si la cle .env est absente).
    */
    'staff_only_mode' => filter_var(env('STAFF_ONLY_MODE', true), FILTER_VALIDATE_BOOLEAN),

];
