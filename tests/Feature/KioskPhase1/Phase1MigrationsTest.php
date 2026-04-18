<?php

namespace Tests\Feature\KioskPhase1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.10 : assert que toutes les migrations
 * Phase 1.1 ont ajouté les colonnes/tables attendues.
 */
class Phase1MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_categories_has_parent_id(): void
    {
        $this->assertTrue(Schema::hasColumn('item_categories', 'parent_id'));
    }

    public function test_allergens_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('allergens'));
        foreach (['code', 'name_key', 'icon', 'sort'] as $col) {
            $this->assertTrue(Schema::hasColumn('allergens', $col), "allergens.$col missing");
        }
    }

    public function test_item_allergen_pivot_exists(): void
    {
        $this->assertTrue(Schema::hasTable('item_allergen'));
        $this->assertTrue(Schema::hasColumn('item_allergen', 'is_trace'));
    }

    public function test_upsell_rules_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('upsell_rules'));
        foreach (['branch_id', 'trigger_type', 'trigger_value', 'suggested_item_id', 'active', 'priority', 'starts_at', 'ends_at', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('upsell_rules', $col), "upsell_rules.$col missing");
        }
    }

    public function test_kiosk_promos_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('kiosk_promos'));
        foreach (['branch_id', 'code', 'type', 'value', 'min_cart', 'valid_from', 'valid_to', 'max_uses', 'uses_count', 'active', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('kiosk_promos', $col), "kiosk_promos.$col missing");
        }
    }

    public function test_branches_has_available_locales(): void
    {
        $this->assertTrue(Schema::hasColumn('branches', 'available_locales'));
    }

    public function test_items_has_is_chef_pick(): void
    {
        $this->assertTrue(Schema::hasColumn('items', 'is_chef_pick'));
    }

    public function test_loyalty_consents_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('loyalty_consents'));
        foreach (['user_id', 'consent_accepted', 'privacy_notice_version', 'ip_hash', 'user_agent_hash', 'occurred_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('loyalty_consents', $col), "loyalty_consents.$col missing");
        }
    }
}
