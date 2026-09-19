<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [2026-05-18 PR-D drift-guard sentinel — F-D-T3]
 *
 * Per ultra-review PR-D F-D-T3, ~78 FormRequest classes contain a naked
 * `return true;` in their `authorize()` method, deferring authz entirely
 * to route middleware (or to nothing, for routes without
 * `permission:*` middleware). V1.0.2 will surgically refactor the 8
 * highest-blast offenders to call `$this->user()?->can('xxx')`.
 *
 * This sentinel does NOT fix the 78 — it locks the BASELINE so a careless
 * refactor cannot grow the count. Any new FormRequest with `return true;`
 * (or any other reverted file) flips this sentinel red.
 *
 * Update the baseline ONLY when:
 *   - A FormRequest moves from `return true;` to an explicit can() check
 *     (count goes DOWN)
 *   - A new FormRequest is added with `return true;` AND there is a route
 *     middleware that authoritatively covers it (count goes UP with
 *     explicit owner-gate doc in plans/v1-0-2-backlog/).
 */
class FormRequestAuthzDriftSentinelTest extends TestCase
{
    /**
     * Baseline = 64 (2026-07-02 ULTRA-AUDIT V4-DEPLOY ratchet — CompanyRequest + SiteRequest
     * refactorés `return true` → `$this->user()?->can('settings')` (defense-in-depth des settings
     * écrits en .env). -2 vs le plancher 66 précédent ; ratchet pour verrouiller le progrès.
     *
     * History:
     *   - 77 initial Wave 8 (commit 68b63c090) — sentinel baseline establishment.
     *   - 74 post Wave 5H (commit 46fb4ef2d) — 5 admin-write FormRequests refactored
     *     to $this->user()?->can('xxx') (Currency, Tax, Branch, Role, Administrator).
     *   - 69 post BUILD-6 — 8 critical FormRequests refactored.
     *   - 66 ratchet 2026-05-29 SUP-2 audit — actual count had drifted -3 since
     *     BUILD-6 without baseline update (subsequent wave fixes uncounted).
     *     Ratchet cements progress so regression cannot re-introduce.
     *   - 69 post BUILD-6 — 8 critical FormRequests refactored:
     *       * PosOrderRequest   → can('pos')                              (POS order create)
     *       * DeliveryBoyRequest→ can('delivery-boys_create|edit')
     *       * CouponRequest     → can('coupons_create|edit')
     *       * OfferRequest      → can('offers_create|edit')
     *       * PermissionRequest → can('settings')                         (Spatie root mutation)
     *       * KioskMachineRequest → can('settings')                       (kiosk pairing → sanctum tokens)
     *       * DiningTableRequest → can('dining_tables_create|edit')
     *       * ItemRequest       → can('items_create|edit')                (catalog mutation)
     *
     * Note: 3 NEW FormRequests landed before this wave (DeliveryBoyCashSession*),
     * each ships with `return true;` but the underlying routes are guarded by
     * the LIVREUR-Z4-ARCH-03 cash-session controller middleware. They will be
     * folded into a follow-up BUILD-6.5 wave (same can() pattern) once the
     * delivery-boy cash-session feature is fully wired.
     *
     * V1.0.2 BACKLOG (remaining ~69 → keep shrinking each wave):
     * Largest blast-radius candidates remaining (Phase A subset):
     *   - EmployeeRequest, ChefRequest, WaiterRequest (staff create/edit)
     *   - SignupRequest, OtpRequest, VerifyPhoneRequest (customer authn)
     *   - CompanyRequest, SiteRequest, ThemeRequest, LanguageRequest (settings)
     *   - SliderRequest, PageRequest, SocialMediaRequest (CMS)
     *   - ItemCategoryRequest, ItemAttributeRequest, ItemExtraRequest, ItemAddonRequest,
     *     ItemVariationRequest, MenuTemplateRequest, OfferItemRequest (catalog family)
     */
    // [ONB-13 C7 2026-08-28] 64 → 54. Le critère C7 de la mission visait ≤ 55.
    //
    // Dix règles sont passées de `return true` inconditionnel à un miroir EXACT de
    // la permission que porte leur route — famille catalogue, création de comptes
    // (Chef, Serveur), et réglages. Ce n'est pas la garde principale : le
    // middleware du contrôleur garde déjà l'accès. C'est le second verrou, si une
    // route est un jour recâblée sans son middleware.
    //
    // [FUSION 2026-08-28] 54 → 52. Deux voies ont durci des FormRequest DIFFÉRENTES
    // en parallèle : ONB-13 C7 posait 54, le GOAL CONSOLIDATION du 2026-08-25 posait
    // 62. Ni l'un ni l'autre n'est le bon cliquet une fois les deux réunis — les
    // ensembles se recouvrent en partie seulement. 52 est le compte RÉEL de l'arbre
    // fusionné, mesuré avec le regex de ce banc, pas le minimum des deux annonces.
    // Reprendre un cliquet annoncé plutôt que mesuré l'aurait laissé trop lâche de
    // deux crans, et le cliquet aurait cessé de mordre sur les deux prochaines
    // régressions.
    private const RETURN_TRUE_BASELINE = 52;

    public function test_form_request_return_true_count_does_not_grow_past_baseline(): void
    {
        $dir = base_path('app/Http/Requests');
        $this->assertDirectoryExists($dir);

        $count = 0;
        $files = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            // Match the authorize() body shape exactly. Excludes commented
            // examples / docblock references / unrelated `return true;` in
            // other methods would still match — accepted noise budget.
            if (preg_match('/public function authorize\(\)\s*:\s*bool\s*\{\s*return true;\s*\}/', $body)
                || preg_match('/public function authorize\(\)\s*\{\s*return true;\s*\}/', $body)) {
                $count++;
                $files[] = basename($file->getPathname());
            }
        }

        $msg = sprintf(
            "Found %d FormRequest classes with `return true;` in authorize() — baseline is %d.\n".
            "If count GROWS, refactor the new file to call \$this->user()?->can('xxx').\n".
            "If count SHRINKS, lower RETURN_TRUE_BASELINE in this sentinel + add a regression test for the refactored authz.\n".
            "Affected files:\n  - %s",
            $count,
            self::RETURN_TRUE_BASELINE,
            implode("\n  - ", $files),
        );

        $this->assertLessThanOrEqual(
            self::RETURN_TRUE_BASELINE,
            $count,
            'FormRequest authz drift detected. ' . $msg,
        );

        // Diagnostic: also report when count has SHRUNK so the operator
        // remembers to lower the baseline post-refactor.
        if ($count < self::RETURN_TRUE_BASELINE) {
            $baseline = self::RETURN_TRUE_BASELINE;
            fwrite(STDOUT, "\n[sentinel] FormRequest return-true count is now {$count} "
                . "(< baseline {$baseline}). Lower RETURN_TRUE_BASELINE accordingly.\n");
        }
    }
}
