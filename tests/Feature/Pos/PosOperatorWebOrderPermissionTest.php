<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [WEB-ORDER-ACCEPT 2026-07-30 · décision owner] Audit adversaire « gestion commande site »
 * 2026-07-30 : le bouton « Accepter » d'une commande web du tracker était MORT (affiché mais POST
 * → 403) pour le rôle « POS Operator », qui portait `pos`+`pos-orders` mais PAS `online-orders`.
 * Décision owner : le caissier accepte/gère les commandes du site au comptoir (mono-resto).
 *
 * Ce test verrouille les DEUX invariants du grant :
 *   (1) POS Operator porte `online-orders` → le bouton « Accepter » fonctionne ;
 *   (2) POS Operator NE porte PAS `pos-refund` → le remboursement reste réservé (frontière saine).
 */
class PosOperatorWebOrderPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
    }

    private function posOperatorPermissionNames(): \Illuminate\Support\Collection
    {
        $role = Role::where('name', 'POS Operator')->where('guard_name', 'sanctum')->firstOrFail();

        return $role->permissions->pluck('name');
    }

    /** @test */
    public function pos_operator_role_carries_online_orders_permission(): void
    {
        $this->assertTrue(
            $this->posOperatorPermissionNames()->contains('online-orders'),
            'Le caissier (POS Operator) doit porter online-orders pour accepter/gérer les commandes web.'
        );
    }

    /** @test */
    public function pos_operator_role_does_not_carry_pos_refund_permission(): void
    {
        $this->assertFalse(
            $this->posOperatorPermissionNames()->contains('pos-refund'),
            'Le remboursement reste réservé au gérant — pos-refund NON accordé au caissier.'
        );
    }
}
