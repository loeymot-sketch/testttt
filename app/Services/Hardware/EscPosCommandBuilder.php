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

    /**
     * [OWNER8 2026-07-06] ESC - n — soulignement matériel (0=off, 1=on 1 point).
     * Utilisé pour le symbole crudité « O̲ » (oignons cuits) : CP858 ne connaît
     * pas U+0332, encodeForPrinter() traduit la séquence X+U+0332 en ESC-1 X ESC-0.
     */
    public static function underline(bool $on): string
    {
        return self::ESC . '-' . ($on ? "\x01" : "\x00");
    }

    /**
     * [CONVERSION TICKET 2026-08-08] Inversion vidéo — GS B n. Blanc sur NOIR.
     *
     * C'est le SEUL effet d'une imprimante thermique qui crée un vrai contraste : elle n'a ni
     * couleur, ni graisse variable, ni taille intermédiaire. Un texte gras double-taille reste du
     * noir sur blanc parmi du noir sur blanc ; un bandeau inversé est la seule chose qui « saute
     * aux yeux » sur un ticket que le client regarde deux secondes.
     *
     * Le fond noir ne couvre QUE les caractères réellement imprimés : pour obtenir un bandeau
     * pleine largeur, il faut donc imprimer des ESPACES autour du texte — voir la méthode `band()`
     * du générateur de ticket promo, où ce remplissage est le visuel lui-même.
     *
     * Supporté par les imprimantes ESC/POS courantes (Epson et compatibles SAGA/SK1). Une
     * imprimante qui l'ignorerait rendrait simplement du noir sur blanc : dégradation propre,
     * jamais de caractères parasites.
     */
    public static function invert(bool $on): string
    {
        return self::GS . 'B' . chr($on ? 1 : 0);
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
     * [FLYER PROMO 2026-08-08] Image en points (`GS v 0`) — le logo du restaurant.
     *
     * Une thermique n'imprime QUE du noir ou du blanc, un point à la fois. Il
     * faut donc convertir l'image en bitmap 1 bit avant de l'envoyer ; aucune
     * primitive de ce genre n'existait dans le projet.
     *
     * Choix du TRAMAGE plutôt que d'un seuil brut : le logo Le Cayenne mélange
     * du texte noir (qui doit rester net) et un piment orange en aplat. Un seuil
     * simple transforme l'orange en un pâté noir informe ou l'efface
     * entièrement ; le tramage Floyd-Steinberg restitue un dégradé lisible en
     * points, exactement comme les photos des journaux. Le texte, lui, reste
     * franc parce qu'il est déjà aux extrêmes.
     *
     * La transparence est aplatie sur du BLANC : sans ça, un PNG transparent
     * ressort en aplat noir (le canal alpha vaut 0 = « sombre » pour un calcul
     * de luminance naïf) et l'imprimante crache un rectangle plein.
     *
     * @param  string  $path           chemin absolu de l'image source
     * @param  int     $maxWidthDots   largeur cible en points (arrondie au multiple de 8 inférieur)
     * @return string  octets ESC/POS, ou chaîne vide si l'image est illisible
     */
    public static function rasterImage(string $path, int $maxWidthDots = 512): string
    {
        if (! function_exists('imagecreatefromstring') || ! is_readable($path)) {
            return '';
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return '';
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return '';
        }

        try {
            $srcW = imagesx($src);
            $srcH = imagesy($src);
            if ($srcW < 1 || $srcH < 1) {
                return '';
            }

            // La largeur DOIT être un multiple de 8 : chaque octet transporte
            // 8 points horizontaux.
            $targetW = (int) (min($maxWidthDots, $srcW * 4) & ~7);
            if ($targetW < 8) {
                return '';
            }
            $targetH = max(1, (int) round($srcH * ($targetW / $srcW)));

            $dst = imagecreatetruecolor($targetW, $targetH);
            // Fond blanc AVANT la copie — voir docblock (transparence).
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, imagecolorallocate($dst, 255, 255, 255));
            imagealphablending($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

            // Luminance perçue, puis tramage par diffusion d'erreur.
            $lum = [];
            for ($y = 0; $y < $targetH; $y++) {
                for ($x = 0; $x < $targetW; $x++) {
                    $rgb = imagecolorat($dst, $x, $y);
                    $lum[$y][$x] =
                        0.299 * (($rgb >> 16) & 0xFF)
                        + 0.587 * (($rgb >> 8) & 0xFF)
                        + 0.114 * ($rgb & 0xFF);
                }
            }

            $bytesPerRow = intdiv($targetW, 8);
            $data = '';

            for ($y = 0; $y < $targetH; $y++) {
                $row = str_repeat("\x00", $bytesPerRow);

                for ($x = 0; $x < $targetW; $x++) {
                    $old = $lum[$y][$x];
                    $new = $old < 128 ? 0.0 : 255.0;
                    $err = $old - $new;

                    if ($new === 0.0) {
                        // 1 = point encré.
                        $i = intdiv($x, 8);
                        $row[$i] = chr(ord($row[$i]) | (0x80 >> ($x % 8)));
                    }

                    // Floyd-Steinberg : 7/16 à droite, 3/16 5/16 1/16 en dessous.
                    if ($x + 1 < $targetW)                     $lum[$y][$x + 1]     += $err * 7 / 16;
                    if ($y + 1 < $targetH) {
                        if ($x > 0)                            $lum[$y + 1][$x - 1] += $err * 3 / 16;
                                                               $lum[$y + 1][$x]     += $err * 5 / 16;
                        if ($x + 1 < $targetW)                 $lum[$y + 1][$x + 1] += $err * 1 / 16;
                    }
                }

                $data .= $row;
            }

            imagedestroy($dst);

            return self::GS . 'v0' . "\x00"
                . chr($bytesPerRow % 256) . chr(intdiv($bytesPerRow, 256))
                . chr($targetH % 256) . chr(intdiv($targetH, 256))
                . $data;
        } finally {
            if (is_resource($src) || $src instanceof \GdImage) {
                @imagedestroy($src);
            }
        }
    }

    /**
     * [FLYER PROMO 2026-08-07] QR code NATIF ESC/POS (`GS ( k`).
     *
     * Le projet n'avait aucune primitive QR côté imprimante : le seul QR
     * existant (`simplesoftwareio/simple-qrcode`, QR de tables) produit un PNG,
     * inimprimable tel quel sur une thermique sans passer par un raster.
     *
     * On utilise donc le générateur INTERNE de l'imprimante plutôt qu'une
     * image : le rendu est net à toutes les tailles, l'impression est
     * instantanée, et on n'envoie que quelques dizaines d'octets au lieu d'un
     * bitmap. C'est le chemin standard sur les Epson TM et leurs compatibles.
     *
     * ⚠️ Toutes les thermiques ne gèrent PAS `GS ( k` (les modèles d'entrée de
     * gamme l'ignorent silencieusement — rien ne s'imprime, aucune erreur).
     * L'appelant DOIT donc toujours imprimer l'URL en clair à côté du QR, pour
     * qu'un ticket reste exploitable même si le carré ne sort pas.
     *
     * @param  string  $data        contenu encodé (ici : une URL)
     * @param  int     $moduleSize  1..16 — taille d'un module en points (6 ≈ 3 cm sur 80 mm)
     * @param  string  $ecc         L|M|Q|H — correction d'erreur. M par défaut :
     *                              un ticket thermique se froisse et pâlit vite,
     *                              L serait trop fragile, H mangerait trop de place.
     */
    public static function qrCode(string $data, int $moduleSize = 6, string $ecc = 'M'): string
    {
        if ($data === '') {
            return '';
        }

        $eccByte = match (strtoupper($ecc)) {
            'L'     => "\x30", // 7 %
            'Q'     => "\x32", // 25 %
            'H'     => "\x33", // 30 %
            default => "\x31", // M — 15 %
        };

        $moduleSize = max(1, min(16, $moduleSize));

        // Fonction 165 — modèle 2 (le modèle universellement lu par les téléphones).
        $model = self::GS . '(k' . "\x04\x00" . "\x31\x41" . "\x32\x00";

        // Fonction 167 — taille du module.
        $size = self::GS . '(k' . "\x03\x00" . "\x31\x43" . chr($moduleSize);

        // Fonction 169 — niveau de correction d'erreur.
        $level = self::GS . '(k' . "\x03\x00" . "\x31\x45" . $eccByte;

        // Fonction 180 — stockage des données. La longueur inclut les 3 octets
        // d'en-tête (cn, fn, m), d'où le +3 ; elle est encodée en petit-boutien
        // sur deux octets.
        $len = strlen($data) + 3;
        $store = self::GS . '(k' . chr($len % 256) . chr(intdiv($len, 256)) . "\x31\x50\x30" . $data;

        // Fonction 181 — impression du symbole stocké.
        $print = self::GS . '(k' . "\x03\x00" . "\x31\x51\x30";

        return $model . $size . $level . $store . $print;
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
        // [OWNER8 2026-07-06] U+0332 (combining low line — symbole crudité « O̲ »
        // oignons cuits) n'existe pas en CP858 : iconv le TRANSLIT en « _ » séparé
        // ou le droppe. Traduction ICI (et UNIQUEMENT ici — sanitize() strip les
        // octets 0x00-0x1F, un ESC injecté via textLine serait détruit) en
        // soulignement MATÉRIEL : X+U+0332 → ESC - 1, X, ESC - 0. Les octets ESC
        // (ASCII 0x1B) traversent iconv inchangés.
        $text = preg_replace('/(.)\x{0332}/u', "\x1B-\x01\$1\x1B-\x00", $text) ?? $text;
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
        $padding = max(0, intdiv($widthChars - self::width($text), 2));

        return str_repeat(' ', $padding) . $text . self::LF;
    }

    public static function lineKV(string $label, string $value, int $widthChars = 48): string
    {
        $value = self::truncate(self::sanitize($value), max(1, $widthChars - 2));
        $maxLabel = max(1, $widthChars - self::width($value) - 1);
        $label = self::truncate(self::sanitize($label), $maxLabel);
        $padding = max(1, $widthChars - self::width($label) - self::width($value));

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
        $avail = max(1, $widthChars - self::width($indent));
        $lines = [];
        $cur = '';
        foreach (explode(' ', $body) as $word) {
            // Hard-split a token longer than the available width.
            // (split par code points — un mot > largeur contenant U+0332 n'existe pas
            // en pratique : le segment crudités « STOO̲ » fait 4 colonnes visibles)
            while (self::width($word) > $avail) {
                if ($cur !== '') {
                    $lines[] = $indent . $cur;
                    $cur = '';
                }
                $lines[] = $indent . mb_substr($word, 0, $avail);
                $word = mb_substr($word, $avail);
            }
            $candidate = $cur === '' ? $word : $cur . ' ' . $word;
            if (self::width($candidate) > $avail) {
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
        $vlen = self::width($value);
        $firstAvail = max(1, $w - $vlen - 1);
        $contAvail = max(1, $w - self::width($indent));

        $segments = [];
        $cur = '';
        $avail = $firstAvail;
        $indentFor = '';
        foreach (explode(' ', trim($left)) as $word) {
            while (self::width($word) > $avail) {
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
            if (self::width($cand) > $avail) {
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
                $pad = max(1, $w - self::width($text) - $vlen);
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
            while (self::width($word) > $w) {
                if ($cur !== '') {
                    $lines[] = $cur;
                    $cur = '';
                }
                $lines[] = mb_substr($word, 0, $w);
                $word = mb_substr($word, $w);
            }
            $cand = $cur === '' ? $word : $cur . ' ' . $word;
            if (self::width($cand) > $w) {
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

    /**
     * [OWNER8 2026-07-06] Largeur VISIBLE (colonnes imprimées) : U+0332 (combining
     * low line — « O̲ » oignons cuits) compte 0 colonne, il devient un soulignement
     * ESC - n à l'encodage (encodeForPrinter) et ne consomme aucune colonne papier.
     * Utilisée par TOUS les calculs wrap/pad (mb_strlen compterait 1 de trop).
     */
    private static function width(string $text): int
    {
        return mb_strlen($text) - mb_substr_count($text, "\u{0332}");
    }

    private static function truncate(string $text, int $widthChars): string
    {
        return mb_strimwidth($text, 0, max(1, $widthChars), '', 'UTF-8');
    }
}
