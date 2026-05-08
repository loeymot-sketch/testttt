<?php

namespace Tests\Unit\Models;

use App\Models\Branch;
use App\Models\DeliveryPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Verifies the DeliveryPlatform Eloquent model:
 *   - encrypted:json cast roundtrips credentials at rest;
 *   - relations to Branch / externalOrders are wired;
 *   - the BranchScope global scope is applied (auth-context tests
 *     live in BranchIsolationTest at the integration layer; here
 *     we just assert the scope is registered).
 */
class DeliveryPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_at_rest_and_decrypted_via_cast(): void
    {
        $branch = Branch::factory()->create();

        $secret = [
            'api_key'        => 'ue_sk_test_AbCdEf1234567890',
            'webhook_secret' => 'whsec_zXyWvU09876',
        ];

        $platform = DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_uuid_42',
            'credentials'       => $secret,
        ]);

        // Read via the model cast: plaintext.
        $this->assertSame($secret, $platform->fresh()->credentials);

        // Read raw from the DB: ciphertext (NOT the plaintext json).
        $rawRow = \DB::table('delivery_platforms')->where('id', $platform->id)->first();
        $this->assertNotNull($rawRow);
        $this->assertNotEquals(json_encode($secret), $rawRow->credentials);
        // Sanity: the raw column is non-empty and does not contain the api_key in cleartext.
        $this->assertNotEmpty($rawRow->credentials);
        $this->assertStringNotContainsString('ue_sk_test_AbCdEf1234567890', $rawRow->credentials);
    }

    public function test_relations_are_wired(): void
    {
        $branch = Branch::factory()->create();

        $platform = DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'deliveroo',
            'enabled'           => false,
            'external_store_id' => 'r-12345',
            'credentials'       => ['api_key' => 'test'],
        ]);

        $this->assertInstanceOf(Branch::class, $platform->branch);
        $this->assertSame($branch->id, $platform->branch->id);

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Collection::class,
            $platform->externalOrders
        );
    }

    public function test_branch_scope_is_registered_on_model(): void
    {
        // Static introspection — the global scope is registered in
        // booted(), so it must be present in getGlobalScopes() once
        // the model boots.
        DeliveryPlatform::query();
        $scopes = (new DeliveryPlatform())->getGlobalScopes();
        $this->assertArrayHasKey(\App\Models\Scopes\BranchScope::class, $scopes);
    }

    public function test_unique_branch_platform_pair_holds(): void
    {
        $branch = Branch::factory()->create();

        DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'a',
            'credentials'       => ['api_key' => 'k'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => false,
            'external_store_id' => 'b',
            'credentials'       => ['api_key' => 'k2'],
        ]);
    }
}
