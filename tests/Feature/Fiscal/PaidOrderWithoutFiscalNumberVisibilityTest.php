<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ANGLE MORT NF525 2026-08-08] Une vente PAYÉE sans numéro fiscal était INVISIBLE du contrôle.
 *
 * `fiscal:verify-sequence-continuity` ne scannait que les commandes qui PORTENT un numéro
 * (`whereNotNull('fiscal_sequence_no')`). Une vente payée dont le numéro est resté NULL lui
 * échappait donc par construction : la séquence restait parfaitement contiguë, la commande
 * annonçait « GAP-FREE, OK », et 13 ventes payées en production (127,20 €) ne portaient aucun
 * numéro — dont la commande #333, 1,90 € encaissés le 2026-08-03, restée CINQ JOURS inaperçue.
 *
 * Une vente sans numéro n'est pas un trou DANS la séquence : c'est une vente HORS séquence. Le
 * contrôle ne savait pas dire cette phrase-là.
 *
 * Pourquoi la commande n'ATTRIBUE pas le numéro manquant : le webhook Mollie refuse
 * explicitement d'improviser un chemin fiscal (`mollie.webhook.fiscal_finalize_noop` — le verrou
 * « borne » de `finalizePaidKioskOrder` rend l'appel inopérant pour une commande web pure, et son
 * élargissement est un point d'activation propriétaire). Attribuer un numéro depuis un outil de
 * diagnostic serait contourner cette décision en silence. Ce contrôle RAPPORTE, il ne RÉPARE PAS
 * — et le troisième test ci-dessous est là pour que personne ne « l'améliore » dans ce sens.
 */
class PaidOrderWithoutFiscalNumberVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function commande(array $extra = []): Order
    {
        $branch = Branch::factory()->create();
        $client = User::factory()->create(['branch_id' => 0]);

        return Order::factory()->create($extra + [
            'branch_id' => $branch->id,
            'user_id' => $client->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PENDING,
            'total' => 1.90,
            'source_surface' => 'web',
            'fiscal_sequence_no' => null,
        ]);
    }

    /** LE DÉFAUT : la vente payée sans numéro doit désormais faire ÉCHOUER le contrôle. */
    public function test_une_vente_payee_sans_numero_fiscal_fait_ECHOUER_le_controle(): void
    {
        $o = $this->commande();

        $this->artisan('fiscal:verify-sequence-continuity')
            ->expectsOutputToContain('VENTES PAYÉES SANS NUMÉRO FISCAL')
            ->assertExitCode(1);

        // Le pire cas — encaissée ET jamais promue — doit être nommé séparément : le client a payé
        // et n'a jamais été servi. C'est l'information qui déclenche une action commerciale.
        $this->artisan('fiscal:verify-sequence-continuity')
            ->expectsOutputToContain('JAMAIS PROMUES')
            ->assertExitCode(1);

        $this->assertNotNull($o->fresh(), 'garde de cohérence du banc');
    }

    /**
     * GARDE ANTI-TEST-VIDE : sans elle, une commande qui échouerait TOUJOURS passerait le test
     * ci-dessus pour la mauvaise raison. Une base saine doit rendre SUCCÈS.
     */
    public function test_une_base_saine_rend_toujours_SUCCES(): void
    {
        $branch = Branch::factory()->create();
        $client = User::factory()->create(['branch_id' => 0]);

        // Une vente payée CORRECTEMENT numérotée : elle ne doit rien déclencher.
        Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $client->id,
            'payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::PENDING,
            'total' => 9.90, 'source_surface' => 'web', 'fiscal_sequence_no' => 1,
        ]);

        $this->artisan('fiscal:verify-sequence-continuity')->assertExitCode(0);
    }

    /**
     * LA PROPRIÉTÉ À NE JAMAIS PERDRE : le contrôle est en LECTURE SEULE. Attribuer un numéro
     * depuis un outil de diagnostic contournerait en silence une décision propriétaire.
     */
    public function test_le_controle_n_ATTRIBUE_JAMAIS_le_numero_manquant(): void
    {
        $o = $this->commande();
        $statutAvant = (int) $o->status;

        $this->artisan('fiscal:verify-sequence-continuity')->assertExitCode(1);

        $apres = $o->fresh();
        $this->assertNull($apres->fiscal_sequence_no,
            'un outil de diagnostic ne doit JAMAIS attribuer un numéro fiscal — c\'est une décision humaine');
        $this->assertSame($statutAvant, (int) $apres->status,
            'le contrôle ne doit pas non plus promouvoir la commande');
    }

    /**
     * Une vente IMPAYÉE sans numéro est NORMALE (le numéro s'attribue au paiement) : la lever
     * en alarme noierait le signal utile sous du bruit, et l'alarme finirait ignorée.
     */
    public function test_une_vente_IMPAYEE_sans_numero_ne_declenche_RIEN(): void
    {
        $this->commande(['payment_status' => PaymentStatus::UNPAID]);

        $this->artisan('fiscal:verify-sequence-continuity')->assertExitCode(0);
    }
}
