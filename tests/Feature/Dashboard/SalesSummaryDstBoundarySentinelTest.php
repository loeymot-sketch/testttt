<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.1 · Codex P1-G]
 *
 * `salesSummary` fabriquait sa liste de jours en additionnant 86 400 secondes :
 *
 *     for ($t = strtotime($first); $t <= strtotime($last); $t += 86400)
 *
 * Un jour civil ne fait pas toujours 86 400 secondes. À Paris, le 29 mars 2026 en fait
 * 82 800 (passage à l'heure d'été) et le 25 octobre 2026 en fait 90 000 (retour à l'heure
 * d'hiver). Conséquence mesurée sur cette machine :
 *
 *   - 2026-03-28 → 2026-03-31 rendait 3 jours au lieu de 4 : le 31 mars DISPARAISSAIT du
 *     graphique et du dénominateur de la moyenne journalière ;
 *   - 2026-10-24 → 2026-10-26 rendait le 25 octobre DEUX FOIS : la journée était comptée
 *     en double dans le dénominateur.
 *
 * Deux fois par an, le chiffre d'affaires moyen par jour était donc faux, sans qu'aucune
 * erreur n'apparaisse nulle part. Ce banc verrouille les deux bascules.
 */
class SalesSummaryDstBoundarySentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function jours(string $premier, string $dernier): array
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        $r = $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/dashboard/sales-summary?first_date='.$premier.'&last_date='.$dernier)
            ->assertOk()
            ->json();

        // `per_day_sales` est une simple liste de montants : les DATES n'étaient exposées
        // nulle part, si bien que le graphique traçait une courbe sans axe des abscisses.
        // `per_day_labels` les publie — c'est aussi le seul moyen d'observer la génération
        // des jours depuis l'extérieur.
        $etiquettes = $r['per_day_labels'] ?? $r['data']['per_day_labels'] ?? null;
        $montants = $r['per_day_sales'] ?? $r['data']['per_day_sales'] ?? null;
        $this->assertIsArray($etiquettes, 'la réponse doit nommer les jours de la ventilation');
        $this->assertIsArray($montants, 'la réponse doit porter la ventilation par jour');
        $this->assertSameSize($etiquettes, $montants, 'un montant par jour, un jour par montant');

        return $etiquettes;
    }

    public function test_le_passage_a_l_heure_d_ete_ne_fait_pas_disparaitre_un_jour(): void
    {
        // 29 mars 2026 : 02:00 → 03:00. La journée ne fait que 82 800 s.
        $jours = $this->jours('2026-03-28', '2026-03-31');

        $this->assertSame(
            ['2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31'],
            $jours,
            'quatre jours demandés, quatre jours rendus — le 31 mars ne doit pas disparaître'
        );
    }

    public function test_le_retour_a_l_heure_d_hiver_ne_compte_pas_un_jour_deux_fois(): void
    {
        // 25 octobre 2026 : 03:00 → 02:00. La journée fait 90 000 s.
        $jours = $this->jours('2026-10-24', '2026-10-26');

        $this->assertSame(
            ['2026-10-24', '2026-10-25', '2026-10-26'],
            $jours,
            'trois jours demandés, trois jours rendus — le 25 octobre ne doit pas apparaître deux fois'
        );
        $this->assertSame(count($jours), count(array_unique($jours)), 'aucun jour en double');
    }

    /**
     * Le dénominateur de la moyenne journalière est `count($dateRangeArray)` : un jour
     * manquant ou compté double fausse directement le « CA moyen par jour » affiché.
     */
    public function test_le_nombre_de_jours_sert_de_denominateur_et_reste_juste(): void
    {
        $this->assertCount(4, $this->jours('2026-03-28', '2026-03-31'));
        $this->assertCount(3, $this->jours('2026-10-24', '2026-10-26'));
        $this->assertCount(1, $this->jours('2026-03-29', '2026-03-29'));
        $this->assertCount(366, $this->jours('2024-01-01', '2024-12-31'), '2024 est bissextile');
    }
}
