<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ============================================================================
 * DATABASE SEEDER - FOODKING
 * ============================================================================
 *
 * This seeder initializes the database with all required data.
 * NOTE: Menu seeding is handled separately by MenuSeeder - the single source
 * of truth for French menu items.
 *
 * To seed the menu:
 *   php artisan menu:create
 *
 * To reset the menu:
 *   php artisan menu:reset
 *
 * DEPRECATED: ItemCategoryTableSeeder, ItemTableSeeder (English seeders removed)
 * USE INSTEAD: MenuSeeder (French only)
 * ============================================================================
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Core system seeders (required for operation)
        $this->call(MenuTableSeeder::class);
        $this->call(MenuTemplateTableSeeder::class);
        $this->call(MenuSectionTableSeeder::class);
        $this->call(PermissionTableSeeder::class);
        $this->call(RoleTableSeeder::class);
        $this->call(CompanyTableSeeder::class);
        $this->call(LanguageTableSeeder::class);
        $this->call(SiteTableSeeder::class);
        $this->call(PaymentGatewayTableSeederVersionOne::class);
        $this->call(SmsGatewayTableSeederVersionOne::class);
        $this->call(CurrencyTableSeeder::class);
        $this->call(BranchTableSeeder::class);
        $this->call(UserTableSeeder::class);
        $this->call(RolePermissionTableSeeder::class);
        $this->call(ComposerPermissionsMinimalSeeder::class);
        $this->call(IngredientPermissionSeeder::class);
        $this->call(LeCayenneRoleLandingUrlSeeder::class);
        $this->call(MailTableSeeder::class);
        $this->call(OrderSetupTableSeeder::class);
        $this->call(OtpTableSeeder::class);
        $this->call(NotificationTableSeeder::class);
        $this->call(NotificationAlertTableSeeder::class);
        $this->call(CookiesTableSeeder::class);
        $this->call(ThemeTableSeeder::class);
        $this->call(LicenseTableSeeder::class);
        $this->call(KioskMachineTableSeeder::class);

        // Content seeders
        $this->call(SocialMediaTableSeeder::class);
        $this->call(AnalyticTableSeeder::class);
        $this->call(TaxTableSeeder::class);
        $this->call(PageTableSeeder::class);
        $this->call(SliderTableSeeder::class);

        // =========================================================================
        // MENU SEEDING - SINGLE SOURCE OF TRUTH
        // =========================================================================
        // USE: MenuSeeder ONLY - It handles categories, items, attributes,
        //      variations, extras, and addons in ONE atomic operation.
        //
        // Commands:
        //   php artisan menu:create  - Create menu (fails if exists)
        //   php artisan menu:reset   - Purge and recreate
        //   php artisan menu:verify  - Validate French integrity
        //
        // DEPRECATED (BLOCKED from execution):
        //   - ItemCategoryTableSeeder (deleted)
        //   - ItemTableSeeder (deleted)
        //   - GrillHouseMenuSeeder (deprecated, throws exception)
        //   - CompleteFrenchMenuSeeder (deprecated, throws exception)
        //   - ItemExtraTableSeeder (deprecated, throws exception)
        //   - ItemVariationTableSeeder (deprecated, throws exception)
        //   - ItemAddonTableSeeder (deprecated, throws exception)
        //   - ItemAttributeTableSeeder (not needed, handled by MenuSeeder)
        // =========================================================================
        $this->call(MenuSeeder::class);
        $this->call(TimeSlotTableSeeder::class);
        $this->call(PaymentGatewayDataTableSeeder::class);

        // Related item structures (variations, extras, addons)
        // These are now seeded by MenuSeeder - kept for compatibility
        // $this->call(ItemVariationTableSeeder::class);
        // $this->call(ItemExtraTableSeeder::class);
        // $this->call(ItemAddonTableSeeder::class);

        // Promotions and offers
        $this->call(OfferTableSeeder::class);
        $this->call(OfferItemTableSeeder::class);
        $this->call(CouponTableSeeder::class);

        // Kiosk Design V1 — Phase 1.3 : référentiel des 14 allergènes EU 1169/2011.
        // Idempotent (updateOrCreate sur `code`), safe à lancer à chaque migrate:fresh.
        $this->call(AllergensSeeder::class);

        // Order data (optional - for demo purposes)
        // $this->call(OrderTableSeeder::class);
        // $this->call(OrderItemTableSeeder::class);
        // $this->call(OrderCouponTableSeeder::class);
        // $this->call(OrderAddressTableSeeder::class);
        // $this->call(TransactionTableSeeder::class);
        // $this->call(KdsOrderTableSeeder::class);
        // $this->call(OrderTableSeederVersionTwo::class);

        // Communication
        $this->call(PushNotificationTableSeeder::class);
        $this->call(MessageTableSeeder::class);
        $this->call(MessageHistoryTableSeeder::class);
        $this->call(DiningTableTableSeeder::class);
    }
}
