<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\EscPosCommandBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [TICKET-WRAP 2026-06-30] The client compo line must never overflow the printer
 * width: a long ingredient list (e.g. "Mexicanos, Cordon Bleu, Mayonnaise, Pain,
 * Salade, Tomate, Oignon" = 67 cols) wraps to several lines, each <= width, with a
 * hanging indent so the continuation aligns under the first ingredient.
 */
class EscPosCommandBuilderWrapTest extends TestCase
{
    public function test_short_text_stays_on_one_line(): void
    {
        $out = EscPosCommandBuilder::wrapIndented('Cordon Bleu, Samouraï', 48, '   ');
        $this->assertSame(['   Cordon Bleu, Samouraï'], $out);
    }

    public function test_long_list_wraps_under_width_with_hanging_indent(): void
    {
        $body = 'Mexicanos, Cordon Bleu, Mayonnaise, Pain, Salade, Tomate, Oignon';
        $out = EscPosCommandBuilder::wrapIndented($body, 48, '   ');
        $this->assertGreaterThan(1, count($out), 'doit wrapper sur plusieurs lignes');
        foreach ($out as $line) {
            $this->assertLessThanOrEqual(48, mb_strlen($line), "ligne trop longue: '$line'");
            $this->assertStringStartsWith('   ', $line, 'indent suspendu manquant');
        }
        // Reassembling the trimmed pieces reconstructs the original (no token lost).
        $joined = implode(' ', array_map('trim', $out));
        $this->assertSame($body, $joined);
    }

    public function test_single_token_longer_than_width_is_hard_split(): void
    {
        $body = str_repeat('A', 60);
        $out = EscPosCommandBuilder::wrapIndented($body, 48, '   ');
        foreach ($out as $line) {
            $this->assertLessThanOrEqual(48, mb_strlen($line), "ligne trop longue: '$line'");
        }
        $this->assertSame($body, implode('', array_map(fn ($l) => substr($l, 3), $out)));
    }

    public function test_empty_body_yields_no_lines(): void
    {
        $this->assertSame([], EscPosCommandBuilder::wrapIndented('', 48, '   '));
    }

    /**
     * CP858 has no Œ/œ/Æ/æ ligature glyph. Default TRANSLIT yields the ugly mid-word
     * "OEuf"; we pre-map French ligatures to their 2-letter form so the printed
     * ticket reads "Oeuf" (real menu supplement "Œuf").
     */
    public function test_french_ligatures_transliterate_cleanly(): void
    {
        $decode = fn (string $b) => iconv('CP858', 'UTF-8//IGNORE', $b);
        $this->assertSame('Oeuf', $decode(EscPosCommandBuilder::encodeForPrinter('Œuf')));
        $this->assertSame('Coeur', $decode(EscPosCommandBuilder::encodeForPrinter('Cœur')));
        $this->assertSame('oeuf', $decode(EscPosCommandBuilder::encodeForPrinter('œuf')));
        // Accented chars that DO exist in CP858 must still round-trip untouched.
        $this->assertSame('Viande supplémentaire', $decode(EscPosCommandBuilder::encodeForPrinter('Viande supplémentaire')));
        $this->assertSame('Algérienne', $decode(EscPosCommandBuilder::encodeForPrinter('Algérienne')));
    }
}
