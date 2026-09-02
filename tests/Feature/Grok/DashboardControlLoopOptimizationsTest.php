<?php

namespace Tests\Feature\Grok;

use Tests\TestCase;

class DashboardControlLoopOptimizationsTest extends TestCase
{
    public function test_quick_access_fail_closed_when_permissions_unknown(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/DashboardComponent.vue'));
        $this->assertSame(1, preg_match('/quickAccessLinks\(\) \{([\s\S]*?)return links;/', $src, $m));
        $this->assertStringContainsString('if (!perms.length)', $m[1]);
        $this->assertStringContainsString('return false', $m[1]);
        $this->assertStringNotContainsString("if (!perms.length) {\n                    return true;", $m[1]);
    }

    public function test_z_report_widget_fail_closed(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/LastZReportWidget.vue'));
        $this->assertStringContainsString("p.url === 'transactions'", $src);
        $this->assertStringContainsString('return false', $src);
    }

    public function test_audit_trail_caps_at_20_and_deprioritizes_logins(): void
    {
        $src = file_get_contents(base_path('app/Services/DashboardService.php'));
        $this->assertStringContainsString("->limit(20)", $src);
        $this->assertStringContainsString("'user.login','user.logout'", $src);
        $vue = file_get_contents(base_path('resources/js/components/admin/dashboard/AuditTrailComponent.vue'));
        $this->assertStringContainsString('max-h-80', $vue);
    }

    public function test_sla_alerts_are_capped_in_sql(): void
    {
        $src = file_get_contents(base_path('app/Services/DashboardService.php'));
        $this->assertStringContainsString("->orderBy('updated_at', 'asc')\n                ->limit(50)", $src);
    }

    public function test_system_health_asks_before_toggle_and_announces_status(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/observability/SystemHealthComponent.vue'));
        $this->assertStringContainsString('aria-live="polite"', $src);
        $this->assertStringContainsString('window.confirm', $src);
    }

    public function test_sidebar_maps_cockpit_to_settings_permission(): void
    {
        $src = file_get_contents(base_path('resources/js/components/layouts/backend/BackendMenuComponent.vue'));
        $this->assertStringContainsString("'observability/system': 'settings'", $src);
        $this->assertStringContainsString("'observability/outbox': 'settings'", $src);
    }

    public function test_overview_uses_cayenne_palette_not_magenta(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/OverviewComponent.vue'));
        $this->assertStringContainsString('#F4501E', $src);
        $this->assertStringContainsString('#FFB800', $src);
        $this->assertStringNotContainsString('#C81E63', $src);
    }

    public function test_realtime_no_longer_duplicates_sales_and_orders(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/RealtimeReportComponent.vue'));
        $this->assertStringContainsString('Ticket Moyen', $src);
        $this->assertStringNotContainsString("Chiffre d'Affaires du Jour", $src);
        $this->assertStringNotContainsString('Commandes du Jour', $src);
    }

    public function test_total_menu_items_counts_active_only(): void
    {
        $src = file_get_contents(base_path('app/Services/DashboardService.php'));
        $this->assertStringContainsString("where('status', Status::ACTIVE)", $src);
        $this->assertStringContainsString('constrainCustomerFacing', $src);
        $this->assertStringNotContainsString('return Item::count();', $src);
    }

    public function test_overview_keeps_loader_until_all_tiles_return(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/OverviewComponent.vue'));
        $this->assertStringContainsString('pendingLoads', $src);
        $this->assertStringContainsString('this.pendingLoads > 0', $src);
    }

    public function test_realtime_does_not_paint_zero_on_error(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/RealtimeReportComponent.vue'));
        $this->assertStringContainsString('.catch(', $src);
        $this->assertStringContainsString("this.failed = true", $src);
        $this->assertStringNotContainsString("report.daily_sales || '0.00'", $src);
    }

    public function test_stock_low_widget_fail_closed_when_perms_unknown(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/dashboard/StockLowAlertsWidget.vue'));
        $this->assertStringContainsString('if (!perms.length)', $src);
        $this->assertStringContainsString('return false', $src);
        $this->assertStringNotContainsString('never silently degrade', $src);
    }

    /**
     * [2026-09-02 · Sub 3.1] Ce banc COMPTAIT les occurrences de `$this->assertSalesDateWindow`
     * dans le source et en exigeait au moins trois. Il mesurait une forme d'écriture, pas un
     * comportement : le garde a été centralisé dans `DashboardService::resolveDashboardWindow()`,
     * ce qui le fait passer de 3 points d'entrée gardés à 4 — une amélioration nette — et
     * pourtant le compteur tombait à 1 et le banc virait au rouge. Un banc qui rougit quand le
     * produit s'améliore finit par être neutralisé, pas écouté.
     *
     * Le refus lui-même est désormais vérifié pour de bon, sur les quatre points datés et sur
     * cinq formes d'entrée fautives (période inversée, > 366 jours, borne isolée, date
     * impossible, date illisible), dans
     * `tests/Feature/Dashboard/DashboardDateContractMatrixTest.php`. Cette classe-ci n'a pas de
     * base de données (elle ne fait que lire des sources), d'où le déplacement plutôt qu'une
     * réécriture sur place.
     */

    public function test_outbox_queue_lane_uses_queue_size(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Admin/Observability/SyncOverviewController.php'));
        $this->assertStringContainsString('Queue::size($queue)', $src);
    }

    public function test_healthz_probe_fails_if_any_monitored_queue_throws(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/HealthzController.php'));
        $this->assertStringContainsString('$unreadable !== []', $src);
    }

    public function test_system_health_failed_fetch_is_not_no_backup(): void
    {
        $src = file_get_contents(base_path('resources/js/components/admin/observability/SystemHealthComponent.vue'));
        $this->assertStringContainsString("return 'mesure indisponible'", $src);
        $this->assertStringContainsString('journal serveur, pas le journal fiscal', $src);
    }

    public function test_page_index_requires_settings_permission(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Admin/PageController.php'));
        $this->assertStringContainsString("'index', 'store', 'update', 'destroy', 'show'", $src);
    }

    public function test_healthz_cli_does_not_print_unknown_queue_as_zero(): void
    {
        $src = file_get_contents(base_path('app/Console/Commands/HealthzCheckCommand.php'));
        $this->assertStringContainsString('queue=%s', $src);
        $this->assertStringNotContainsString('queue=%d', $src);
        $this->assertStringContainsString("=== 'unknown'", $src);
    }
}
