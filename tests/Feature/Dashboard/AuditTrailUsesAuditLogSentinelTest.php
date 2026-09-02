<?php

namespace Tests\Feature\Dashboard;

use App\Models\ActionLog;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GAP-FIX-02 2026-05-25] Sentinel: DashboardService::auditTrail() MUST
 * source from NF525 hash-chained `audit_logs` (App\Models\AuditLog), NOT
 * from the generic non-chained `action_logs` (App\Models\ActionLog).
 *
 * Background: AuditTrailComponent.vue dashboard widget historically queried
 * ActionLog, which has no prev_hash/current_hash columns and is mutable.
 * It actively misled inspectors by claiming to display the "audit trail"
 * while showing unsigned events. Gap-Hunt Phase E heal #2 (B1-S7-P5 P0)
 * rewired it to AuditLog.
 *
 * Discriminator: ActionLog has no `current_hash`; AuditLog does. Every row
 * returned by DashboardService::auditTrail() MUST expose a non-empty
 * `hash_prefix` field — that field can only come from AuditLog.
 */
class AuditTrailUsesAuditLogSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // [2026-09-02] `DashboardService::dashboardBranchId()` abort(403) sur un
        // `branch_id = 0` SANS rôle Admin — garde anti-fail-open posé après l'écriture
        // de ces tests, qui supposaient encore « branch_id 0 ⇒ admin ». Il faut donc un
        // vrai rôle Spatie. Le garde est juste : on corrige la fixture, pas le garde.
        $this->seedSpatieRoles();
    }


    /**
     * Source verification: returned items expose chain-integrity hash prefix.
     */
    public function test_audit_trail_returns_hash_prefix_field(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'action' => 'gap_fix_02.sentinel.write',
            'resource' => 'order',
            'resource_id' => 12345,
            'payload' => ['proof' => 'from_audit_logs'],
        ]);

        $rows = app(DashboardService::class)->auditTrail();

        $this->assertGreaterThan(0, $rows->count(), 'auditTrail should return at least the row we just wrote');

        $first = $rows->first();
        $this->assertArrayHasKey('hash_prefix', $first, 'Payload MUST include hash_prefix — only AuditLog has current_hash');
        $this->assertNotEmpty($first['hash_prefix'], 'hash_prefix MUST be non-empty (proves chain row exists)');
        $this->assertSame(8, strlen($first['hash_prefix']), 'hash_prefix MUST be 8-char SHA-256 prefix per spec');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $first['hash_prefix'], 'hash_prefix MUST be hex (NF525 SHA-256 chain proof)');
    }

    /**
     * Source verification: ActionLog rows MUST NOT leak into the widget.
     * If the implementation regresses to ActionLog, this test breaks because
     * ActionLog rows have no current_hash column.
     */
    public function test_audit_trail_does_not_surface_action_log_rows(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // Insert one ActionLog row with a recognisable action string.
        ActionLog::create([
            'user_id' => $admin->id,
            'branch_id' => $branch->id,
            'action' => 'GAP_FIX_02_ACTIONLOG_ONLY_MARKER',
            'resource' => 'should_not_appear',
            'details' => 'If this string shows up in auditTrail output, the widget regressed to ActionLog.',
        ]);

        // And one AuditLog row through the legitimate service.
        app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'action' => 'gap_fix_02.legit.audit',
            'payload' => [],
        ]);

        $rows = app(DashboardService::class)->auditTrail();

        $actions = $rows->pluck('action')->all();
        $this->assertNotContains(
            'GAP_FIX_02_ACTIONLOG_ONLY_MARKER',
            $actions,
            'auditTrail leaked an ActionLog-only marker action → widget is querying the wrong table.'
        );
        $this->assertContains(
            'gap_fix_02.legit.audit',
            $actions,
            'auditTrail did not surface the AuditLog row → widget is no longer reading audit_logs.'
        );

        // Every returned row MUST have a populated hash_prefix.
        foreach ($rows as $row) {
            $this->assertArrayHasKey('hash_prefix', $row);
            $this->assertNotEmpty($row['hash_prefix'], 'Every row MUST carry a chain hash prefix — proves audit_logs source.');
        }
    }

    /**
     * Payload shape contract: the new payload MUST keep backward-compat
     * keys the Vue component already renders (user_name, action, resource,
     * time, branch_id, id) AND ship the new hash_prefix field.
     */
    public function test_audit_trail_payload_shape_is_stable(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['name' => 'Admin Sentinel', 'branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'action' => 'gap_fix_02.shape.contract',
            'resource' => 'cash_drawer',
            'resource_id' => 7,
            'payload' => ['amount' => 50, 'currency' => 'EUR'],
        ]);

        $first = app(DashboardService::class)->auditTrail()->first();

        // Backward-compat keys (Vue template depends on them).
        foreach (['id', 'user_name', 'branch_id', 'action', 'resource', 'time'] as $key) {
            $this->assertArrayHasKey($key, $first, "Missing legacy key `{$key}` — Vue component breaks without it.");
        }

        // New NF525 keys.
        $this->assertArrayHasKey('hash_prefix', $first);
        $this->assertArrayHasKey('payload_keys', $first);

        // Resource reference includes resource_id when present (regression
        // catch: the old ActionLog payload only had `resource`).
        $this->assertSame('cash_drawer#7', $first['resource']);

        // user_name resolves to the user we just authenticated as, not the
        // generic 'Système' fallback (proves the service-side User::whereIn
        // lookup works).
        $this->assertSame('Admin Sentinel', $first['user_name']);
    }

    /**
     * [2026-09-02] Un journal d'audit sert à répondre à « qui ». Il répondait « Système »
     * pour 152 lignes portant pourtant un `user_id` renseigné — dont 19 ouvertures de caisse,
     * 19 fermetures et 9 mouvements d'espèces. Cause : `$log->user_id && isset($users[...])
     * ? nom : 'Système'` faisait retomber un acteur NON RÉSOLU sur la même étiquette qu'un
     * acteur réellement absent. La ligne fiscale, elle, était intacte : `user_id` conservé,
     * table en insertion seule. C'était l'AFFICHAGE qui inventait un acteur.
     */
    public function test_un_acteur_supprime_n_est_pas_maquille_en_systeme(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $auteur = User::factory()->create(['branch_id' => 0, 'name' => 'Caissière Dupont']);
        $this->actingAs($admin);

        app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'user_id' => $auteur->id,
            'action' => 'cash.session.opened',
            'resource' => 'cash_drawer_session',
            'resource_id' => 4242,
            'payload' => [],
        ]);

        $auteur->delete(); // suppression douce : le compte part, la trace reste

        $ligne = app(DashboardService::class)->auditTrail()
            ->firstWhere('action', 'cash.session.opened');

        $this->assertNotNull($ligne, "la ligne d'audit doit rester lisible après suppression du compte");
        $this->assertNotSame('Système', $ligne['user_name'], "un acteur identifié ne doit jamais être maquillé en « Système »");
        $this->assertStringContainsString('Caissière Dupont', $ligne['user_name']);
        $this->assertStringContainsString('supprimé', $ligne['user_name']);
    }

    /** Un acteur dont le compte n'existe plus du tout doit être nommé par son identifiant. */
    public function test_un_acteur_introuvable_est_nomme_par_son_identifiant(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'user_id' => 999123,
            'action' => 'cash.movement.recorded',
            'resource' => 'cash_movement',
            'resource_id' => 7,
            'payload' => [],
        ]);

        $ligne = app(DashboardService::class)->auditTrail()
            ->firstWhere('action', 'cash.movement.recorded');

        $this->assertNotNull($ligne);
        $this->assertSame('Utilisateur #999123 (compte supprimé)', $ligne['user_name']);
    }

    /**
     * Contrôle apparié : une écriture réellement sans acteur reste « Système ».
     *
     * [2026-09-02] Attention à l'instrument : `AuditLogService::write()` retombe sur
     * `auth()->id()` quand `user_id` est nul. Écrire en étant authentifié ne produit donc
     * PAS une ligne sans acteur — ma première version de ce test s'est fait piéger et a
     * lu le nom de l'admin connecté. On écrit avant de s'authentifier.
     */
    public function test_une_ecriture_sans_acteur_reste_systeme(): void
    {
        $branch = Branch::factory()->create();

        app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'user_id' => null,
            'action' => 'outbox.replayed',
            'resource' => 'domain_event',
            'resource_id' => 3,
            'payload' => [],
        ]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $ligne = app(DashboardService::class)->auditTrail()
            ->firstWhere('action', 'outbox.replayed');

        $this->assertNotNull($ligne);
        $this->assertSame('Système', $ligne['user_name']);
    }

    /**
     * [2026-09-02 · Sub 3.4 · Codex P1-I] « il y a 3 heures » ne se recoupe avec rien.
     *
     * Pour un contrôle, la seule question utile est « à quelle heure exactement, et sur
     * quelle branche ». Le widget ne rendait que `diffForHumans()` : impossible de
     * rapprocher une ligne d'audit d'un ticket, d'un Z ou d'une capture d'écran. La date
     * exacte est désormais publiée à côté de la formulation relative.
     */
    public function test_chaque_ligne_porte_l_horodatage_exact_et_la_branche(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        app(AuditLogService::class)->write([
            'branch_id' => 0,
            'user_id' => $admin->id,
            'action' => 'test.horodatage',
            'resource' => 'cash_drawer',
            'resource_id' => 7,
            'payload' => ['x' => 1],
        ]);

        $ligne = app(DashboardService::class)->auditTrail()->first();

        $this->assertArrayHasKey('created_at', $ligne, "l'horodatage exact doit être publié");
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string) $ligne['created_at'],
            "l'horodatage doit être une date complète, pas une formulation relative"
        );
        $this->assertArrayHasKey('branch_id', $ligne, 'la branche doit rester lisible sur chaque ligne');
        $this->assertArrayHasKey('time', $ligne, 'la formulation relative reste, à côté de la date exacte');
    }
}
