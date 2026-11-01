<?php

namespace Tests\Feature\Delivery\Concerns;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Delivery\DeliveryWaveService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * [LIVRAISON QR 2026-11-01] Décor commun des tests de la remise par QR : une branche, deux livreurs, un client,
 * un membre de l'équipe, et une fabrique de livraisons (commande + adresse + arrêt de tournée).
 * Mot de passe de tous les comptes de test : « password » (hachage de la fabrique d'utilisateurs).
 */
trait HandoverFixtures
{
    protected Branch $branch;
    protected User $courier;
    protected User $otherCourier;
    protected User $customerAccount;
    protected User $staffAccount;
    protected User $adminAccount;

    protected function setUpHandover(): void
    {
        Storage::fake('local');
        $this->seedSpatieRoles();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key', 'app.url' => 'https://plateforme.test']);
        Carbon::setTestNow(Carbon::parse('2026-11-01 19:02:00'));

        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->courier = $this->userWithRole('Delivery Boy', 'livreur.a@test.fr');
        $this->otherCourier = $this->userWithRole('Delivery Boy', 'livreur.b@test.fr');
        $this->customerAccount = $this->userWithRole('Customer', 'client@test.fr');
        $this->staffAccount = $this->userWithRole('POS Operator', 'caisse@test.fr');
        $this->adminAccount = $this->userWithRole('Admin', 'admin@test.fr', 0);
    }

    protected function tearDownHandover(): void
    {
        Carbon::setTestNow();
    }

    protected function userWithRole(string $role, string $email, ?int $branchId = null): User
    {
        $u = User::factory()->create([
            'email' => $email, 'status' => Status::ACTIVE,
            'branch_id' => $branchId ?? $this->branch->id,
        ]);
        $u->assignRole($role);

        return $u;
    }

    /**
     * Livraison à encaisser/payée, rattachée à sa tournée (19:30). Par défaut : espèces à la porte, 27,50 €, PRÊTE.
     *
     * @param  array<string,mixed>  $over
     */
    protected function delivery(array $over = [], ?User $courier = null): DeliveryStop
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Karim Test', 'phone' => '0612345678']);
        $order = Order::factory()->create($over + [
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'total' => 27.50, 'delivery_charge' => 3.90,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '10 Rue Test, Hénin-Beaumont',
            'apartment' => '2B', 'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);
        $stop = app(DeliveryWaveService::class)->assign($order->fresh());
        $who = $courier ?? $this->courier;
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => $who->id]);
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['delivery_boy_id' => $who->id]);

        return $stop->fresh();
    }

    /** Le livreur est parti : commande EN ROUTE, arrêt « parti ». */
    protected function departed(DeliveryStop $stop): DeliveryStop
    {
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::OUT_FOR_DELIVERY]);
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['left_at' => now()]);

        return $stop->fresh();
    }

    protected function qrToken(DeliveryStop $stop): string
    {
        $token = app(DeliveryHandoverService::class)->issueForStop($stop->fresh());
        $this->assertNotNull($token, 'un jeton doit pouvoir être émis pour cet arrêt');

        return $token;
    }

    /** Connexion dédiée : renvoie le jeton « handover_token » du livreur. */
    protected function handoverLogin(string $email, string $password = 'password'): string
    {
        $r = $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json'])
            ->postJson('/api/frontend/delivery-handover/login', ['email' => $email, 'password' => $password]);
        $r->assertStatus(201);

        return (string) $r->json('token');
    }

    /** @return array<string,string> */
    protected function bearer(string $token, ?string $idem = null): array
    {
        return ['Authorization' => 'Bearer '.$token, 'x-api-key' => 'test-api-key', 'Accept' => 'application/json',
            'X-Idempotency-Key' => $idem ?? (string) Str::uuid()];
    }
}
