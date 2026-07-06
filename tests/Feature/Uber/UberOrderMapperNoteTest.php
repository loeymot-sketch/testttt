<?php

namespace Tests\Feature\Uber;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Uber\UberOrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W6-ADV B-4 2026-07-06] Note client Uber → canal SÛR cuisine. Les sanitizers jumeaux
 * (cleanInstruction PHP / sanitizeKdsInstruction JS) droppent toute ligne 100%
 * MAJUSCULES (écho du nom produit pos-wizard) : une vraie note Uber en capitales
 * (« SANS OIGNONS SVP » — fréquent) était SILENCIEUSEMENT perdue écran + ticket
 * (latent food-safety au branchement Uber prod). Le mapper bracket désormais la note
 * brute ([...], pattern wizard préservé par le sanitize).
 */
class UberOrderMapperNoteTest extends TestCase
{
    use RefreshDatabase;

    private function makeCatalogItem(string $name = 'Tacos M'): Item
    {
        $cat = ItemCategory::query()->firstOrCreate(
            ['slug' => 'tacos'],
            ['name' => 'Tacos', 'status' => Status::ACTIVE, 'channels' => []]
        );

        return Item::query()->create([
            'item_category_id' => $cat->id,
            'slug' => 'tacos-m',
            'name' => $name,
            'price' => 8.5,
            'status' => Status::ACTIVE,
            'is_available' => 1,
            'order' => 0,
        ]);
    }

    private function mapLineFor(string $note, string $title = 'Tacos M'): array
    {
        $mapper = app(UberOrderMapper::class);
        $mapped = $mapper->map([
            'display_id' => 'ABC-1234',
            'cart' => [
                'items' => [[
                    'title' => $title,
                    'quantity' => 1,
                    'special_instructions' => $note,
                    'price' => ['unit_price' => ['amount' => 850], 'total_price' => ['amount' => 850]],
                ]],
            ],
        ]);

        return $mapped['items'][0];
    }

    public function test_uppercase_uber_note_is_bracketed_and_survives_kitchen_sanitize(): void
    {
        $this->makeCatalogItem();
        $line = $this->mapLineFor('SANS OIGNONS SVP');

        $this->assertSame('[SANS OIGNONS SVP]', $line['instruction'], 'note Uber bracketée (pattern wizard)');

        // Preuve bout-en-bout : le sanitize cuisine PRÉSERVE la note bracketée
        // (la même note brute serait droppée par la règle anti-écho MAJUSCULES).
        $f = app(KitchenTicketSymbolicFormatter::class);
        $this->assertSame(
            '[SANS OIGNONS SVP]',
            $f->cleanInstruction($line['instruction'], 'Tacos M'),
            'la note capitale bracketée survit au sanitize cuisine'
        );
        $this->assertSame(
            '',
            $f->cleanInstruction('SANS OIGNONS SVP', 'Tacos M'),
            'contrôle : la même note NON bracketée serait perdue (règle anti-écho)'
        );
    }

    public function test_lowercase_note_bracketed_and_multiline_kept(): void
    {
        $this->makeCatalogItem();
        $line = $this->mapLineFor("bien cuit svp\nSANS SEL");

        $this->assertSame("[bien cuit svp\nSANS SEL]", $line['instruction']);

        $f = app(KitchenTicketSymbolicFormatter::class);
        $this->assertSame(
            "[bien cuit svp\nSANS SEL]",
            $f->cleanInstruction($line['instruction'], 'Tacos M'),
            'note multi-ligne : les lignes capitales de continuation restent (bracket ouvert)'
        );
    }

    public function test_empty_note_stays_empty_and_prebracketed_not_doubled(): void
    {
        $this->makeCatalogItem();

        $this->assertSame('', $this->mapLineFor('')['instruction'], 'pas de « [] » inventé');
        $this->assertSame('[deja notee]', $this->mapLineFor('[deja notee]')['instruction'], 'pas de double bracket');
    }

    public function test_unmapped_item_keeps_uber_prefix_and_bracketed_note(): void
    {
        // catalogue vide → placeholder technique + titre réel en instruction
        $line = $this->mapLineFor('NO ONIONS', 'ZZZ Produit Inconnu');

        $this->assertStringContainsString('[UBER NON MAPPÉ: ZZZ Produit Inconnu]', $line['instruction']);
        $this->assertStringContainsString('[NO ONIONS]', $line['instruction']);

        $f = app(KitchenTicketSymbolicFormatter::class);
        $clean = $f->cleanInstruction($line['instruction'], 'Article Uber (non mappé)');
        $this->assertStringContainsString('NO ONIONS', $clean, 'note capitale visible en cuisine même sur item non mappé');
    }
}
