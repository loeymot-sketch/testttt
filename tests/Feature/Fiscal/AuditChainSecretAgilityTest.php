<?php

namespace Tests\Feature\Fiscal;

use App\Models\AuditLog;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * [LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS 2026-08-08] Agilité de clé à la VÉRIFICATION de la
 * chaîne d'audit NF525.
 *
 * Contexte mesuré sur la production (783 lignes, recalcul en lecture seule) : 360 signatures se
 * reproduisent avec le secret de leur branche, **423 avec le secret par défaut**, et **aucune
 * n'est irréductible**. Chaînage intact, aucun trou d'id : la chaîne n'a jamais été altérée.
 * `fiscal:verify-chain` annonçait pourtant « TAMPER id=56 » depuis six semaines — parce qu'il
 * s'arrête à la PREMIÈRE ligne qu'il ne sait pas reproduire.
 *
 * Ce que cette suite verrouille, et dans cet ordre d'importance :
 *
 *   1. **Une altération RÉELLE reste détectée.** C'est la seule propriété qui compte : accepter
 *      plusieurs secrets connus ne doit jamais devenir « accepter n'importe quoi ». Trois
 *      formes d'atteinte sont couvertes (charge utile, signature, chaînage) plus le cas d'un
 *      secret inconnu. Prouvé par mutation dans le rapport du LOCK.
 *   2. Une ligne signée avec le secret de sa branche est acceptée (cas normal).
 *   3. Une ligne signée avec le secret par DÉFAUT alors qu'elle porte une branche est acceptée
 *      (les 423 lignes historiques) — sinon leur intégrité reste improuvable devant un tiers.
 *
 * NOTE DÉCOUVERTE EN ÉCRIVANT CETTE SUITE : un DÉCLENCHEUR de base de données refuse tout
 * `UPDATE` sur `audit_logs` (« audit_logs is INSERT-only (NF525) »). L'append-only est donc
 * garanti par la base elle-même, pas seulement par le modèle — protection plus forte que
 * supposée. Conséquence pour ces tests : on ne peut pas modifier une ligne existante, on FORGE
 * donc des lignes à l'insertion dont la signature ne correspond pas à leur contenu. Du point de
 * vue du vérificateur, c'est exactement équivalent — et c'est le seul modèle de menace qui reste
 * praticable : insérer, pas réécrire.
 */
class AuditChainSecretAgilityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_BRANCHE_1 = 'secret-de-la-branche-1-suffisamment-long-0001';
    private const SECRET_DEFAUT = 'secret-par-defaut-suffisamment-long-000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        // Forme tableau : deux secrets DISTINCTS, sans passer par env() (que `config:cache`
        // rendrait inopérant en test). Branche 0 = le défaut, branche 1 = son propre secret.
        Config::set('fiscal.audit_secret', [
            0 => self::SECRET_DEFAUT,
            1 => self::SECRET_BRANCHE_1,
        ]);
    }

    private function service(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    /** Calcule une signature comme le ferait la signature réelle, pour une branche donnée. */
    private function signerPour(int $branche, ?string $prev, string $action, array $payload): string
    {
        $m = new ReflectionMethod(AuditLogService::class, 'computeHash');
        $m->setAccessible(true);

        return $m->invoke($this->service(), $branche, $prev, $action, $payload);
    }

    /**
     * Insère une ligne en SQL direct, signée avec la branche de notre choix — c'est ainsi qu'on
     * reproduit fidèlement les 423 lignes historiques (branche 1 sur la ligne, secret du défaut
     * dans la signature).
     */
    private function insererLigne(int $brancheLigne, int $brancheSignature, string $action, array $payload, ?string $prev): string
    {
        $hash = $this->signerPour($brancheSignature, $prev, $action, $payload);

        DB::table('audit_logs')->insert([
            'branch_id' => $brancheLigne,
            'user_id' => null,
            'action' => $action,
            'resource' => 'test',
            'resource_id' => null,
            'payload' => json_encode($payload),
            'prev_hash' => $prev,
            'current_hash' => $hash,
            'ip' => null,
            'user_agent' => null,
            'session_id' => null,
            'created_at' => now(),
        ]);

        return $hash;
    }

    /** Insère une ligne avec une signature IMPOSÉE — pour forger un cas d'altération. */
    private function insererBrut(int $branche, string $action, array $payload, ?string $prev, string $hash): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $branche, 'user_id' => null, 'action' => $action, 'resource' => 'test',
            'resource_id' => null, 'payload' => json_encode($payload),
            'prev_hash' => $prev, 'current_hash' => $hash,
            'ip' => null, 'user_agent' => null, 'session_id' => null, 'created_at' => now(),
        ]);
    }

    // =====================================================================
    // (1) LA PROPRIÉTÉ ESSENTIELLE — une altération réelle reste détectée
    // =====================================================================

    public function test_une_charge_utile_qui_ne_correspond_pas_a_sa_signature_est_DETECTEE(): void
    {
        // Signature calculée sur { montant: 10 }, contenu écrit { montant: 9999 } : c'est ce que
        // produirait une réécriture réussie, et le vérificateur doit le voir.
        $hash = $this->signerPour(1, null, 'test.action', ['montant' => 10]);
        $this->insererBrut(1, 'test.action', ['montant' => 9999], null, $hash);

        $this->assertNotNull(
            $this->service()->verifyChain(1),
            'charge utile ne correspondant pas à la signature : DOIT être détectée — sinon la '
            .'chaîne ne sert plus à rien'
        );
    }

    public function test_une_signature_arbitraire_est_DETECTEE(): void
    {
        $this->insererBrut(1, 'test.action', ['montant' => 10], null, str_repeat('a', 64));

        $this->assertNotNull($this->service()->verifyChain(1), 'signature arbitraire DOIT être détectée');
    }

    public function test_un_chainage_qui_ne_pointe_pas_la_ligne_precedente_est_DETECTE(): void
    {
        $this->insererLigne(1, 1, 'test.un', ['n' => 1], null);
        // 2ᵉ ligne signée cohérente avec un prev FAUX : la signature se reproduit, mais le
        // maillon ne pointe pas la ligne précédente. Le chaînage doit être vérifié pour lui-même.
        $faux = str_repeat('b', 64);
        $this->insererBrut(1, 'test.deux', ['n' => 2], $faux, $this->signerPour(1, $faux, 'test.deux', ['n' => 2]));

        $this->assertNotNull($this->service()->verifyChain(1), 'chaînage rompu DOIT être détecté');
    }

    public function test_une_ligne_signee_avec_un_secret_INCONNU_est_DETECTEE(): void
    {
        // Signée avec la branche 2, dont le secret n'existe pas dans la configuration : la
        // signature ne se reproduit donc avec AUCUN secret connu.
        $inconnu = hash_hmac('sha256', 'peu importe', 'secret-que-la-config-ne-connait-pas-xyz');

        DB::table('audit_logs')->insert([
            'branch_id' => 1, 'user_id' => null, 'action' => 'test.inconnu', 'resource' => 'test',
            'resource_id' => null, 'payload' => json_encode(['n' => 1]),
            'prev_hash' => null, 'current_hash' => $inconnu,
            'ip' => null, 'user_agent' => null, 'session_id' => null, 'created_at' => now(),
        ]);

        $this->assertNotNull(
            $this->service()->verifyChain(1),
            'un secret hors configuration DOIT rester une altération'
        );
    }

    // =====================================================================
    // (2) et (3) — les deux secrets connus sont acceptés
    // =====================================================================

    public function test_le_secret_de_la_branche_est_accepte(): void
    {
        $this->insererLigne(1, 1, 'test.branche', ['n' => 1], null);

        $this->assertNull($this->service()->verifyChain(1), 'cas normal : doit être accepté');
    }

    /** Le cas des 423 lignes : branche 1 sur la ligne, secret par DÉFAUT dans la signature. */
    public function test_le_secret_par_DEFAUT_est_accepte_sur_une_ligne_de_branche(): void
    {
        $this->insererLigne(1, 0, 'test.historique', ['n' => 1], null);

        $this->assertNull(
            $this->service()->verifyChain(1),
            'ligne historique signée avec le défaut : doit être acceptée, sinon son intégrité '
            .'reste improuvable devant un contrôle'
        );
    }

    /** Chaîne mixte, comme la production : les deux secrets cohabitent et tout se vérifie. */
    public function test_une_chaine_MIXTE_se_verifie_entierement(): void
    {
        $h1 = $this->insererLigne(1, 1, 'test.a', ['n' => 1], null);        // secret de branche
        $h2 = $this->insererLigne(1, 0, 'test.b', ['n' => 2], $h1);        // secret par défaut
        $h3 = $this->insererLigne(1, 0, 'test.c', ['n' => 3], $h2);        // défaut
        $this->insererLigne(1, 1, 'test.d', ['n' => 4], $h3);              // branche

        $this->assertSame(4, AuditLog::withoutGlobalScopes()->count(), 'garde : 4 lignes écrites');
        $this->assertNull($this->service()->verifyChain(1), 'chaîne mixte : entièrement vérifiable');
    }

    /**
     * Et la tolérance ne doit pas masquer une altération au MILIEU d'une chaîne mixte : c'est le
     * cas le plus proche de la production, et le plus facile à laisser passer par inadvertance.
     */
    public function test_une_alteration_au_milieu_d_une_chaine_mixte_est_DETECTEE(): void
    {
        $h1 = $this->insererLigne(1, 1, 'test.a', ['n' => 1], null);
        // Ligne du MILIEU forgée : signée (avec le défaut) sur { n: 2 }, contenu { n: 222 }.
        $h2 = $this->signerPour(0, $h1, 'test.b', ['n' => 2]);
        $this->insererBrut(1, 'test.b', ['n' => 222], $h1, $h2);
        $this->insererLigne(1, 1, 'test.c', ['n' => 3], $h2);

        $this->assertNotNull(
            $this->service()->verifyChain(1),
            'altération au milieu d\'une chaîne mixte DOIT être détectée'
        );
    }
}
