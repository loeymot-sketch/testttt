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
        // [OWNER8 2026-07-06] Oignons CUITS → O̲ (O + U+0332) — AVANT /oignon/
        // (sinon le cru matcherait d'abord). Jumeau STRICT de kdsSymbolic.js
        // (même string O+U+0332 → parité écran↔ticket). Le soulignement matériel
        // ESC - n est appliqué par EscPosCommandBuilder::encodeForPrinter.
        ['/oignon.*cuit|cuit.*oignon/', "O\u{0332}"],
        ['/oignon/', 'O'],
    ];

    private const CRUDITE_ORDER = ['S', 'T', 'O', "O\u{0332}"];

    /** lowercase, strip diacritics, trim — for keyword matching. */
    private function norm(?string $s): string
    {
        $s = (string) $s;
        // [TICKET-WIDTHSAFE 2026-07-01] Pré-mapper les ligatures AVANT translit : « Œuf » doit
        // donner « oeuf » → symbole « OEU », jamais « UF » (vu par l'owner).
        // [PARITY 2026-07-06] iconv//TRANSLIT dépend de la libc (macOS : « é » → « 'e » →
        // « Méga » devenait « M » au lieu de « MEG », divergence ticket↔écran). Str::ascii
        // (portable-ascii) est déterministe sur tous les OS — parité stricte avec le JS.
        $s = strtr($s, ['Œ' => 'Oe', 'œ' => 'oe', 'Æ' => 'Ae', 'æ' => 'ae']);
        $s = \Illuminate\Support\Str::ascii($s);

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

    /**
     * Un item ADDON « Menu (Frites + Boisson) » / « Formule … » → affiché juste « MENU »
     * en cuisine. [SYNC-BORNE 2026-07-01] On NE matche QUE la ligne addon (menu suivi
     * d'une parenthèse, ou « formule ») — surtout PAS un vrai produit dont le nom contient
     * « menu » (ex. « Menu Enfant Burger »/« Nuggets ») qui DOIT garder son identité en
     * cuisine (le cuisinier doit savoir Burger vs Nuggets).
     */
    public function isMenuItem(string $name): bool
    {
        return (bool) preg_match('/\bmenu\s*\(|\bformule\b/u', $this->norm($name));
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
        // [CLUSTER-2 2026-07-11] Distinguer la formule PARTIELLE : « frites seules »
        // (ratio frites 0,6) et « boisson seule » (ratio boisson 0,4) ne sont PAS un
        // menu complet — la cuisine doit voir FRITES/BOISSON, pas « MENU » (sinon elle
        // sert la formule entière = fuite revenu). menu_full/menu_formule restent MENU.
        // frites+boisson présents ensemble = la formule complète = MENU.
        $addons = $snapshot['addons'] ?? [];
        $hasFull = $hasFrites = $hasBoisson = false;
        foreach ($addons as $a) {
            $role = strtolower((string) ($a['role'] ?? ''));
            if (! str_starts_with($role, 'menu_')) {
                continue;
            }
            if ($role === 'menu_frites') {
                $hasFrites = true;
            } elseif ($role === 'menu_boisson') {
                $hasBoisson = true;
            } else {
                $hasFull = true; // menu_full / menu_formule / futur menu_*
            }
        }
        if ($hasFull || ($hasFrites && $hasBoisson)) {
            return 'MENU';
        }
        if ($hasFrites) {
            return 'FRITES';
        }
        if ($hasBoisson) {
            return 'BOISSON';
        }
        foreach ($addons as $a) {
            if (preg_match('/frite/', $this->norm((string) ($a['addon_name'] ?? $a['name'] ?? '')))) {
                return 'F';
            }
        }

        return '';
    }

    /**
     * [W3-FIX-C 2026-07-06] Item BOISSON ? Jumeau STRICT du JS categorize()==='drink'
     * (kdsCustomization.js) : mêmes regex, même ORDRE (un nom qui matche une catégorie
     * antérieure — menu/sandwich/taco/…/dessert — n'est JAMAIS une boisson ; garde
     * dessert-avant-drink : « Gâteau » contient « eau » mais reste un dessert).
     * NB : lowercase SANS translit (le JS ne strip pas les diacritiques ici).
     */
    public function isDrinkItem(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        $before = [
            '/menu|formule/u',                       // menu_formule
            '/sandwich|kafteji|brick/u',             // sandwich
            '/\btacos?\b/u',                         // taco
            '/burger|cheeseburger|double cheese/u',  // burger
            '/assiette|couscous|ojja|lablabi/u',     // assiette
            '/frite|onion ring/u',                   // side
            '/dessert|crepe|crêpe|gateau|gâteau|glace|tiramisu/u', // dessert (AVANT drink)
        ];
        foreach ($before as $re) {
            if (preg_match($re, $n)) {
                return false;
            }
        }

        // [W6-ADV B-1 2026-07-06] Le match noms ratait 8/15 boissons actives DB
        // (« Hawaï 33cl » — régression du renommage Fanta Hawai —, Orangina,
        // Capri-Sun, Tropico, Ice Tea, Fuze Tea, Perrier, Oasis) → « 1 x HAW »
        // cryptique en cuisine. Ajouts JUMEAUX (kdsCustomization.js isDrinkName) :
        // marques réelles + « boisson » générique + token VOLUMÉTRIQUE (« 33cl »,
        // « 50 cl », « 1L », « 1,5l » — seuls les liquides sont nommés au volume).
        // Les gardes ci-dessus restent la protection anti faux-positif (desserts,
        // « frites + boisson », « Menu (Frites + Boisson) »...).
        return (bool) (
            preg_match('/coca|fanta|sprite|\beau\b|coke|water|\bjus\b|\bthé?\b|menthe|caf[eé]|boisson|oasis|orangina|capri|tropico|ice[ -]?tea|fuze|perrier|hawa[iï]/u', $n)
            || preg_match('/\b\d{1,2} ?cl\b|\b\d(?:[.,]\d)? ?l\b/u', $n)
        );
    }

    /**
     * [W6-ADV C-P1-1 2026-07-06] La BORNE écrit la boisson de formule DANS la ligne
     * « Pain : Pain. Formule : Menu complet (frites + boisson) (Hawaï 33cl). Sauce
     * frites : Algérienne » (UNE seule ligne — shape réel #5533) que cleanInstruction
     * droppe entière (anti double-menu / compo) → la boisson mourait avec (ni ticket
     * ni KDS). Extrait le(s) segment(s) entre parenthèses validés boisson
     * (isDrinkItem — la garde rejette « (frites + boisson) », libellé de formule)
     * → ligne au format CAISSE « BOISSON: X », canal déjà préservé + rendu des
     * 2 côtés. Jumeau STRICT du JS kdsCustomization.js extractFormuleDrinkLines.
     *
     * @return list<string>
     */
    private function extractFormuleDrinkLines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\n/', $raw) as $ln) {
            if (! preg_match('/formule\s*:/iu', $ln)) {
                continue;
            }
            if (preg_match_all('/\(([^()]+)\)/u', $ln, $m)) {
                foreach ($m[1] as $seg) {
                    $seg = trim($seg);
                    if ($seg !== '' && $this->isDrinkItem($seg)) {
                        $out[] = 'BOISSON: '.$seg;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * [W3-FIX-C 2026-07-06] Lignes boisson d'un item ("1 Coca-Cola 33cl") depuis ses
     * addons (role 'drink' / 'menu_boisson' / *boisson*) — le cuisinier PRÉPARE les
     * boissons, elles doivent sortir sur le ticket ET l'écran. Jumeau du JS
     * kdsSymbolic.js drinkAddonLabels() (écran : « 1× … », ticket : « 1 … »).
     *
     * @param  array<string,mixed>  $snapshot
     * @return list<string>
     */
    public function drinkLines(array $snapshot): array
    {
        $out = [];
        foreach (($snapshot['addons'] ?? []) as $a) {
            $role = strtolower((string) ($a['role'] ?? ''));
            if (! ($role === 'drink' || $role === 'menu_boisson' || str_contains($role, 'boisson'))) {
                continue;
            }
            $name = trim((string) ($a['addon_name'] ?? $a['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            // [CLUSTER-6 2026-07-11] Ne PAS émettre le conteneur de formule comme
            // boisson : role 'menu_boisson' porte parfois le nom du conteneur
            // (« Menu (Frites + Boisson) »), qui n'est PAS une boisson. La vraie
            // boisson vient de extractFormuleDrinkLines (instruction). La garde
            // isDrinkItem rejette « menu/formule », accepte « Coca 33cl ».
            if (! $this->isDrinkItem($name)) {
                continue;
            }
            $q = (int) ($a['quantity'] ?? 1);
            $q = $q > 0 ? $q : 1;
            $out[] = "{$q} {$name}";
        }

        return $out;
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
                continue; // echoed product name (exact)
            }
            // [KITCHEN 2026-06-30] Le pos-wizard écrit le nom produit en MAJUSCULES en
            // 1re ligne (ex « TACOS », « SANDWICH CAYENNE ») — souvent ≠ du nom stocké.
            // Toute ligne 100% MAJUSCULES (lettres/espaces, sans chiffre) = écho produit → drop
            // (la ligne 1 symbolique montre déjà le produit ; les vraies notes ont des minuscules).
            if (! $insideBracket && preg_match('/^[\p{Lu}][\p{Lu}\s\'\-]*$/u', $t) && mb_strlen($t) >= 2) {
                continue;
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

        // [KITCHEN-NOPRICE 2026-06-30] La cuisine n'affiche JAMAIS de prix : on retire
        // toute annotation « (+2,00 €) » / « (+2,50) » des notes conservées.
        // € avant OU après le nombre, décimale point OU virgule : (+2,00 €) / (+€1.00) / (+2,50).
        $priceRe = '/\s*\(\s*\+?\s*(?:€|EUR)?\s*\d+[.,]\d{1,2}\s*(?:€|EUR)?\s*\)/u';
        $kept = array_map(fn ($l) => trim(preg_replace($priceRe, '', $l)), $kept);

        // [W6-ADV C-P1-1] Boisson de formule borne extraite AVANT le drop de sa ligne —
        // dédupliquée si la caisse a déjà écrit sa propre ligne « BOISSON: X ».
        $lower = array_map(static fn ($l) => mb_strtolower((string) $l), $kept);
        foreach ($this->extractFormuleDrinkLines($raw) as $d) {
            if (! in_array(mb_strtolower($d), $lower, true)) {
                $kept[] = $d;
                $lower[] = mb_strtolower($d);
            }
        }

        return trim(implode("\n", $kept));
    }

    /** @return array{0:string,1:string} [produit, taille] — only M/L/XL trailing tokens */
    private function produitAndSize(string $itemName): array
    {
        $raw = trim($itemName);
        if (preg_match('/\s+(XL|L|M)\s*$/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $produit = trim(mb_substr($raw, 0, $m[0][1]));

            return [$this->produitCode($produit), mb_strtoupper($m[1][0])];
        }

        return [$this->produitCode($raw), ''];
    }

    /**
     * [T3-CUISINE 2026-07-05] Code produit 3 lettres pour la cuisine (owner : « Cayenne → CAY,
     * Terminator → TER »). Rend le ticket cuisine COMPACT (→ police plus grande possible) et
     * l'écran KDS lisible. On prend les 3 lettres du DERNIER mot significatif pour ne PAS
     * confondre « Menu Enfant Burger » (BUR) et « Menu Enfant Nuggets » (NUG). Parité stricte
     * avec le JS `kdsSymbolic.js produitCode()` (ticket == écran).
     */
    private const CODE_GENERIC_WORDS = ['menu', 'enfant', 'formule', 'grande', 'grand', 'petite', 'petit', 'mini', 'maxi', 'moyenne', 'moyen', 'box'];

    private function produitCode(string $produit): string
    {
        $n = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $this->norm($produit)));
        if ($n === '') {
            return '';
        }
        $words = array_values(array_filter(explode(' ', $n), static fn ($x): bool => $x !== ''));
        // Premier mot SIGNIFICATIF : on saute les préfixes génériques (Menu/Enfant/Grande…) et
        // les tailles/volumes (33cl, 50cl, 1l) → « Coca 33cl »→COC, « Menu Enfant Burger »→BUR.
        $significant = array_values(array_filter($words, static function (string $w): bool {
            if (in_array($w, self::CODE_GENERIC_WORDS, true)) {
                return false;
            }

            return ! preg_match('/^\d+(cl|ml|l|g|kg)?$/', $w);
        }));
        $base = $significant[0] ?? ($words[0] ?? $n);

        return mb_strtoupper(mb_substr($base, 0, 3));
    }
}
