<?php

namespace Tests\Feature\Pilotage;

use App\Models\User;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL G3 2026-09-03 · T3.1 · défaut V-07]
 *
 * Un journal d'audit n'a de valeur que s'il ne peut pas devancer l'événement qu'il
 * atteste.
 *
 * La bascule d'un interrupteur écrivait sa ligne dans `audit_logs` — le journal chaîné
 * HMAC, append-only, dont la suppression est refusée par un déclencheur SQL — PUIS
 * appliquait la bascule. Entre les deux, l'écriture en base de
 * `InterrupteurService::regler()` peut échouer : base verrouillée, délai dépassé,
 * `QueryException`. Le seul `catch` du contrôleur n'attrapait que
 * `InvalidArgumentException` : la panne remontait telle quelle en 500, et la ligne
 * d'audit restait.
 *
 * Cette ligne affirme alors un changement qui n'a jamais eu lieu, et comme la table est
 * chaînée et append-only, elle ne peut plus jamais être retirée : elle reste six ans.
 * Le jour d'un contrôle, c'est une preuve fausse qu'on ne peut pas rétracter.
 *
 * Ce banc fixe l'ordre : le fait d'abord, la preuve ensuite — et rien de retenu si l'un
 * des deux manque.
 */
class InterrupteurAuditApresMutationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = 'pilotage.interrupteur.bascule';

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
        $etat = (new InterrupteurService())->etat();
        $this->assertNotEmpty($etat, 'le catalogue des interrupteurs ne doit pas être vide');

        return (string) $etat[0]['nom'];
    }

    /** Le nombre de lignes d'audit décrivant une bascule d'interrupteur. */
    private function lignesDeBascule(): int
    {
        return (int) DB::table('audit_logs')->where('action', self::ACTION)->count();
    }

    /**
     * Une panne d'écriture en base pendant `regler()` : une `QueryException`, exactement
     * ce que produit un verrou, un délai dépassé ou une base indisponible.
     */
    private function panneDeBase(): QueryException
    {
        return new QueryException(
            'update "settings" set "payload" = ? where "group" = ? and "key" = ?',
            ['false', 'pilotage', 'split_payment.enabled'],
            new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked')
        );
    }

    /**
     * CAS 1 — la bascule échoue : AUCUNE trace ne doit affirmer qu'elle a eu lieu.
     *
     * C'est le cas qui rougit avant correctif : la ligne d'audit était déjà écrite quand
     * `regler()` explosait.
     */
    public function test_une_bascule_qui_echoue_ne_laisse_aucune_ligne_d_audit(): void
    {
        $nom = $this->premierInterrupteur();
        $avant = (new InterrupteurService())->valeur($nom);
        $lignesAvant = $this->lignesDeBascule();

        $double = \Mockery::mock(InterrupteurService::class)->makePartial();
        $double->shouldReceive('regler')->andThrow($this->panneDeBase());
        $this->app->instance(InterrupteurService::class, $double);

        $reponse = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/'.$nom, ['actif' => ! $avant]);

        $this->app->forgetInstance(InterrupteurService::class);

        $this->assertTrue(
            $reponse->status() >= 400,
            'une bascule qui échoue doit répondre une erreur, pas un succès (reçu : '.$reponse->status().')'
        );

        $this->assertSame(
            $lignesAvant,
            $this->lignesDeBascule(),
            'une bascule qui n’a pas eu lieu ne doit laisser AUCUNE ligne dans audit_logs : '
            .'la table est chaînée et append-only, une ligne fausse y reste six ans'
        );

        $this->assertSame(
            $avant,
            (new InterrupteurService())->valeur($nom),
            'l’interrupteur ne doit pas avoir bougé quand son écriture a échoué'
        );

        $this->assertDatabaseMissing('settings', [
            'group' => 'pilotage',
            'key'   => InterrupteurService::CATALOGUE[$nom]['cle'],
        ]);
    }

    /**
     * CAS 2 — bascule réussie : exactement UNE ligne, et elle dit la vérité.
     *
     * La valeur consignée est celle RELUE après application, pas celle demandée : c'est
     * la seule qui atteste de l'état réel du système.
     */
    public function test_une_bascule_reussie_laisse_exactement_une_ligne_fidele(): void
    {
        $nom = $this->premierInterrupteur();
        $avant = (new InterrupteurService())->valeur($nom);
        $lignesAvant = $this->lignesDeBascule();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Correlation-Id' => 'g3-correlation-1'])
            ->putJson('/api/admin/observability/interrupteurs/'.$nom, ['actif' => ! $avant])
            ->assertOk();

        $this->assertSame(
            $lignesAvant + 1,
            $this->lignesDeBascule(),
            'une bascule réussie doit laisser EXACTEMENT une ligne'
        );

        $ligne = DB::table('audit_logs')->where('action', self::ACTION)->orderByDesc('id')->first();
        $this->assertNotNull($ligne);
        $this->assertSame((int) $admin->id, (int) $ligne->user_id, 'l’acteur doit être nommé');
        $this->assertSame((int) $admin->branch_id, (int) $ligne->branch_id, 'la branche doit être consignée');

        $charge = json_decode((string) $ligne->payload, true);
        $this->assertSame($nom, $charge['interrupteur'] ?? null);
        $this->assertSame($avant, $charge['avant'] ?? null, 'l’état d’avant doit être consigné');
        $this->assertSame(! $avant, $charge['apres'] ?? null, 'l’état d’après doit être consigné');
        $this->assertSame('g3-correlation-1', $charge['correlation_id'] ?? null);

        // La valeur consignée est celle que le système renvoie ensuite : relue, pas
        // supposée. Sans cela, l'audit atteste d'une intention, pas d'un fait.
        $this->assertSame(
            (new InterrupteurService())->valeur($nom),
            $charge['apres'] ?? null,
            'la valeur consignée doit être celle RÉELLEMENT appliquée, relue après la bascule'
        );
    }

    /**
     * Même classe de défaut : un nom hors catalogue est refusé par `regler()` — mais
     * l'audit était déjà écrit, et affirmait la bascule d'un interrupteur qui n'existe
     * pas.
     */
    public function test_un_interrupteur_inconnu_ne_laisse_aucune_ligne_d_audit(): void
    {
        $lignesAvant = $this->lignesDeBascule();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/interrupteur-qui-n-existe-pas', ['actif' => true])
            ->assertStatus(404);

        $this->assertSame(
            $lignesAvant,
            $this->lignesDeBascule(),
            'un interrupteur inconnu ne doit laisser aucune trace d’une bascule qui ne peut pas exister'
        );
    }
}
