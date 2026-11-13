<?php

namespace Tests\Feature\UberDirect;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\UberDirectDelivery;
use App\Models\User;
use App\Services\UberDirect\UberDirectDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-11-13] Une course Uber peut finir APRÈS la suppression du compte (commande annulée alors que le
 * coursier avait déjà le sac : la course n'est volontairement pas annulée). La suppression effaçait bien nom, téléphone et
 * consignes du destinataire, mais le webhook / le relevé suivant (applyStatus) réécrivait la réponse brute d'Uber — et
 * les y remettait. Pour un compte effacé, la réponse d'Uber est enregistrée SANS ces données.
 */
class CourseUberCompteSupprimeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        config(['app.api_key' => 'k']);
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $table = config('settings.repositories.database.table', 'settings');
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(['key' => 'site_default_branch', 'group' => 'site'],
                ['payload' => json_encode((string) $this->branch->id), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->withHeaders(['x-api-key' => 'k', 'Accept' => 'application/json']);
    }

    private function client(string $tel): User
    {
        $u = User::factory()->create(['branch_id' => 0, 'name' => 'Jean D.', 'phone' => $tel,
            'email' => $tel . '@example.test', 'email_verified_at' => now()->timestamp, 'status' => Status::ACTIVE, 'is_guest' => Ask::YES]);
        $u->assignRole('Customer');

        return $u;
    }

    private function course(User $u): int
    {
        $commande = Order::factory()->create(['user_id' => $u->id, 'branch_id' => $this->branch->id, 'status' => OrderStatus::CANCELED]);

        return DB::table('uber_direct_deliveries')->insertGetId([
            'branch_id' => $this->branch->id, 'order_id' => $commande->id, 'status' => 'pickup_complete', 'quote_fee_cents' => 742,
            'delivery_id' => 'del_' . $commande->id, 'dropoff_phone' => '+33612345678', 'dropoff_instructions' => 'code 1234',
            'delivery_payload' => json_encode(['id' => 'del_' . $commande->id, 'dropoff' => ['name' => 'Jean D.', 'phone_number' => '+33612345678', 'notes' => 'code 1234']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function livrer(int $id): array
    {
        $row = UberDirectDelivery::withoutGlobalScopes()->find($id);
        app(UberDirectDispatchService::class)->applyStatus($row, 'delivered', [
            'id' => $row->delivery_id, 'status' => 'delivered', 'tracking_url' => 'https://track.example/x',
            'dropoff' => ['name' => 'Jean D.', 'phone_number' => '+33612345678', 'notes' => 'code 1234', 'address' => '12 rue de Lens'],
        ]);

        return json_decode((string) DB::table('uber_direct_deliveries')->find($id)->delivery_payload, true);
    }

    public function test_le_webhook_uber_ne_reinjecte_pas_le_destinataire_d_un_compte_supprime(): void
    {
        $u = $this->client('0612345678');
        $id = $this->course($u);
        $jeton = $u->createToken('auth_token', ['kiosk:order'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $jeton)->postJson('/api/auth/delete-account')->assertOk();

        $brut = $this->livrer($id);

        $this->assertSame('delivered', DB::table('uber_direct_deliveries')->find($id)->status, 'le statut de la course doit être appliqué');
        $this->assertArrayNotHasKey('phone_number', $brut['dropoff'] ?? [], 'téléphone réinjecté après suppression');
        $this->assertArrayNotHasKey('name', $brut['dropoff'] ?? [], 'nom réinjecté après suppression');
        $this->assertArrayNotHasKey('notes', $brut['dropoff'] ?? [], 'consignes réinjectées après suppression');
        $this->assertSame('12 rue de Lens', $brut['dropoff']['address'] ?? null, 'l\'adresse de livraison (non personnelle à elle seule) reste');
    }

    public function test_la_course_d_un_compte_actif_garde_la_reponse_d_uber_entiere(): void
    {
        $id = $this->course($this->client('0698765432'));

        $brut = $this->livrer($id);

        $this->assertSame('+33612345678', $brut['dropoff']['phone_number'] ?? null);
        $this->assertSame('Jean D.', $brut['dropoff']['name'] ?? null);
    }
}
