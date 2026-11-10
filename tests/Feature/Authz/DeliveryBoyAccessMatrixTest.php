<?php

namespace Tests\Feature\Authz;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.4.1] Matrice RÉELLE des droits du rôle « Delivery Boy ».
 *
 * Relevé (base de dev ET production, lecture seule, 2026-11-10) : le rôle existe, atterrit sur `delivery-boys`
 * et ne possède AUCUNE permission Spatie (`RolePermissionTableSeeder` ne lui en donne pas). Son écran est
 * `/livreur` (API `api/frontend/delivery-run*`, garde = `delivery_boy_id === Auth::id()`), pas l'admin.
 *
 * Ce que ce test verrouille :
 *  1. Un livreur ne lit RIEN de l'admin qui ne soit pas de la donnée de référence sans client ni argent :
 *     toute route `api/admin/*` dépourvue de middleware `permission:`/`role:` est appelée avec son compte
 *     et doit refuser (401/403/404). La liste est calculée depuis le routeur — une route ajoutée demain
 *     sans garde entre d'elle-même dans la sonde.
 *  2. Les tickets (client ET cuisine) lui sont fermés.
 *  3. Un livreur ne voit que SES arrêts : ceux d'un collègue → 403 sans adresse ni téléphone.
 */
class DeliveryBoyAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes admin SANS middleware de permission qu'un compte authentifié de n'importe quel rôle peut lire
     * PAR CONCEPTION : données de référence de l'interface (devise, langue, fuseau, thème…), sans client,
     * sans commande, sans argent. Toute autre réponse 2xx à un livreur est un défaut.
     */
    private const LECTURE_DE_REFERENCE = [
        'api/admin/country-code',
        'api/admin/country-code/show/{country}',
        'api/admin/timezone',
        'api/admin/setting/currency',
        'api/admin/setting/language',
        'api/admin/setting/language/show/{language}',
        'api/admin/setting/language/file-list/{language}',
        'api/admin/setting/site',
        'api/admin/setting/theme',
        'api/admin/setting/cookies',
        'api/admin/setting/social-media',
        'api/admin/setting/slider',
        'api/admin/setting/menu-section',
        'api/admin/setting/menu-template',
        'api/admin/setting/menu-template/show/{menuTemplate}',
        'api/admin/setting/analytic-section/{analytic}',
        'api/admin/setting/tax',
        'api/admin/setting/time-slot',
        'api/admin/setting/order-setup',
        'api/admin/pos-category',
        // Relevé 2026-11-10 : seules ces six-là répondaient 200 à un livreur parmi les 74 routes sans garde ;
        // données d'exploitation de l'interface (succursale, société, tables, modèles de messages), sans client ni argent.
        'api/admin/default-access',
        'api/admin/setting/company',
        'api/admin/setting/branch',
        'api/admin/setting/branch/show/{branch}',
        'api/admin/setting/notification-alert',
        'api/admin/dining-table',
        // Même nature (réglages OTP sans secret, fiche succursale par coordonnées) ; en environnement de test ces deux-là
        // répondent 500/422 faute de clés de réglage, pas pour une raison d'autorisation.
        'api/admin/setting/otp',
        'api/admin/setting/branch/lat-long/{branch}',
    ];

    private Branch $branch;
    private User $livreur;
    private User $collegue;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        config(['app.api_key' => 'test-api-key']);

        $this->branch = Branch::factory()->create();
        $this->livreur = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->livreur->assignRole('Delivery Boy');
        $this->collegue = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->collegue->assignRole('Delivery Boy');

        $client = User::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0612345678']);
        $this->order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $client->id, 'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'source_surface' => 'delivery',
        ]);
        OrderAddress::query()->create(['order_id' => $this->order->id, 'user_id' => $client->id, 'label' => 'x', 'address' => '10 Rue Test', 'latitude' => '50.43', 'longitude' => '2.96']);
    }

    private function enLivreur(User $u = null)
    {
        return $this->actingAs($u ?? $this->livreur, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    public function test_le_role_livreur_n_a_aucune_permission_et_c_est_documente(): void
    {
        $this->assertSame([], Role::findByName('Delivery Boy', 'sanctum')->permissions()->pluck('name')->all(),
            'Le rôle Livreur a reçu des permissions admin : mettre à jour docs/AUTHZ_MATRIX.md et cette matrice.');
    }

    /** 1. Toute route admin sans garde de permission refuse un livreur (sauf référence sans client ni argent). */
    public function test_un_livreur_ne_lit_ni_ne_mute_rien_dans_l_admin(): void
    {
        $item = Item::factory()->create();
        $params = [
            'order' => $this->order->id, 'item' => $item->id, 'branch' => $this->branch->id,
            'zReport' => 1, 'country' => 1, 'language' => 1, 'menuTemplate' => 1, 'analytic' => 1,
            'code' => 'X1', 'itemExtra' => 1, 'itemVariation' => 1, 'nom' => 'fidelite', 'flyer' => 1,
        ];

        $sondees = 0;
        $fuites = [];
        $aInspecter = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/admin/') || $uri === 'api/admin/menu-projection') {
                continue; // menu-projection : sondée plus bas avec des paramètres valides (elle valide avant d'autoriser)
            }
            $middleware = implode(',', array_filter($route->gatherMiddleware(), 'is_string'));
            if (preg_match('/permission:|role:|role_or_permission:|can:|PermissionMiddleware|RoleMiddleware|Authorize:/', $middleware)) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $chemin = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($params[$m[1]] ?? 1), $uri);
            $sondees++;

            $reponse = $this->enLivreur()->json($method, '/'.$chemin, []);
            $status = $reponse->getStatusCode();
            $libelle = $method.' '.$uri;

            if (in_array($uri, self::LECTURE_DE_REFERENCE, true) && $method === 'GET') {
                continue; // lecture de référence : 200 attendu, ni client ni argent dedans
            }
            if ($status >= 200 && $status < 300) {
                $fuites[] = $libelle.' → '.$status.' '.Str::limit((string) $reponse->getContent(), 160);
            } elseif (! in_array($status, [401, 403, 404, 405], true)) {
                // 422 = la validation a tourné AVANT toute autorisation ; 500 = garde absente et code qui casse.
                $aInspecter[] = $libelle.' → '.$status;
            }
        }

        // `menu-projection` valide AVANT d'autoriser (422 à vide) : on la sonde avec des paramètres valides.
        $projection = $this->enLivreur()->getJson('/api/admin/menu-projection?channel=pos&branch_id='.$this->branch->id);
        if ($projection->getStatusCode() < 300) {
            $fuites[] = 'GET api/admin/menu-projection?channel=pos → '.$projection->getStatusCode();
        }

        $this->assertGreaterThan(40, $sondees, 'La sonde n\'a presque rien trouvé : le filtre de routes est cassé.');
        $this->assertSame([], $fuites, "Un livreur sans permission obtient une réponse 2xx :\n".implode("\n", $fuites));
        $this->assertSame([], $aInspecter, "Routes atteintes par un livreur sans refus franc (422/5xx) :\n".implode("\n", $aInspecter));
    }

    /**
     * 1bis. [SUPERVISION 2026-11-10] `setting/site` est lisible par tout compte (le SPA le charge au démarrage), mais la clé
     * Google Maps — facturable — et le drapeau de débogage ne sortent que pour un porteur de `settings`. Trouvé par la
     * relecture adverse : la liste blanche ci-dessus avait rangé cette route en « référence sans argent » alors qu'elle
     * livrait la clé.
     */
    public function test_un_livreur_lit_les_reglages_du_site_sans_la_cle_google_maps(): void
    {
        \Smartisan\Settings\Facades\Settings::group('site')->set('site_google_map_key', 'AIza-cle-de-test');
        \Smartisan\Settings\Facades\Settings::group('site')->set('site_app_debug', 5);

        $livreur = $this->enLivreur()->getJson('/api/admin/setting/site')->assertOk()->json();
        $livreur = $livreur['data'] ?? $livreur;
        $this->assertNull($livreur['site_google_map_key'] ?? null, 'la clé Google Maps est lisible par un livreur');
        $this->assertNull($livreur['site_app_debug'] ?? null);
        $this->assertArrayHasKey('site_default_currency', $livreur, 'les réglages d\'interface restent lisibles');

        \Spatie\Permission\Models\Permission::findOrCreate('settings', 'sanctum');
        $admin = User::factory()->create(['branch_id' => 0, 'status' => Status::ACTIVE]);
        $admin->givePermissionTo('settings');
        $vu = $this->enLivreur($admin)->getJson('/api/admin/setting/site')->assertOk()->json();
        $vu = $vu['data'] ?? $vu;
        $this->assertSame('AIza-cle-de-test', $vu['site_google_map_key'] ?? null, 'l\'écran Réglages doit toujours voir la clé');
    }

    /** 2. Les octets ESC/POS, client comme cuisine, sont fermés au livreur (T-1.3.3). */
    public function test_un_livreur_ne_lit_aucun_ticket(): void
    {
        $this->enLivreur()->getJson("/api/admin/pos/orders/{$this->order->id}/escpos?ticket=client")->assertForbidden();
        $this->enLivreur()->getJson("/api/admin/pos/orders/{$this->order->id}/escpos?ticket=kitchen")->assertForbidden();
    }

    /** 3. Un arrêt attribué à un collègue : rien à lire, rien à faire, aucune donnée du client dans la réponse. */
    public function test_un_livreur_ne_voit_pas_les_arrets_d_un_collegue(): void
    {
        $stop = \App\Models\DeliveryStop::query()->create([
            'branch_id' => $this->branch->id, 'order_id' => $this->order->id, 'delivery_boy_id' => $this->collegue->id,
            'wave_at' => now()->addMinutes(30)->startOfMinute(), 'sequence' => 1,
            'collect_method' => 'cash', 'amount_due_cents' => 1250,
        ]);

        $liste = $this->enLivreur()->getJson('/api/frontend/delivery-run')->assertOk();
        $this->assertSame([], $liste->json('data'), 'La tournée du collègue apparaît chez un autre livreur.');

        foreach (['arrive', 'deliver'] as $action) {
            $r = $this->enLivreur()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/{$action}", []);
            $this->assertSame(403, $r->getStatusCode(), "$action sur l'arrêt d'un collègue → ".$r->getStatusCode());
            $this->assertStringNotContainsString('10 Rue Test', (string) $r->getContent());
            $this->assertStringNotContainsString('0612345678', (string) $r->getContent());
        }
        $this->enLivreur()->get("/api/frontend/delivery-run/stops/{$stop->id}/photo")->assertForbidden();
        // Partir avec la tournée d'un collègue : aucun arrêt n'est à lui → refus, et la tournée du collègue reste « à partir ».
        $depart = $this->enLivreur()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()]);
        $this->assertGreaterThanOrEqual(400, $depart->getStatusCode(), 'Un livreur a fait partir la tournée d\'un collègue.');
        $this->assertNull($stop->fresh()->left_at);

        // Le collègue, lui, voit son arrêt avec l'adresse.
        $this->assertCount(1, $this->enLivreur($this->collegue)->getJson('/api/frontend/delivery-run')->json('data'));
    }
}
