<?php

namespace Tests\Feature\Fiscal;

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [P1-1 NF525 2026-07-18] Réutilisation de numéros fiscaux après HARD-delete.
 *
 * FiscalSequenceService::next() (FROZEN) alloue le prochain numéro via
 * `MAX(fiscal_sequence_no)+1` sur `orders` avec `->withTrashed()`. Les
 * SOFT-deletes sont donc couverts, MAIS un HARD-delete d'un order fiscalisé
 * fait redescendre le MAX → réémission d'un numéro déjà gravé dans la chaîne
 * signée (preuve : seq 2579 revendiqué par 6 orders). Il existait déjà un
 * trigger `order_payments_no_delete` mais AUCUN sur `orders` lui-même.
 *
 * Ce test verrouille le trigger `orders_no_delete_when_fiscalized`
 * (migration 2026_07_18_130000), parité SQLite :memory: du SIGNAL MySQL prod :
 *   (a) order AVEC fiscal_sequence_no → hard-delete REJETÉ (QueryException) ;
 *   (b) order SANS fiscal_sequence_no (NULL) → hard-delete OK (les commandes
 *       de test NON encaissées restent purgeables — « purge 186 cmd test ») ;
 *   (c) le trigger de parité est bien présent après les migrations.
 */
class OrdersNoDeleteWhenFiscalizedTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_delete_of_a_fiscalized_order_is_rejected(): void
    {
        // Order encaissé : un numéro fiscal lui a été alloué et gravé.
        $order = Order::factory()->create(['fiscal_sequence_no' => 2579]);
        $id = $order->id;

        $rejected = false;
        try {
            // Raw DELETE — bypasse Eloquent/SoftDeletes : c'est le vecteur exact
            // (purge SQL, forceDelete, FK CASCADE) que le trigger doit bloquer.
            DB::table('orders')->where('id', $id)->delete();
        } catch (QueryException $e) {
            $rejected = true;
            $this->assertStringContainsStringIgnoringCase(
                'fiscal',
                $e->getMessage(),
                'Le message de rejet doit désigner l\'invariant fiscal NF525. Reçu : ' . $e->getMessage()
            );
        }

        $this->assertTrue(
            $rejected,
            'Le HARD-delete d\'un order fiscalisé a silencieusement réussi — '
            . 'le trigger orders_no_delete_when_fiscalized est absent ou permet le bypass. '
            . 'Réutilisation de numéro fiscal = FAUX VERT NF525.'
        );

        // Défense en profondeur : la ligne fiscale doit toujours exister.
        $this->assertTrue(
            DB::table('orders')->where('id', $id)->exists(),
            "L'order fiscalisé id={$id} a disparu malgré le rejet du DELETE."
        );
    }

    public function test_hard_delete_of_a_non_fiscalized_order_is_allowed(): void
    {
        // Order NON encaissé : fiscal_sequence_no NULL (défaut factory).
        $order = Order::factory()->create();
        $id = $order->id;

        $this->assertNull(
            DB::table('orders')->where('id', $id)->value('fiscal_sequence_no'),
            'Précondition : la commande de test ne doit pas porter de numéro fiscal.'
        );

        // Aucune exception attendue : le trigger ne fire que si fiscal_sequence_no
        // IS NOT NULL. Les commandes de test purgeables restent supprimables.
        DB::table('orders')->where('id', $id)->delete();

        $this->assertFalse(
            DB::table('orders')->where('id', $id)->exists(),
            'Un order NON fiscalisé (seq NULL) doit rester hard-deletable.'
        );
    }

    public function test_parity_trigger_is_present_after_migrations(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'Vérification de présence spécifique à SQLite (sqlite_master). '
                . 'La parité MySQL prod est garantie par la migration + fiscal:verify-immutability-triggers.'
            );
        }

        $present = DB::select(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name = ?",
            ['orders_no_delete_when_fiscalized']
        );

        $this->assertNotEmpty(
            $present,
            'Le trigger de parité SQLite orders_no_delete_when_fiscalized est absent de sqlite_master — '
            . "l'invariant NF525 serait un FAUX VERT en PHPUnit. Vérifier la branche sqlite de "
            . '2026_07_18_130000_add_orders_no_delete_when_fiscalized_trigger.php.'
        );
    }
}
