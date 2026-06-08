<?php

namespace Tests\Feature\Composer;

use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 7] Anti-drift sentinel for the resolveForItem TRAP.
 *
 * ComposerProfileService::resolveForItem implements CATEGORY-wins precedence (category checked
 * first, item as fallback). The 4 live RENDER resolvers must NOT use it — they implement the
 * canonical ITEM-owned precedence (item-owned profile wins; category profile inherited only when
 * the item has none). Wiring resolveForItem into a render path would silently invert precedence,
 * making a category default shadow an item's own published wizard. This sentinel fails if any render
 * resolver starts referencing resolveForItem, before that inversion can ship.
 */
class ComposerResolveForItemTrapSentinelTest extends TestCase
{
    /** The 4 live render resolvers (item-owned precedence + category fallback). */
    private const RENDER_RESOLVERS = [
        'app/Services/Menu/MenuProjectionService.php',
        'app/Services/Kiosk/KioskMenuService.php',
        'app/Http/Resources/ItemResource.php',
        'app/Http/Resources/NormalItemResource.php',
    ];

    public function test_render_resolvers_do_not_use_the_category_wins_resolveForItem_trap(): void
    {
        foreach (self::RENDER_RESOLVERS as $relative) {
            $source = file_get_contents(base_path($relative));
            $this->assertIsString($source, "missing render resolver: {$relative}");
            $this->assertStringNotContainsString(
                'resolveForItem',
                $source,
                "{$relative} must NOT use ComposerProfileService::resolveForItem (category-wins trap) — "
                .'render paths use item-owned precedence with category fallback.'
            );
        }
    }

    public function test_each_render_resolver_keeps_item_owned_precedence_then_category_fallback(): void
    {
        foreach (self::RENDER_RESOLVERS as $relative) {
            $source = file_get_contents(base_path($relative));
            $this->assertStringContainsString('item_id', $source, "{$relative} must resolve by item_id (item-owned precedence)");
            $this->assertStringContainsString('item_category_id', $source, "{$relative} must keep a category fallback");
        }
    }
}
