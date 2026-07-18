<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [C3 2026-07-18 · notif client « prête »] Le canal privé PAR CLIENT
 * `customer.{customerId}` (diffusé `private-customer.{id}`) porte les changements de
 * statut de commande vers le compte du client. SÉCURITÉ NON-NÉGOCIABLE : un client
 * ne peut s'abonner QU'À SON PROPRE canal — jamais à celui d'un autre (pas de fuite
 * cross-client). Le prédicat d'autorisation est purement identitaire
 * ((int)$user->id === (int)$customerId), inspoofable (comme App.Models.User.{id}),
 * et n'exige aucune requête DB.
 *
 * Le driver de broadcast en test = `log` (auth() no-op) → l'endpoint HTTP
 * /api/broadcasting/auth n'exécute PAS le callback. On teste donc le callback
 * d'autorisation DIRECTEMENT (invocation via le registre de canaux du broadcaster),
 * ce qui prouve la logique de garde indépendamment du driver.
 */
class CustomerOrderChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Résout le callback enregistré pour un nom de canal donné (avec placeholders,
     * ex. `customer.{customerId}`) depuis le registre interne du broadcaster.
     */
    private function resolveChannelCallback(string $channelName): ?\Closure
    {
        $broadcaster = app(BroadcasterContract::class);
        $prop = new \ReflectionProperty(Broadcaster::class, 'channels');
        $prop->setAccessible(true);
        /** @var array<string,mixed> $channels */
        $channels = $prop->getValue($broadcaster);

        $cb = $channels[$channelName] ?? null;

        return $cb instanceof \Closure ? $cb : null;
    }

    public function test_customer_channel_is_registered(): void
    {
        $this->assertNotNull(
            $this->resolveChannelCallback('customer.{customerId}'),
            'C3 : le canal `customer.{customerId}` DOIT être enregistré dans routes/channels.php.'
        );
    }

    public function test_customer_is_authorized_on_own_channel(): void
    {
        $me = User::factory()->create();
        $callback = $this->resolveChannelCallback('customer.{customerId}');
        $this->assertNotNull($callback);

        // Laravel passe le paramètre extrait sous forme de string → on reproduit fidèlement.
        $this->assertTrue(
            (bool) $callback($me, (string) $me->id),
            'C3 : le client DOIT être autorisé sur SON propre canal client.'
        );
    }

    public function test_customer_is_refused_on_another_customers_channel(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $callback = $this->resolveChannelCallback('customer.{customerId}');
        $this->assertNotNull($callback);

        $this->assertFalse(
            (bool) $callback($me, (string) $other->id),
            'C3 : le client NE DOIT PAS pouvoir s\'abonner au canal d\'un AUTRE client (anti-fuite).'
        );
    }
}
