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

    /**
     * GS V m — coupe papier. m=0 : coupe TOTALE (ticket se détache). m=1 : coupe
     * PARTIELLE (ticket reste accroché au rouleau, ne tombe jamais). Voir
     * config/printing.php `cut.mode`.
     */
    public static function cut(bool $partial = false): string
    {
        return self::GS . 'V' . ($partial ? "\x01" : "\x00");
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
        // CP858 lacks the French ligatures Œ/œ/Æ/æ; pre-map to their 2-letter form so
        // the printer shows "Oeuf" (real supplement "Œuf"), not the TRANSLIT "OEuf".
        $text = strtr($text, ['Œ' => 'Oe', 'œ' => 'oe', 'Æ' => 'Ae', 'æ' => 'ae']);
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

    /**
     * Word-wrap a body string to the printer width with a hanging indent so a long
     * compo/ingredient list never overflows 48 cols (it would otherwise wrap raw,
     * breaking alignment). Splits on spaces; a single token wider than the available
     * space is hard-split. Returns the wrapped lines (each already indented, <= width),
     * NOT including LF — the caller emits them via textLine().
     *
     * @return array<int, string>
     */
    public static function wrapIndented(string $body, int $widthChars = 48, string $indent = '   '): array
    {
        $body = trim(self::sanitize($body));
        if ($body === '') {
            return [];
        }
        $avail = max(1, $widthChars - mb_strlen($indent));
        $lines = [];
        $cur = '';
        foreach (explode(' ', $body) as $word) {
            // Hard-split a token longer than the available width.
            while (mb_strlen($word) > $avail) {
                if ($cur !== '') {
                    $lines[] = $indent . $cur;
                    $cur = '';
                }
                $lines[] = $indent . mb_substr($word, 0, $avail);
                $word = mb_substr($word, $avail);
            }
            $candidate = $cur === '' ? $word : $cur . ' ' . $word;
            if (mb_strlen($candidate) > $avail) {
                $lines[] = $indent . $cur;
                $cur = $word;
            } else {
                $cur = $candidate;
            }
        }
        if ($cur !== '') {
            $lines[] = $indent . $cur;
        }

        return $lines;
    }

    /**
     * [TICKET-WIDTHSAFE 2026-07-01] Word-wrap `text` to width (no indent), one textLine per
     * wrapped line. Combine with alignCenter() for centered multi-line headers/footers qui
     * ne débordent JAMAIS le papier (évite que l'imprimante ré-enroule « 7,40 € » → « 7,\n40 € »).
     */
    public static function textWrap(string $text, int $widthChars = 48): string
    {
        $out = '';
        foreach (self::wordWrapLines($text, $widthChars) as $line) {
            $out .= $line . self::LF;
        }

        return $out;
    }

    /**
     * [TICKET-WIDTHSAFE 2026-07-01] Ligne article : « qty name » à gauche + montant à DROITE,
     * le montant restant TOUJOURS ATOMIQUE (jamais coupé) sur la 1re ligne. Si le nom est trop
     * long, il s'enroule sur des lignes indentées EN DESSOUS (jamais tronqué). Chaque ligne
     * émise fait ≤ widthChars caractères.
     */
    public static function lineItemKV(string $left, string $value, int $widthChars = 48, string $indent = '   '): string
    {
        $w = max(8, $widthChars);
        $value = self::sanitize($value);
        $left = self::sanitize($left);
        $vlen = mb_strlen($value);
        $firstAvail = max(1, $w - $vlen - 1);
        $contAvail = max(1, $w - mb_strlen($indent));

        $segments = [];
        $cur = '';
        $avail = $firstAvail;
        $indentFor = '';
        foreach (explode(' ', trim($left)) as $word) {
            while (mb_strlen($word) > $avail) {
                if ($cur !== '') {
                    $segments[] = [$indentFor, $cur];
                    $cur = '';
                }
                $indentFor = empty($segments) ? '' : $indent;
                $avail = empty($segments) ? $firstAvail : $contAvail;
                $take = $avail;
                $segments[] = [$indentFor, mb_substr($word, 0, $take)];
                $word = mb_substr($word, $take);
                $indentFor = $indent;
                $avail = $contAvail;
            }
            $cand = $cur === '' ? $word : $cur . ' ' . $word;
            if (mb_strlen($cand) > $avail) {
                $segments[] = [$indentFor, $cur];
                $cur = $word;
                $indentFor = $indent;
                $avail = $contAvail;
            } else {
                $cur = $cand;
            }
        }
        if ($cur !== '' || empty($segments)) {
            $segments[] = [$indentFor, $cur];
        }

        $out = '';
        foreach ($segments as $i => [$ind, $seg]) {
            $text = $ind . $seg;
            if ($i === 0) {
                $pad = max(1, $w - mb_strlen($text) - $vlen);
                $out .= $text . str_repeat(' ', $pad) . $value . self::LF;
            } else {
                $out .= $text . self::LF;
            }
        }

        return $out;
    }

    /** @return array<int,string> Pur word-wrap à `widthChars` (coupe dure les mots trop longs). */
    private static function wordWrapLines(string $text, int $widthChars): array
    {
        $text = trim(self::sanitize($text));
        if ($text === '') {
            return [];
        }
        $w = max(1, $widthChars);
        $lines = [];
        $cur = '';
        foreach (explode(' ', $text) as $word) {
            while (mb_strlen($word) > $w) {
                if ($cur !== '') {
                    $lines[] = $cur;
                    $cur = '';
                }
                $lines[] = mb_substr($word, 0, $w);
                $word = mb_substr($word, $w);
            }
            $cand = $cur === '' ? $word : $cur . ' ' . $word;
            if (mb_strlen($cand) > $w) {
                $lines[] = $cur;
                $cur = $word;
            } else {
                $cur = $cand;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }

        return $lines;
    }

    private static function sanitize(string $text): string
    {
        // [TICKET-WIDTHSAFE 2026-07-01] Normaliser les ligatures AVANT la mise en page :
        // « Œuf » compte 3 caractères en mb_strlen mais s'imprime « Oeuf » (4) → la largeur
        // calculée était fausse d'1 colonne (débordement / « € » coupé). En pré-mappant ici,
        // mb_strlen == largeur imprimée. (encodeForPrinter garde le même strtr, idempotent.)
        $text = strtr($text, ['Œ' => 'Oe', 'œ' => 'oe', 'Æ' => 'Ae', 'æ' => 'ae']);

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? '';
    }

    private static function truncate(string $text, int $widthChars): string
    {
        return mb_strimwidth($text, 0, max(1, $widthChars), '', 'UTF-8');
    }
}
