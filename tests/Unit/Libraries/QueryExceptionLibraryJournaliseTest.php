<?php

namespace Tests\Unit\Libraries;

use App\Libraries\QueryExceptionLibrary;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [DÉTAIL CACHÉ 2026-09-29] Une erreur de base de données masquée au client doit
 * laisser une trace au journal — sinon elle n'existe nulle part.
 *
 * Constaté en reproduisant le geste réel du caissier (produit + supplément libre)
 * sur une base MySQL en retard de migration : le caissier voyait « Une erreur de
 * base de données s'est produite », et le journal ne contenait rien. Le message
 * pilote (« Column 'line_type' not found ») aurait désigné la cause en une ligne.
 *
 * Ce banc fige les deux moitiés du contrat : le client ne reçoit toujours PAS le
 * détail technique hors debug, ET le journal le reçoit, lui.
 */
class QueryExceptionLibraryJournaliseTest extends TestCase
{
    private function exceptionSql(): QueryException
    {
        $pdo = new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'line_type' in 'field list'");
        $pdo->errorInfo = ['42S22', 1054, "Unknown column 'line_type' in 'field list'"];

        return new QueryException(
            'insert into `order_items` (`line_type`) values (?)',
            ['manual_supplement'],
            $pdo
        );
    }

    public function test_hors_debug_le_client_recoit_le_message_generique_et_le_journal_recoit_la_cause(): void
    {
        config(['app.debug' => false]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $contexte) {
                return str_contains($message, 'masquée au client')
                    && ($contexte['sqlstate'] ?? null) === '42S22'
                    && ($contexte['code'] ?? null) === 1054
                    && str_contains((string) ($contexte['pilote'] ?? ''), "Unknown column 'line_type'")
                    // La requête LIÉE (avec les valeurs) ne doit PAS partir au journal.
                    && ! isset($contexte['sql']);
            });

        $rendu = QueryExceptionLibrary::message($this->exceptionSql());

        $this->assertSame(trans('all.message.database_error_message'), $rendu);
        $this->assertStringNotContainsString('line_type', $rendu, 'Le client ne doit pas voir le schéma.');
    }

    public function test_en_debug_le_message_brut_est_rendu_et_toujours_journalise(): void
    {
        config(['app.debug' => true]);

        Log::shouldReceive('error')->once();

        $rendu = QueryExceptionLibrary::message($this->exceptionSql());

        $this->assertStringContainsString('line_type', $rendu);
    }

    /** La clé étrangère 1451 garde son message métier dédié — non-régression. */
    public function test_la_contrainte_de_cle_etrangere_garde_son_message_metier(): void
    {
        config(['app.debug' => false]);
        Log::shouldReceive('error')->never();

        $pdo = new \PDOException('fk');
        $pdo->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row'];
        $e = new QueryException('delete from items where id = ?', [1], $pdo);

        $this->assertSame(trans('all.message.resource_already_used'), QueryExceptionLibrary::message($e));
    }
}
