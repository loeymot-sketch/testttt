<?php

namespace App\Services\Hardware;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] Byte builder for a 2x20 pole customer display
 * (the counter SAGA blue VFD). These displays speak the de-facto CD5220 command
 * set over a serial line:
 *
 *   ESC @            (0x1B 0x40)            initialise
 *   CLR              (0x0C)                 clear the whole screen
 *   ESC Q A <s> CR   (0x1B 0x51 0x41 … 0x0D) write the UPPER line (auto-padded)
 *   ESC Q B <s> CR   (0x1B 0x51 0x42 … 0x0D) write the LOWER line
 *   ESC t n          (0x1B 0x74 n)          select character code page
 *
 * Text is assembled in UTF-8 and transcoded ONCE to the display code page
 * (CP858 → € + FR accents) at the end, exactly like the receipt renderer — this
 * is why the SAGA currently shows mojibake: it is fed bytes in the wrong charset.
 */
final class CustomerDisplayCommandBuilder
{
    public const COLS = 20;

    public static function init(): string
    {
        return "\x1B\x40";
    }

    public static function clear(): string
    {
        return "\x0C";
    }

    /** ESC t n — select code page (19 = CP858). */
    public static function selectCodePage(int $page = 19): string
    {
        return "\x1B\x74" . chr(max(0, min(255, $page)));
    }

    /** ESC Q A <20 chars> CR — upper line, padded/truncated to 20 columns. */
    public static function upperLine(string $text): string
    {
        return "\x1B\x51\x41" . self::fit($text) . "\x0D";
    }

    /** ESC Q B <20 chars> CR — lower line. */
    public static function lowerLine(string $text): string
    {
        return "\x1B\x51\x42" . self::fit($text) . "\x0D";
    }

    /** Pad (right) or truncate a string to exactly COLS columns. */
    public static function fit(string $text): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        $clean = mb_strimwidth($clean, 0, self::COLS, '', 'UTF-8');
        $pad = self::COLS - mb_strlen($clean);

        return $clean . ($pad > 0 ? str_repeat(' ', $pad) : '');
    }

    /** Right-align a value within COLS (e.g. the total). */
    public static function fitRight(string $text): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        $clean = mb_strimwidth($clean, 0, self::COLS, '', 'UTF-8');
        $pad = self::COLS - mb_strlen($clean);

        return ($pad > 0 ? str_repeat(' ', $pad) : '') . $clean;
    }
}
