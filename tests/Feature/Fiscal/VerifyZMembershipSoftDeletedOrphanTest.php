<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [SELF-AUDIT B 2026-07-05] `fiscal:verify-z-membership` est le contrôle compensateur NF525 qui
 * prouve « chaque reçu numéroté est dans exactement un Z signé ». Il interrogeait les commandes avec
 * `withoutGlobalScope(BranchScope)` SEUL → le SoftDeletingScope restait actif → une commande fiscalisée
 * SOFT-DELETÉE (archivage/correction/rétention, exactement ce que ZReportService::aggregate agrège via
 * ->withTrashed()) était INVISIBLE au contrôle → « Z OK » à tort. Ce test verrouille l'alignement de la
 * population de l'auditeur sur celle de l'agrégateur (withTrashed).
 */
class VerifyZMembershipSoftDeletedOrphanTest extends TestCase
{
    use RefreshDatabase;

    private function fiscalOrder(bool $softDeleted, ?int $fiscalSeq): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'fiscal_sequence_no' => $fiscalSeq,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT, // non-terminal
            'parent_order_id' => null,
        ]);

        if ($softDeleted) {
            $order->delete(); // soft-delete
        }

        return $order;
    }

    /** @test — une commande fiscalisée SOFT-DELETÉE dans un trou de Z est DÉTECTÉE (plus de faux-vert). */
    public function soft_deleted_fiscalised_orphan_is_flagged(): void
    {
        // Aucun Z-report du tout → toute commande fiscalisée non couverte est un TROU. Avant le fix,
        // la commande soft-deletée était exclue → 0 candidat → SUCCESS (faux-vert). Après : withTrashed
        // la rend visible → TROU détecté → FAILURE.
        $this->fiscalOrder(softDeleted: true, fiscalSeq: 4242);

        $exit = Artisan::call('fiscal:verify-z-membership');

        $this->assertSame(
            1,
            $exit,
            'Une commande fiscalisée soft-deletée dans un trou de Z DOIT être signalée (exit FAILURE), pas masquée.'
        );
    }

    /** @test — une commande soft-deletée NON fiscalisée reste hors périmètre (garde whereNotNull préservée). */
    public function soft_deleted_non_fiscalised_order_is_ignored(): void
    {
        $this->fiscalOrder(softDeleted: true, fiscalSeq: null);

        $exit = Artisan::call('fiscal:verify-z-membership');

        $this->assertSame(
            0,
            $exit,
            'Une commande NON fiscalisée (fiscal_sequence_no NULL) n\'entre pas dans le contrôle Z-membership.'
        );
    }
}
