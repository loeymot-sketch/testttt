<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * V1 Le Cayenne — Wave Q-4 retraction of Wave P Round 2-B seeded allergens
 * (orchestrator self-correction 2026-05-20).
 *
 * ## Background
 *
 * On 2026-05-19 the original `LeCayenneAllergenSeeder` (Wave P R2-B) populated
 * `items.allergen_flags` + `item_allergen` for the 45 Le Cayenne menu items
 * using **guessed mappings** ("Sandwich → gluten/oeufs/lait/moutarde/sulfites
 * assuming standard fast-food bun + commercial sauces"). The intent was EU
 * 1169/2011 (FIC) Annex II compliance for the France launch, with a
 * conservative over-flag bias ("when in doubt, OVER-flag rather than under-
 * flag").
 *
 * On 2026-05-20 the owner identified that the guessed allergens were NOT
 * truthful chef-confirmed data (the rice base obviously has none of the
 * flagged items; the seeded "5-allergen Sandwich Cayenne" was an assumption).
 * Per owner verbatim: "Rice doesn't have any allergy or anything. It's a
 * problem you put them just like that — remove them, they serve nothing,
 * except if real ones should be visible."
 *
 * ## Action taken
 *
 * - All `items.allergen_flags` reset to `[]` (= explicit FIC "checked-none"
 *   disclosure rather than ambiguous NULL — consistent with the rest of the
 *   model, since the column is `cast => 'array'`).
 * - `item_allergen` pivot truncated.
 * - This seeder is now a NOOP. It is preserved (rather than deleted) so the
 *   audit trail for the regression is unambiguous.
 *
 * ## Path forward (owner direction)
 *
 * - Owner / restaurant chef will provide REAL per-item allergens.
 * - At that point, either:
 *   (a) Implement an admin UI editor under "Articles → Allergènes" so the
 *       restaurant team can manage allergens themselves (no developer churn
 *       on a per-recipe basis), OR
 *   (b) Replace the body of this seeder with the chef-confirmed mapping
 *       (preserving the SSOT discipline: `allergen_flags` JSON + pivot via
 *       `AllergenService::projectFlags`).
 *
 * ## FIC compliance note (legal)
 *
 * EU Regulation 1169/2011 mandates disclosure of the 14 declared allergens
 * on prepared foods sold in restaurants. Until real chef-confirmed data is
 * provided, the system is technically **non-FIC-compliant for production
 * ship to France**. V1 Le Cayenne LOCAL only (single restaurant, owner
 * decision). Production ship MUST gate on this work being completed first.
 *
 * The previous `AllergenCoverageSentinelTest` 80% coverage assertion has
 * been moved to `@group manual` for the same reason — CI no longer enforces
 * a coverage target that depended on fabricated data.
 */
class LeCayenneAllergenSeeder extends Seeder
{
    public function run(): void
    {
        // NOOP — see class docblock for the regression history.
        //
        // Re-enabling this seeder = replace the body with chef-confirmed
        // per-item allergens AND restore the sentinel coverage threshold
        // in tests/Feature/Sentinels/AllergenCoverageSentinelTest.php.
    }
}
