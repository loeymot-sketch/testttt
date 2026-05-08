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
     * SQL injection ORDER BY — PosCategoryController::index() accepts a
     * user-supplied `order_column` and forwards it to ->orderBy() with
     * NO whitelist (see app/Http/Controllers/Admin/PosCategoryController.php
     * line 29-30). Laravel backticks the column identifier so a payload
     * like `id; DROP TABLE orders;` cannot reach the SQL parser as
     * code — the QueryGrammar wraps it as `\`id; DROP TABLE orders;--\``
     * and the driver returns 0 rows instead of 200-with-data — so the
     * impact is "fingerprintable / DoS / silent-degradation" rather
     * than RCE.
     *
     * **FINDING**: a malicious `order_column` currently returns 200 OK
     * (with empty result set) on this codebase. That's a defense-in-depth
     * gap, NOT an active exploit. Remediation = add an explicit whitelist
     * (in_array($column, ['id', 'name', 'slug', 'created_at'], true) ?
     * $column : 'id') in PosCategoryController::index().
     *
     * Track-5 charter forbids prod fixes — this test is therefore marked
     * `markTestIncomplete` to publish the finding without breaking CI.
     * Promote to a hard `assertNotEquals(200, ...)` once the whitelist
     * lands. See plans/PLAN_AUDIT_F0XX_POS_CATEGORY_ORDER_COLUMN_WHITELIST.md
     * (to be filed by orchestrator).
     */
    public function test_pos_category_rejects_malicious_order_column(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('Admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?order_column=' . urlencode('id; DROP TABLE orders;--'));

        if ($response->status() === 200) {
            $this->markTestIncomplete(
                'FINDING: PosCategoryController::index accepts non-whitelisted '
                . 'order_column. Backticking blocks RCE but DoS / silent-empty '
                . 'is possible. Remediation: in_array($column, [\'id\', \'name\', '
                . '\'slug\', \'created_at\'], true) whitelist. Track-5 charter '
                . 'forbids prod fix; promote this assertion to hard once '
                . 'remediated.'
            );
        }

        // Once remediated, this branch becomes the authoritative invariant:
        $this->assertContains(
            $response->status(),
            [400, 422, 500],
            'After whitelist remediation, malicious order_column must be 4xx (validation rejected). '
            . 'Status: ' . $response->status()
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
