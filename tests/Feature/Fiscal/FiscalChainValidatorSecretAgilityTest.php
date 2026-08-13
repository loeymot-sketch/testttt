<?php

namespace Tests\Feature\Fiscal;

use App\Exceptions\FiscalChainCorruptedException;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalChainValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LE JUMEAU OUBLIÉ DE L'AGILITÉ DE CLÉ — celui qui BLOQUAIT l'ouverture du rapport Z.
 *
 * ── CE QUI S'EST PASSÉ EN PRODUCTION, MESURÉ ─────────────────────────────────────────────────
 * Le rapport Z n'a PAS PU S'OUVRIR PENDANT 17 JOURS : dernier Z clos le 2026-07-27, aucun depuis,
 * **189 ventes numérotées et 3 344,80 € hors de tout Z signé**, le compteur montant chaque jour.
 * Le filet de nuit journalisait `opened=0 skipped=0 failed=1` à chaque passage, avec
 * `FiscalChainCorruptedException … (window=500, errors=183)`.
 *
 * ── LA CAUSE : UN CORRECTIF POSÉ SUR UN SEUL DES DEUX VÉRIFICATEURS ──────────────────────────
 * Le 2026-08-08, `LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS` a appris à `AuditLogService::verifyChain`
 * qu'une ligne signée AVANT l'apparition de `FISCAL_AUDIT_SECRET_BRANCH_1` se reproduit avec le
 * secret par DÉFAUT, pas avec l'override — et qu'il faut donc essayer les secrets CONNUS.
 *
 * `FiscalChainValidator` — **celui qui garde l'ouverture du Z** — n'a jamais reçu ce correctif.
 * Constat qui a tranché : `grep -c candidateVerificationBranches` → **AuditLogService 2,
 * FiscalChainValidator 0**. D'où deux vérificateurs qui, sur les MÊMES données, rendaient des
 * verdicts OPPOSÉS : `fiscal:verify-chain --all` répondait « CHAIN OK » pendant que l'ouverture du
 * Z échouait sur 181 « altérations ».
 *
 * ⚠️ LA CHAÎNE N'ÉTAIT PAS CORROMPUE. Déjà mesuré et consigné (`AuditLogService:219-221`) :
 * 360 lignes se reproduisent avec le secret de branche, 423 avec le défaut, **aucune irréductible**,
 * chaînage `prev_hash` intact, aucun trou d'identifiant. C'était un FAUX POSITIF.
 *
 * ⚠️ HYPOTHÈSE TESTÉE ET ÉCARTÉE : ce n'était PAS un résidu de `php artisan config:cache` (piège
 * connu du 2026-08-12 qui rend `env()` nul). Vérifié en production : aucun `bootstrap/cache/config.php`,
 * et le validateur échouait quand même. Ne pas reprendre cette fausse piste.
 *
 * ── CE QUE CE BANC VERROUILLE ────────────────────────────────────────────────────────────────
 * Assouplir un détecteur d'altération fiscale ne doit JAMAIS devenir « accepter n'importe quoi ».
 * Ce banc éprouve donc les DEUX sens, et surtout l'ÉGALITÉ des deux vérificateurs — c'est cette
 * égalité, pas la tolérance elle-même, qui empêche le jumeau d'être oublié une seconde fois.
 */
class FiscalChainValidatorSecretAgilityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_BRANCHE_1 = 'secret-de-la-branche-1-suffisamment-long-0001';

    private const SECRET_DEFAUT = 'secret-par-defaut-suffisamment-long-000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        Config::set('fiscal.audit_secret', [
            0 => self::SECRET_DEFAUT,
            1 => self::SECRET_BRANCHE_1,
        ]);
        Config::set('fiscal.chain_validation_enabled', true);
    }

    private function signerPour(int $branche, ?string $prev, string $action, array $payload): string
    {
        $m = new ReflectionMethod(AuditLogService::class, 'computeHash');
        $m->setAccessible(true);

        return $m->invoke(app(AuditLogService::class), $branche, $prev, $action, $payload);
    }

    /** Insère une ligne de branche 1 signée avec le secret de notre choix. */
    private function inserer(int $brancheSignature, string $action, ?string $prev, ?string $hashImpose = null): string
    {
        $payload = ['n' => $action];
        $hash = $hashImpose ?? $this->signerPour($brancheSignature, $prev, $action, $payload);

        DB::table('audit_logs')->insert([
            'branch_id' => 1, 'user_id' => null, 'action' => $action, 'resource' => 'test',
            'resource_id' => null, 'payload' => json_encode($payload), 'prev_hash' => $prev,
            'current_hash' => $hash, 'ip' => null, 'user_agent' => null, 'session_id' => null,
            'created_at' => now(),
        ]);

        return $hash;
    }

    private function valider(): void
    {
        app(FiscalChainValidator::class)->assertChainIntegrity(1, 500);
    }

    /**
     * LE CŒUR — c'est exactement la situation des 423 lignes de production : la ligne porte la
     * branche 1, mais sa signature a été faite avec le secret par DÉFAUT (elle est antérieure à
     * l'override). Elle DOIT être acceptée : elle est authentique.
     */
    public function test_une_ligne_signee_avec_le_secret_par_defaut_n_empeche_plus_l_ouverture_du_Z(): void
    {
        $h1 = $this->inserer(1, 'apres_override', null);       // signée avec le secret de branche
        $this->inserer(0, 'avant_override', $h1);              // signée avec le secret par défaut

        $this->valider();

        $this->assertTrue(true, 'la chaîne est acceptée : le Z peut s\'ouvrir');
    }

    /** Le cas normal ne doit évidemment pas régresser. */
    public function test_une_ligne_signee_avec_le_secret_de_sa_branche_reste_acceptee(): void
    {
        $h1 = $this->inserer(1, 'a', null);
        $this->inserer(1, 'b', $h1);

        $this->valider();

        $this->assertTrue(true);
    }

    /**
     * ⛔ LA PROPRIÉTÉ ESSENTIELLE — la tolérance ne doit pas devenir « accepter n'importe quoi ».
     * Une signature qui ne se reproduit avec AUCUN secret connu reste une ALTÉRATION et doit
     * continuer de bloquer. Sans ce test, le correctif serait un trou de sécurité fiscal.
     */
    public function test_une_signature_forgee_reste_DETECTEE_et_bloque_toujours(): void
    {
        $h1 = $this->inserer(1, 'a', null);
        $this->inserer(1, 'b', $h1, hash_hmac('sha256', 'forge', 'secret-que-la-config-ignore-xyz'));

        $this->expectException(FiscalChainCorruptedException::class);
        $this->valider();
    }

    /**
     * ⛔ L'ATTAQUE PRÉCISE : LE SECRET D'UNE BRANCHE NE FORGE PAS POUR UNE AUTRE.
     *
     * C'est la vraie question que pose un assouplissement : élargir la liste des secrets acceptés
     * élargit-il aussi ce qu'un porteur de secret PARTIEL peut fabriquer ? On éprouve donc le cas
     * hostile le plus réaliste — une ligne qui se présente comme appartenant à la branche 2 mais
     * signée avec le secret de la branche 1, que l'attaquant possède.
     *
     * Elle DOIT être refusée : les candidats d'une ligne de branche 2 sont [2, 0], jamais 1.
     *
     * ⚠️ CE QUE CE BANC N'AFFIRME PAS, ET QU'IL FAUT DIRE : le porteur du secret par DÉFAUT peut,
     * lui, signer pour n'importe quelle branche — puisque `0` est candidat de TOUTE ligne. C'est
     * l'élargissement réel et assumé du LOCK du 2026-08-08, pas un oubli. En V1 mono-poste les deux
     * secrets vivent dans le même `.env` sur la même machine : qui tient l'un tient déjà l'autre.
     * Le jour d'un vrai multi-succursales, ce compromis doit être rejugé.
     */
    public function test_le_secret_d_une_autre_branche_ne_forge_PAS(): void
    {
        // La branche 2 a SON PROPRE secret : sans ça on éprouverait le refus « secret absent »
        // (une autre voie, qui masquerait la question posée) au lieu de l'usurpation elle-même.
        Config::set('fiscal.audit_secret', [
            0 => self::SECRET_DEFAUT,
            1 => self::SECRET_BRANCHE_1,
            2 => 'secret-de-la-branche-2-suffisamment-long-0003',
        ]);

        $h1 = $this->inserer(1, 'a', null);

        // Une ligne de branche 2, signée avec le secret de la branche 1.
        $payload = ['n' => 'usurpation'];
        $hash = $this->signerPour(1, $h1, 'usurpation', $payload);
        DB::table('audit_logs')->insert([
            'branch_id' => 2, 'user_id' => null, 'action' => 'usurpation', 'resource' => 'test',
            'resource_id' => null, 'payload' => json_encode($payload), 'prev_hash' => $h1,
            'current_hash' => $hash, 'ip' => null, 'user_agent' => null, 'session_id' => null,
            'created_at' => now(),
        ]);

        $this->expectException(FiscalChainCorruptedException::class);
        app(FiscalChainValidator::class)->assertChainIntegrity(2, 500);
    }

    /** Et un chaînage rompu reste détecté : on n'a touché qu'à la reproduction de signature. */
    public function test_un_chainage_rompu_reste_DETECTE(): void
    {
        $this->inserer(1, 'a', null);
        $this->inserer(1, 'b', 'un-prev-hash-qui-ne-pointe-rien');

        $this->expectException(FiscalChainCorruptedException::class);
        $this->valider();
    }

    /**
     * LA SENTINELLE QUI EMPÊCHE LE JUMEAU D'ÊTRE OUBLIÉ UNE TROISIÈME FOIS.
     *
     * Les deux vérificateurs doivent rendre le MÊME verdict sur les MÊMES données. C'est cette
     * égalité qui a manqué pendant 17 jours : l'un disait « CHAIN OK », l'autre « altérée ».
     */
    public function test_les_deux_verificateurs_rendent_le_meme_verdict(): void
    {
        $h1 = $this->inserer(1, 'apres_override', null);
        $this->inserer(0, 'avant_override', $h1);

        $auditDit = app(AuditLogService::class)->verifyChain(1);   // null = chaîne saine

        $validateurDit = null;
        try {
            $this->valider();
        } catch (FiscalChainCorruptedException $e) {
            $validateurDit = $e->getMessage();
        }

        $this->assertNull($auditDit, 'le vérificateur historique voit la chaîne saine');
        $this->assertNull($validateurDit,
            'DÉSACCORD ENTRE LES DEUX VÉRIFICATEURS : le Z sera bloqué alors que la chaîne est saine');
    }
}
