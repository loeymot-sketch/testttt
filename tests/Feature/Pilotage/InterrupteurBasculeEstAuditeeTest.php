<?php

namespace Tests\Feature\Pilotage;

use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 4.3 · Codex P2-B]
 *
 * Les interrupteurs changent le comportement de la caisse en production, sans
 * déploiement : couper le paiement fractionné, arrêter la roue. Leur seule trace était un
 * `Log::info` — un fichier texte, rotaté, effaçable, hors de toute chaîne de contrôle.
 *
 * Le soir où le paiement fractionné cesse de fonctionner, la question n'est pas « est-ce
 * que ça marche », c'est « QUI l'a coupé, QUAND, et depuis quel état ». Un journal
 * applicatif ne répond pas à ça de façon opposable : il peut avoir tourné, être tronqué,
 * ou avoir été purgé par la même personne.
 *
 * La bascule écrit désormais une ligne dans le journal d'audit chaîné (`audit_logs`,
 * signature HMAC enchaînée, suppression interdite par déclencheur SQL) : acteur, valeur
 * avant, valeur après, branche, horodatage. Et si l'audit échoue, la bascule N'A PAS LIEU
 * — une bascule non traçable est pire qu'une bascule refusée.
 */
class InterrupteurBasculeEstAuditeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function premierInterrupteur(): string
    {
        $etat = app(InterrupteurService::class)->etat();
        $this->assertNotEmpty($etat, 'le catalogue des interrupteurs ne doit pas être vide');

        return (string) (is_array($etat[0]) ? $etat[0]['nom'] : $etat[0]->nom);
    }

    public function test_une_bascule_ecrit_une_ligne_dans_le_journal_d_audit(): void
    {
        $nom = $this->premierInterrupteur();
        $avant = app(InterrupteurService::class)->valeur($nom);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/'.$nom, ['actif' => ! $avant])
            ->assertOk();

        $ligne = DB::table('audit_logs')
            ->where('action', 'pilotage.interrupteur.bascule')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($ligne, 'la bascule doit laisser une ligne dans audit_logs');
        $this->assertSame((int) $admin->id, (int) $ligne->user_id, "l'acteur doit être nommé");

        $charge = json_decode((string) $ligne->payload, true);
        $this->assertSame($nom, $charge['interrupteur'] ?? null);
        $this->assertSame($avant, $charge['avant'] ?? null, "l'état d'avant doit être consigné");
        $this->assertSame(! $avant, $charge['apres'] ?? null, "l'état d'après doit être consigné");
        $this->assertArrayHasKey('correlation_id', $charge);
    }

    /** La ligne écrite ne doit pas casser la chaîne HMAC du journal. */
    public function test_la_chaine_du_journal_reste_intacte_apres_bascule(): void
    {
        $nom = $this->premierInterrupteur();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/'.$nom, ['actif' => true])
            ->assertOk();

        // `verifyChain` rend null quand la chaîne est intacte, ou l'id de la première
        // ligne altérée. C'est donc `null` qu'on attend ici, pas `true`.
        $this->assertNull(
            app(AuditLogService::class)->verifyChain(0),
            'la chaîne du journal d’audit doit rester intacte après une bascule'
        );
    }

    /**
     * Une bascule dont on ne peut pas garder trace ne doit PAS avoir lieu : sinon la
     * caisse change de comportement et le journal ne le sait pas — exactement le cas
     * qu'on cherche à rendre impossible.
     */
    public function test_une_bascule_non_traçable_est_refusee_et_n_a_pas_lieu(): void
    {
        $nom = $this->premierInterrupteur();
        $avant = app(InterrupteurService::class)->valeur($nom);

        $double = $this->createMock(AuditLogService::class);
        $double->method('write')->willThrowException(new \RuntimeException('journal indisponible'));
        $this->app->instance(AuditLogService::class, $double);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/'.$nom, ['actif' => ! $avant])
            ->assertStatus(500);

        $this->assertSame(
            $avant,
            app(InterrupteurService::class)->valeur($nom),
            "l'interrupteur ne doit pas avoir bougé si la trace n'a pas pu être écrite"
        );
    }
}
