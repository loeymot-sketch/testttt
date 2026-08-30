<?php

namespace Tests\Feature\VoiceOrder;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\VoiceOrder\VoiceOrderDraftExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceOrderDraftExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_payload_is_minimized_store_disabled_and_ids_are_catalog_bounded(): void
    {
        Cache::flush();
        $branch = Branch::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'name' => 'Sandwich Cayenne',
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
            'price' => 99.99,
            'channels' => ['pos'],
        ]);

        Config::set('voice_order.openai.enabled', true);
        Config::set('voice_order.openai.model', 'gpt-test-cheap');
        Config::set('services.openai.key', 'sk-test-secret');
        Config::set('services.openai.base_url', 'https://api.openai.test/v1');

        Http::fake(function (Request $request) use ($item) {
            $body = $request->data();
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
            $this->assertFalse($body['store']);
            $this->assertSame('none', $body['reasoning']['effort']);
            $this->assertSame(1200, $body['max_output_tokens']);
            $this->assertStringNotContainsString('0612345678', $encoded);
            $this->assertStringNotContainsString('client@example.com', $encoded);
            $this->assertStringNotContainsString('99.99', $encoded);
            $this->assertArrayNotHasKey('branch_id', $body);

            return Http::response(['output' => [[
                'type' => 'reasoning',
                'content' => [],
            ], [
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'lines' => [[
                            'item_id' => $item->id,
                            'quantity' => 1,
                            'notes' => 'salade tomate oignon',
                            'confidence' => 0.92,
                            'missing_slots' => [],
                        ], [
                            'item_id' => 999999,
                            'quantity' => 1,
                            'notes' => null,
                            'confidence' => 1,
                            'missing_slots' => [],
                        ]],
                        'ambiguities' => [],
                    ]),
                ]],
            ]]], 200);
        });

        $draft = app(VoiceOrderDraftExtractor::class)->extract(
            $branch->id,
            'Je veux un sandwich Cayenne, téléphone 06 12 34 56 78 et client@example.com'
        );

        $this->assertSame('openai', $draft['source']);
        $this->assertCount(1, $draft['lines']);
        $this->assertSame($item->id, $draft['lines'][0]['item_id']);
        $this->assertTrue($draft['lines'][0]['needs_review']);
        $this->assertArrayNotHasKey('price', $draft['lines'][0]);
    }

    public function test_missing_provider_falls_back_to_deterministic_catalog_match(): void
    {
        $branch = Branch::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Burgers', 'status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'name' => 'Cheeseburger', 'item_category_id' => $category->id, 'status' => Status::ACTIVE,
        ]);
        Config::set('voice_order.openai.enabled', false);

        $draft = app(VoiceOrderDraftExtractor::class)->extract($branch->id, 'Je veux deux cheeseburger');
        $this->assertSame('deterministic', $draft['source']);
        $this->assertSame($item->id, $draft['lines'][0]['item_id']);
        $this->assertSame(2, $draft['lines'][0]['quantity']);
    }
}
