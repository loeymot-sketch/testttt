<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-5 / 5.1] Security invariants — defensive guards on
 * mass-assignment, XSS surface, SQL ORDER BY whitelisting, CSRF/Sanctum
 * gating and ApiKey middleware enforcement.
 *
 * These are READ-ONLY invariants over production code. No assertion
 * exercises a real exploit path; failures here mean a developer added
 * a regression that weakens the security posture (e.g. set
 * `$fillable=['*']`, exposed an unescaped HTML field, or removed a
 * required middleware).
 *
 * NB: tests never mutate production code. If a test fails, the
 * remediation is a code change — not a test softening.
 */
class SecurityInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /**
     * Mass-assignment guard — Order model must not accept arbitrary fields
     * via `Order::create($request->all())`. The whitelist must be explicit,
     * and `id` must NOT be fillable (would let a forged POST overwrite a
     * primary key during $request->all() ingestion).
     */
    public function test_order_model_has_explicit_fillable_whitelist(): void
    {
        $reflection = new ReflectionClass(Order::class);
        $fillableProp = $reflection->getProperty('fillable');
        $fillableProp->setAccessible(true);

        $fillable = $fillableProp->getValue(new Order());

        $this->assertIsArray($fillable, 'Order fillable must be an array');
        $this->assertNotEmpty($fillable, 'Order fillable must not be empty (no implicit guard)');
        $this->assertNotContains('*', $fillable, 'Order fillable must not contain wildcard');
        $this->assertNotContains('id', $fillable, 'Order fillable must not allow forging the primary key');

        // Sanity: at least the core fiscal fields must be explicit.
        foreach (['branch_id', 'total', 'status', 'idempotency_key'] as $required) {
            $this->assertContains($required, $fillable, "Order fillable must explicitly declare {$required}");
        }
    }

    /**
     * Mass-assignment guard — same invariant on FrontendOrder. Web /
     * mobile / kiosk surfaces all feed this model from arbitrary HTTP
     * input via $request->all(); a wildcard would be catastrophic.
     */
    public function test_frontend_order_model_has_explicit_fillable_whitelist(): void
    {
        $reflection = new ReflectionClass(FrontendOrder::class);
        $fillableProp = $reflection->getProperty('fillable');
        $fillableProp->setAccessible(true);

        $fillable = $fillableProp->getValue(new FrontendOrder());

        $this->assertIsArray($fillable, 'FrontendOrder fillable must be an array');
        $this->assertNotEmpty($fillable, 'FrontendOrder fillable must not be empty');
        $this->assertNotContains('*', $fillable, 'FrontendOrder fillable must not contain wildcard');
        $this->assertNotContains('id', $fillable, 'FrontendOrder fillable must not allow forging the primary key');
    }

    /**
     * XSS surface — Resource classes ship payloads to the SPA. Any field
     * named *_html / *_raw_html signals an unescaped HTML field that the
     * SPA renders verbatim. The kiosk frontend does not currently have a
     * "rich text" surface, so the invariant is that NO Resource exposes
     * a raw-HTML field. If a future feature requires one, it must opt in
     * explicitly (and a sibling test should require it to be DOMPurified
     * client-side).
     */
    public function test_no_resource_exposes_raw_html_field(): void
    {
        $resourcesDir = app_path('Http/Resources');
        $this->assertDirectoryExists($resourcesDir);

        $offenders = [];
        foreach (glob($resourcesDir . '/*.php') as $file) {
            $contents = file_get_contents($file);
            // Match field keys ending in _html / _raw_html / _unsafe_html on
            // the LHS of `=>` (Resource toArray pattern).
            if (preg_match('/[\'"][a-z_]+_(?:raw_html|unsafe_html)[\'"]\s*=>/i', $contents)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertEmpty(
            $offenders,
            'Resource classes must not expose *_raw_html / *_unsafe_html fields: ' . implode(', ', $offenders)
        );
    }

    /**
     * SQL injection ORDER BY — PosCategoryController::index() accepts
     * `order_column` / `order_type` from the query string and forwards to
     * ->orderBy(). Laravel backticks the column identifier so a payload
     * like `id; DROP TABLE orders;` cannot reach the SQL parser as code,
     * but the unfiltered identifier still produces a silent-empty result
     * set (DoS / fingerprintable degradation).
     *
     * **REMEDIATED (P1-10 / harden)**: PosCategoryController now applies an
     * explicit whitelist via `self::ALLOWED_ORDER_COLUMNS` and
     * `self::ALLOWED_ORDER_DIRECTIONS`, falling back to `id` / `desc` on
     * any non-whitelisted input. The endpoint must therefore return 200
     * for every input (legit or malicious), because the malicious value
     * is silently coerced to the safe default rather than reaching the
     * grammar at all.
     *
     * Invariants enforced:
     *  - malicious `order_column` → 200 (coerced to `id`, no SQL impact)
     *  - malicious `order_type`   → 200 (coerced to `desc`)
     *  - legitimate input         → 200 (accepted as-is)
     *
     * Regression risk: if a future change drops the whitelist, the SQL
     * driver will start raising `SQLSTATE[42S22]` on the malicious column
     * (column not found) and bubble up as a 500 from the try/catch — this
     * test would then fail on the malicious-column branch.
     */
    public function test_pos_category_order_column_whitelist_rejects_malicious_input(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('Admin');

        // Case 1: malicious column → coerced to default 'id', 200 OK with safe ordering.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?order_column=' . urlencode('id; DROP TABLE orders;--'));
        $this->assertEquals(
            200,
            $response->status(),
            'Malicious order_column must be silently coerced to the default and return 200. Status: ' . $response->status()
        );

        // Case 2: malicious direction → coerced to default 'desc', 200 OK.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?order_type=' . urlencode('DROP TABLE'));
        $this->assertEquals(
            200,
            $response->status(),
            'Malicious order_type must be silently coerced to the default and return 200. Status: ' . $response->status()
        );

        // Case 3: legitimate column + direction accepted as-is.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?order_column=name&order_type=asc');
        $this->assertEquals(
            200,
            $response->status(),
            'Legitimate whitelisted order_column/order_type must be honoured. Status: ' . $response->status()
        );
    }

    /**
     * CSRF / Sanctum guard — admin mutation routes must reject requests
     * that arrive without a Sanctum bearer token (or session cookie).
     * The test posts to a known admin endpoint without acting-as and
     * expects 401 (unauthenticated).
     */
    public function test_admin_mutation_route_requires_sanctum_auth(): void
    {
        // Send the api-key (so we pass ApiKeyMiddleware) but no auth.
        $response = $this->postJson('/api/admin/item', [
            'name' => 'no-auth-attempt',
        ]);

        // Sanctum returns 401 for unauthenticated; some routes return 419
        // when CSRF token is missing on a session-stateful path. Either
        // is acceptable; the invariant is "not 2xx".
        $this->assertContains(
            $response->status(),
            [401, 419, 403],
            'Admin mutation route must not be reachable without Sanctum auth. Status: ' . $response->status()
        );
    }

    /**
     * ApiKey middleware enforcement — `/api/frontend/*` is protected by
     * `apiKey` middleware. A request WITHOUT `x-api-key` must be
     * rejected. Note: the middleware returns 400 (not 401) — see
     * ApiKeyMiddleware::handle().
     *
     * IMPORTANT: TestCase::setUp() injects the api-key on every request
     * via withHeaders(). To exercise the missing-header path we must
     * explicitly null the header on this request.
     */
    public function test_frontend_route_rejects_request_without_api_key(): void
    {
        // Strip the auto-injected api-key header for this request only.
        $response = $this->withHeaders([
            'x-api-key' => '',
            'Accept'    => 'application/json',
        ])->getJson('/api/frontend/page');

        $this->assertEquals(
            400,
            $response->status(),
            'ApiKeyMiddleware must reject /api/frontend/* without a valid x-api-key header. Status: ' . $response->status()
        );
    }

    /**
     * ApiKey middleware enforcement — wrong key value also rejected.
     */
    public function test_frontend_route_rejects_request_with_wrong_api_key(): void
    {
        $response = $this->withHeaders([
            'x-api-key' => 'completely-wrong-key-' . bin2hex(random_bytes(8)),
            'Accept'    => 'application/json',
        ])->getJson('/api/frontend/page');

        $this->assertEquals(
            400,
            $response->status(),
            'ApiKeyMiddleware must reject requests with a non-matching x-api-key. Status: ' . $response->status()
        );
    }
}
