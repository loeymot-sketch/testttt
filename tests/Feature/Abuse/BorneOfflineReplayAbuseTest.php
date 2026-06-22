<?php

namespace Tests\Feature\Abuse;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VECTOR borne_offline — kiosk offline queue replay-once invariant.
 *
 * The kiosk replays queued offline orders through
 * resources/js/helpers/kioskOfflineQueue.js::syncQueue → POST /frontend/order
 * with header X-Idempotency-Key = entry.localKey (kioskOfflineQueue.js:558-562).
 *
 * Crucial subtlety: before each replay, _payloadWithFreshQuote()
 * (kioskOfflineQueue.js:370-393) RE-FETCHES a fresh quote (new quote_token,
 * recomputed total) when the queued payload's quote is missing/stale. So the
 * SECOND replay attempt of the SAME logical order can carry a DIFFERENT request
 * BODY than the first.
 *
 * The HTTP IdempotencyKeyMiddleware keys replay on (branch,user,key) AND
 * requires payloadHash match (IdempotencyKeyMiddleware.php:88,110) — a changed
 * body → 409 IDEMPOTENCY_KEY_CONFLICT, NOT a cached replay.
 *
 * These tests pin the real end-to-end behavior through the actual middleware +
 * app-layer recovery, to prove EXACTLY ONE order survives a lost-response retry.
 */
class BorneOfflineReplayAbuseTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::query()->create([
            'name'       => 'KIOSK-OFFLINE-' . uniqid(),
            'machine_id' => 'KM-' . uniqid(),
            'username'   => 'k-' . uniqid(),
            'password'   => 'pwd',
            'user_id'    => $user->id,
            'branch_id'  => $branch->id,
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type'     => TaxType::PERCENTAGE,
            'status'   => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'price'            => 6.50,
            'status'           => Status::ACTIVE,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        return compact('branch', 'user', 'item');
    }

    private function basePayload(array $ctx): array
    {
        return [
            'branch_id'        => $ctx['branch']->id,
            'order_type'       => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source'           => Source::WEB,
            'payment_method'   => PaymentGateway::CARD,
            'items'            => json_encode([[
                'item_id'         => $ctx['item']->id,
                'quantity'        => 2,
                'item_variations' => [],
                'item_extras'     => [],
            ]]),
        ];
    }

    /**
     * Mirror the kiosk flow: fetch a signed quote, then return the order payload
     * the client would POST (with quote_token/signature/total) — exactly what
     * kioskOfflineQueue._payloadWithFreshQuote() assembles before each replay.
     */
    private function orderPayload(array $ctx): array
    {
        $base = $this->basePayload($ctx);
        $quote = $this->postJson('/api/frontend/order/quote', $base)
            ->assertOk()
            ->json('data');

        return $base + [
            'quote_token'     => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
            'total'           => $quote['total_ttc'],
        ];
    }

    private function key(string $suffix): string
    {
        return 'offline_' . str_replace('.', '', (string) microtime(true)) . $suffix;
    }

    /**
     * Baseline: identical payload + same idempotency key replays to one order.
     */
    public function test_offline_replay_same_payload_same_key_creates_exactly_one_order(): void
    {
        config(['idempotency.enabled' => true]);
        $ctx = $this->ctx();
        $payload = $this->orderPayload($ctx);
        $key = $this->key('abcd');

        $first = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/frontend/order', $payload);
        $second = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/frontend/order', $payload);

        $this->assertContains($first->status(), [200, 201], $first->getContent());
        $this->assertContains($second->status(), [200, 201], $second->getContent());

        $count = FrontendOrder::where('branch_id', $ctx['branch']->id)->count();
        $this->assertSame(1, $count, 'Same key + same payload must yield EXACTLY ONE order.');
    }

    /**
     * THE REAL OFFLINE-REPLAY CASE.
     *
     * First POST reaches the server and COMMITS (order created with
     * idempotency_key), but a network blip drops the response. The kiosk's
     * syncQueue() retries; _payloadWithFreshQuote() re-fetches a fresh quote, so
     * the retry body DIFFERS (new quote_token, total).
     *
     * HARD invariant: still EXACTLY ONE order.
     */
    public function test_offline_replay_with_changed_body_same_key_does_not_double_create(): void
    {
        config(['idempotency.enabled' => true]);
        $ctx = $this->ctx();
        $payload = $this->orderPayload($ctx);
        $key = $this->key('wxyz');

        $first = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/frontend/order', $payload);
        $this->assertContains($first->status(), [200, 201], $first->getContent());
        $this->assertSame(1, FrontendOrder::where('branch_id', $ctx['branch']->id)->count());

        // Retry with a freshly-fetched quote (new quote_token) → different BODY,
        // identical X-Idempotency-Key — exactly _payloadWithFreshQuote()'s output.
        $changedBody = $this->orderPayload($ctx);
        $second = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/frontend/order', $changedBody);

        $count = FrontendOrder::where('branch_id', $ctx['branch']->id)->count();
        $this->assertSame(
            1,
            $count,
            'Lost-response retry with a freshly-quoted body must NOT create a second order. '
            . 'Second response status was ' . $second->status() . ': ' . $second->getContent()
        );
    }

    /**
     * App-layer recovery (findExistingFrontendOrderForIdempotencyRecovery,
     * keyed on branch+key, payload-agnostic) must dedupe even when the HTTP
     * middleware is DISABLED (config default OFF). This is the V1 LOCAL reality.
     */
    public function test_offline_replay_dedupes_at_app_layer_when_middleware_disabled(): void
    {
        config(['idempotency.enabled' => false]);
        $ctx = $this->ctx();
        $payload = $this->orderPayload($ctx);
        $key = $this->key('mid0');

        $first = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/frontend/order', $payload);
        $this->assertContains($first->status(), [200, 201], $first->getContent());

        $changedBody = $this->orderPayload($ctx);
        $second = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/frontend/order', $changedBody);

        $count = FrontendOrder::where('branch_id', $ctx['branch']->id)->count();
        $this->assertSame(
            1,
            $count,
            'App-layer recovery must dedupe by (branch,key) regardless of payload. '
            . 'Second status ' . $second->status() . ': ' . $second->getContent()
        );
    }

    /**
     * Cross-branch key collision: two kiosks replay the same localKey.
     * Each must get its OWN order (key scoped by branch).
     */
    public function test_same_key_different_branch_creates_two_distinct_orders(): void
    {
        config(['idempotency.enabled' => true]);
        $sharedKey = $this->key('xbr0');

        $a = $this->ctx();
        $respA = $this->withHeader('X-Idempotency-Key', $sharedKey)
            ->postJson('/api/frontend/order', $this->orderPayload($a));
        $this->assertContains($respA->status(), [200, 201], $respA->getContent());

        $b = $this->ctx();
        $respB = $this->withHeader('X-Idempotency-Key', $sharedKey)
            ->postJson('/api/frontend/order', $this->orderPayload($b));
        $this->assertContains($respB->status(), [200, 201], $respB->getContent());

        // BranchScope would hide the other branch's order from the active
        // (branch B) auth context — drop it for the count assertion only.
        $countA = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $a['branch']->id)->count();
        $countB = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $b['branch']->id)->count();
        $this->assertSame(1, $countA, 'Branch A must own exactly one order.');
        $this->assertSame(1, $countB, 'Branch B must own exactly one order (key not leaked across branch).');
    }
}
