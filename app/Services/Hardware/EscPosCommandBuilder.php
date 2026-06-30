<?php

namespace App\Services\Hardware;

final class EscPosCommandBuilder
{
    public const ESC = "\x1B";
    public const GS = "\x1D";
    public const LF = "\x0A";

    public static function init(): string
    {
        return self::ESC . '@';
    }

    public static function alignLeft(): string
    {
        return self::ESC . 'a' . "\x00";
    }

    public static function alignCenter(): string
    {
        return self::ESC . 'a' . "\x01";
    }

    public static function alignRight(): string
    {
        return self::ESC . 'a' . "\x02";
    }

    public static function bold(bool $on): string
    {
        return self::ESC . 'E' . ($on ? "\x01" : "\x00");
    }

    public static function doubleSize(bool $on): string
    {
        return self::GS . '!' . ($on ? "\x11" : "\x00");
    }

    /**
     * [TICKET-BIG 2026-06-30] GS ! n — taille caractère, multiplicateurs 1..8.
     * n = ((largeur-1) << 4) | (hauteur-1). Pour un ticket « grand & lisible »
     * sans réduire le nombre de colonnes (donc sans débordement), on agrandit la
     * HAUTEUR seule (textSize(1,2)) : le corps reste à 48 car/ligne mais ~2× plus haut.
     */
    public static function textSize(int $w = 1, int $h = 1): string
    {
        $w = max(1, min(8, $w));
        $h = max(1, min(8, $h));

        return self::GS . '!' . chr((($w - 1) << 4) | ($h - 1));
    }

    /** Corps « grand » lisible : hauteur ×2, largeur inchangée (48 colonnes préservées). */
    public static function doubleHeight(bool $on): string
    {
        return self::textSize(1, $on ? 2 : 1);
    }

    public static function feed(int $lines = 1): string
    {
        return str_repeat(self::LF, max(1, $lines));
    }

    public static function cut(): string
    {
        return self::GS . 'V' . "\x00";
    }

    /**
     * Raw ESC/POS pulse for cash drawer (pin 2, 25ms on / 250ms off — standard Epson).
     */
    public static function openDrawerCommand(): string
    {
        return chr(0x1B) . chr(0x70) . chr(0x00) . chr(0x19) . chr(0xFA);
    }

    public static function openDrawer(): string
    {
        return self::openDrawerCommand();
    }

    /**
     * Select an ESC/POS code page (default 19 = CP858 / multilingual Latin1
     * with Euro sign — the most common default for thermal printers in
     * European deployments). Without this, UTF-8 accents print as "?" or
     * mojibake on most ESC/POS hardware.
     *
     * [V14 C-β / FINDING C-β-T15-1 P2]
     */
    public static function selectCodePage(int $page = 19): string
    {
        return self::ESC . 't' . chr(max(0, min(255, $page)));
    }

    /**
     * Transcode a UTF-8 string to a single-byte encoding compatible with
     * the printer's selected code page. Defaults to CP858 (most common
     * European thermal default). Falls back to ASCII translit on failure.
     *
     * [V14 C-β / FINDING C-β-T15-1 P2] Without this transcoding, UTF-8
     * multibyte chars (é, à, €) print as "?" on most printers AND inflate
     * `mb_strlen` vs the printer's column counter (which is bytes), so the
     * `lineKV` padding drifts on accented strings.
     */
    public static function encodeForPrinter(string $text, string $encoding = 'CP858'): string
    {
        if ($text === '') return '';
        // First try iconv with TRANSLIT (most printers expect single-byte)
        $converted = @iconv('UTF-8', $encoding . '//TRANSLIT//IGNORE', $text);
        if ($converted !== false) return $converted;
        // mbstring fallback (no TRANSLIT, may drop chars)
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, $encoding, 'UTF-8');
            if (is_string($converted)) return $converted;
        }
        // Last resort : strip non-ASCII (always-printable subset)
        return preg_replace('/[^\x20-\x7E\x0A\x0D]/', '?', $text) ?? '';
    }

    public static function separator(string $char = '-', int $widthChars = 48): string
    {
        return str_repeat(mb_substr($char, 0, 1) ?: '-', max(1, $widthChars)) . self::LF;
    }

    public static function textLine(string $text = ''): string
    {
        return self::sanitize($text) . self::LF;
    }

    public static function centerLine(string $text, int $widthChars = 48): string
    {
        $text = self::truncate(self::sanitize($text), $widthChars);
        $padding = max(0, intdiv($widthChars - mb_strlen($text), 2));

        return str_repeat(' ', $padding) . $text . self::LF;
    }

    public static function lineKV(string $label, string $value, int $widthChars = 48): string
    {
        $value = self::truncate(self::sanitize($value), max(1, $widthChars - 2));
        $maxLabel = max(1, $widthChars - mb_strlen($value) - 1);
        $label = self::truncate(self::sanitize($label), $maxLabel);
        $padding = max(1, $widthChars - mb_strlen($label) - mb_strlen($value));

        return $label . str_repeat(' ', $padding) . $value . self::LF;
    }

    private static function sanitize(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? '';
    }

    private static function truncate(string $text, int $widthChars): string
    {
        return mb_strimwidth($text, 0, max(1, $widthChars), '', 'UTF-8');
    }
}
