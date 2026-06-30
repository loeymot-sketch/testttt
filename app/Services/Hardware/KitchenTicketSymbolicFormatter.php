<?php

namespace App\Services\Hardware;

/**
 * [KITCHEN-SYMBOLS 2026-06-28] PHP twin of resources/js/helpers/kdsSymbolic.js.
 *
 * Turns an order item's composition_snapshot into the owner's symbolic kitchen
 * shorthand so the PRINTED kitchen ticket reads exactly like the KDS screen:
 *
 *   Line 1 : [Support] | [Produit] | [Taille] | [Viande(s)] | [Crudités] | [Sauce(s)]
 *            e.g.  G | SANDWICH | P | STO | SAM
 *   Line 2 : "+ Cheddar" per paid supplement
 *   Line 3 : "MENU" / "F"
 *
 * The symbol tables here MUST stay in lockstep with kdsSymbolic.js (parity tests
 * mirror tests/js/kdsSymbolic.spec.js).
 */
final class KitchenTicketSymbolicFormatter
{
    /** @var array<int, array{0:string,1:string}> regex => symbol */
    private const MEAT_TABLE = [
        ['/hach|steak|b[oe]uf/', 'K'],
        ['/poulet/', 'P'],
        ['/tender/', 'Tender'],
        ['/nugget/', 'Nug'],
        ['/mexic/', 'Mex'],
        ['/fricadelle/', 'Frec'],
        ['/cordon/', 'Cordon'],
    ];

    private const SAUCE_TABLE = [
        ['/mayo/', 'MAY'],
        ['/samou/', 'SAM'],
        ['/hannibal/', 'HAN'],
        ['/curry/', 'CURY'],
        ['/andalouse/', 'AND'],
        ['/blanche/', 'BL'],
        ['/ketchup/', 'KTP'],
        ['/burger/', 'Burg'],
        ['/algerien/', 'ALG'],
        ['/barbecue|bbq/', 'BBQ'],
        ['/harissa/', 'HAR'],
        ['/fromage/', 'FRO'],
        ['/spicy/', 'SPI'],
    ];

    private const CRUDITE_TABLE = [
        ['/salade/', 'S'],
        ['/tomate/', 'T'],
        ['/oignon/', 'O'],
    ];

    private const CRUDITE_ORDER = ['S', 'T', 'O'];

    /** lowercase, strip diacritics, trim — for keyword matching. */
    private function norm(?string $s): string
    {
        $s = (string) $s;
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }

        return trim(mb_strtolower($s));
    }

    public function meatSymbol(?string $name): string
    {
        $n = $this->norm($name);
        foreach (self::MEAT_TABLE as [$re, $sym]) {
            if (preg_match($re, $n)) {
                return $sym;
            }
        }

        return '';
    }

    public function sauceSymbol(?string $name): string
    {
        $n = $this->norm($name);
        foreach (self::SAUCE_TABLE as [$re, $sym]) {
            if (preg_match($re, $n)) {
                return $sym;
            }
        }

        return mb_strtoupper(mb_substr(preg_replace('/^sauce\s+/', '', $n), 0, 3));
    }

    public function cruditeSymbol(?string $name): string
    {
        $n = $this->norm($name);
        foreach (self::CRUDITE_TABLE as [$re, $sym]) {
            if (preg_match($re, $n)) {
                return $sym;
            }
        }

        return '';
    }

    /** A free garniture has no price; a paid supplement (e.g. "Oignons frits") does. */
    private function isFreeExtra(array $e): bool
    {
        $price = (float) ($e['unit_price'] ?? $e['line_total'] ?? 0);

        return $price <= 0;
    }

    public function supportSymbol(?string $name): string
    {
        $n = $this->norm($name);
        if (str_contains($n, 'galette')) {
            return 'G';
        }
        if (str_contains($n, 'pain')) {
            return 'S';
        }

        return '';
    }

    /** @param array<string,mixed> $snapshot */
    public function mainLine(string $itemName, array $snapshot): string
    {
        [$produit, $taille] = $this->produitAndSize($itemName);
        $support = '';
        $viandes = [];
        $sauces = [];
        $crud = [];

        foreach (($snapshot['lines'] ?? []) as $l) {
            $attrName = (string) ($l['attribute_name'] ?? '');
            if ($attrName !== '') {
                // snapshot shape: attribute_name = group, variation_name = value.
                $group = $attrName;
                $value = (string) ($l['variation_name'] ?? $l['name'] ?? '');
            } else {
                // legacy shape: variation_name = group, name = value (parity with JS).
                $group = (string) ($l['variation_name'] ?? '');
                $value = (string) ($l['name'] ?? '');
                // Defensive: a malformed snapshot line (attribute_name null) keeps the
                // value in variation_name → recover it so a meat never vanishes.
                if ($value === '' && ! empty($l['variation_name'])) {
                    $value = (string) $l['variation_name'];
                }
            }
            if ($value === '') {
                continue;
            }
            $g = $this->norm($group);
            if (preg_match('/viande|meat/', $g)) {
                $viandes[] = $this->meatSymbol($value) ?: mb_strtoupper(mb_substr($this->norm($value), 0, 3));
            } elseif (str_contains($g, 'sauce')) {
                $sauces[] = $this->sauceSymbol($value);
            } elseif (preg_match('/pain|galette|support|bread/', $g)) {
                $support = $this->supportSymbol($value) ?: $support;
            } elseif (preg_match('/taille|size|portion/', $g)) {
                $taille = $taille !== '' ? $taille : mb_strtoupper($value);
            } elseif ($this->meatSymbol($value) !== '') {
                $viandes[] = $this->meatSymbol($value);
            }
        }

        foreach (($snapshot['extras'] ?? []) as $e) {
            $name = (string) ($e['extra_name'] ?? $e['name'] ?? '');
            $cs = $this->cruditeSymbol($name);
            // Only FREE garnitures (Salade/Tomate/Oignon, price 0) fold into the
            // crudités slot. A PAID extra that happens to match (e.g. "Oignons frits"
            // 0,90€) is a supplement, not a crudité.
            if ($cs !== '' && $this->isFreeExtra($e)) {
                $crud[$cs] = true;
            }
        }

        // Owner rule: tacos (and galette products) show the support first, default G.
        if ($support === '' && (preg_match('/\btacos?\b/', $this->norm($itemName)) || str_contains($this->norm($itemName), 'galette'))) {
            $support = 'G';
        }

        $crudites = implode('', array_filter(self::CRUDITE_ORDER, fn ($c) => isset($crud[$c])));

        $segments = array_filter([
            $support,
            $produit,
            $taille,
            implode(' ', $viandes),
            $crudites,
            implode(' ', $sauces),
        ], fn ($x) => $x !== '' && $x !== null);

        return implode(' | ', $segments);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<string>  "+ Cheddar" lines (crudités excluded — they fold into Line 1)
     */
    public function supplementLines(array $snapshot): array
    {
        $out = [];
        foreach (($snapshot['extras'] ?? []) as $e) {
            $name = (string) ($e['extra_name'] ?? $e['name'] ?? '');
            // Skip only FREE garnitures (folded into Line 1). Paid extras — even
            // crudité-named ones like "Oignons frits" — stay as supplement lines.
            if ($name === '' || ($this->cruditeSymbol($name) !== '' && $this->isFreeExtra($e))) {
                continue;
            }
            $q = (int) ($e['quantity'] ?? 1);
            $suffix = $q > 1 ? " ×{$q}" : '';
            $out[] = "+ {$name}{$suffix}";
        }

        return $out;
    }

    /** Un item « Menu (Frites + Boisson) » / « Formule … » → affiché juste « MENU » en cuisine. */
    public function isMenuItem(string $name): bool
    {
        return (bool) preg_match('/\bmenu\b|formule/u', $this->norm($name));
    }

    /** Sauce frites du menu (depuis l'instruction) → SYMBOLE court (Andalouse → AND). */
    public function fritesSauceSymbol(?string $instruction): string
    {
        if (! is_string($instruction) || $instruction === '') {
            return '';
        }
        if (preg_match('/sauce\s*frites\s*:\s*([^\n]+)/iu', $instruction, $m)) {
            return $this->sauceSymbol(trim($m[1]));
        }

        return '';
    }

    /** @param array<string,mixed> $snapshot */
    public function menuLine(array $snapshot): string
    {
        $addons = $snapshot['addons'] ?? [];
        foreach ($addons as $a) {
            if (str_starts_with(strtolower((string) ($a['role'] ?? '')), 'menu_')) {
                return 'MENU';
            }
        }
        foreach ($addons as $a) {
            if (preg_match('/frite/', $this->norm((string) ($a['addon_name'] ?? $a['name'] ?? '')))) {
                return 'F';
            }
        }

        return '';
    }

    /**
     * Strip the echoed product-name line and the duplicate composition blob the
     * frozen pos-wizard writes into `instruction`; keep only free client notes
     * and bracketed notes. PHP twin of kdsSymbolic / sanitizeKdsInstruction.
     */
    public function cleanInstruction(?string $raw, string $itemName): string
    {
        if (! is_string($raw) || $raw === '') {
            return '';
        }
        $name = mb_strtoupper(trim($itemName));
        $compoRe = '/(^|\s)(Viandes?|Sauce|Suppl[ée]ment|Pain|Galette)\s*:/iu';
        $insideBracket = false;
        $kept = [];
        foreach (preg_split('/\n/', $raw) as $ln) {
            $t = trim($ln);
            if ($t === '') {
                continue;
            }
            if ($name !== '' && mb_strtoupper($t) === $name) {
                continue; // echoed product name
            }
            if ($insideBracket) {
                if (str_contains($t, ']')) {
                    $insideBracket = false;
                }
                $kept[] = $t;

                continue;
            }
            if (str_starts_with($t, '[')) {
                if (! str_contains($t, ']')) {
                    $insideBracket = true;
                }
                $kept[] = $t;

                continue;
            }
            // [KITCHEN-MENU 2026-06-30] Menu + sauce frites → représentés par la ligne
            // « MENU : SYM » : on les retire de l'instruction (anti double-menu / verbeux).
            if (preg_match('/sauce\s*frites|menu\s*\(\s*frites|^\+\s*menu\b/iu', $t)) {
                continue;
            }
            if (preg_match('/^[+↳]/u', $t)) {
                $kept[] = $t;

                continue;
            }
            if (preg_match('/^-\s/', $t)) {
                continue; // bare crudité removal (Line 1 covers it)
            }
            if (preg_match($compoRe, $t)) {
                continue; // compo blob → drop (dup of Line 1)
            }
            $kept[] = $t;
        }

        return trim(implode("\n", $kept));
    }

    /** @return array{0:string,1:string} [produit, taille] — only M/L/XL trailing tokens */
    private function produitAndSize(string $itemName): array
    {
        $raw = trim($itemName);
        if (preg_match('/\s+(XL|L|M)\s*$/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $produit = mb_strtoupper(trim(mb_substr($raw, 0, $m[0][1])));

            return [$produit, mb_strtoupper($m[1][0])];
        }

        return [mb_strtoupper($raw), ''];
    }
}
