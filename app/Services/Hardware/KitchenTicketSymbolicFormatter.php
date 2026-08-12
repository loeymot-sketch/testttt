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
 *   Line 2 : "+ Cheddar" per paid supplement (NO sauce — sauces live on Line 1)
 *   Line 3 : "MENU" / "F"
 *
 * [MEGA-BORNE 2026-07-22 owner]
 *   - Sauce(s): the product's 1st (included) sauce AND its extra sauce(s) are written
 *     TOGETHER in the Line-1 Sauce(s) slot ("FRO MAY"); the extra sauce is NO LONGER a
 *     "+ Sauce supplémentaire" line. The menu/frites sauce stays on Line 2 ("MENU : SYM").
 *   - Tacos: the [Taille] slot is DROPPED (kitchen shows the meats instead — "K P").
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
        // [OWNER 2026-07-31] Une viande « mixte » dont le NOM contient plusieurs viandes
        // (ex. « Mixte (hachée + poulet) ») affiche TOUTES ses lettres, pas seulement la
        // 1ère (avant : « K » seul car /hach/ matche en premier). Poulet (P) en tête si
        // présent → « P K ». Contrat inchangé : 0 match = '', 1 viande = 1 lettre.
        // Jumeau STRICT : resources/js/helpers/kdsSymbolic.js meatSymbol (parité ticket↔écran).
        $syms = [];
        foreach (self::MEAT_TABLE as [$re, $sym]) {
            if (preg_match($re, $n) && ! in_array($sym, $syms, true)) {
                $syms[] = $sym;
            }
        }
        if (count($syms) > 1 && in_array('P', $syms, true)) {
            $syms = array_merge(['P'], array_values(array_diff($syms, ['P'])));
        }

        return implode(' ', $syms);
    }

    public function sauceSymbol(?string $name): string
    {
        $connue = $this->knownSauceSymbol($name);
        if ($connue !== '') {
            return $connue;
        }

        // Sauce hors table → code 3 lettres (comportement historique, inchangé).
        return mb_strtoupper(mb_substr(preg_replace('/^sauce\s+/', '', $this->norm($name)), 0, 3));
    }

    /**
     * [UBER-PHOTO 2026-08-10] Symbole d'une sauce RECONNUE, ou chaîne vide si le nom n'est pas
     * dans la table des sauces.
     *
     * Pourquoi cette méthode existe : {@see sauceSymbol()} ne peut PAS servir à reconnaître une
     * sauce — elle rend toujours quelque chose (les 3 premières lettres) et dirait donc « oui »
     * de n'importe quel mot. Le classement d'une option de ticket Uber a besoin d'une réponse
     * franche. Une seconde table de sauces serait le défaut favori de ce projet (« un correctif
     * appliqué à une moitié du mécanisme ») : elle finirait par diverger le jour où l'owner ajoute
     * une sauce. Il n'y a donc qu'UNE table, et les deux usages la partagent.
     *
     * Outil de CLASSEMENT côté serveur, pas de rendu : aucun jumeau JS n'est requis (l'écran KDS
     * ne classe rien, il affiche ce que le composition_snapshot contient déjà).
     */
    public function knownSauceSymbol(?string $name): string
    {
        $n = $this->norm($name);
        foreach (self::SAUCE_TABLE as [$re, $sym]) {
            if (preg_match($re, $n)) {
                return $sym;
            }
        }

        return '';
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

    /**
     * [MEGA-BORNE 2026-07-22 owner] Un TACOS n'affiche PAS de taille en cuisine : le nombre de
     * viandes (« K P ») porte l'info. Jumeau STRICT du JS kdsSymbolic.js isTacos() (même regex
     * sur le nom normalisé — parité ticket↔écran).
     */
    private function isTacos(string $name): bool
    {
        return (bool) preg_match('/\btacos?\b/', $this->norm($name));
    }

    /** @param array<string,mixed> $snapshot */
    public function mainLine(string $itemName, array $snapshot, ?string $instruction = null): string
    {
        [$produit, $taille] = $this->produitAndSize($itemName);
        // [MEGA-BORNE 2026-07-22 owner] Tacos : aucune taille (produitAndSize l'a déjà retirée du
        // NOM) — on neutralise aussi une éventuelle taille portée par une VARIATION (garde plus bas).
        $isTacos = $this->isTacos($itemName);
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
                // Tacos : taille ignorée en cuisine (le nombre de viandes porte l'info).
                if (! $isTacos) {
                    $taille = $taille !== '' ? $taille : mb_strtoupper($value);
                }
            } elseif ($this->meatSymbol($value) !== '') {
                $viandes[] = $this->meatSymbol($value);
            }
        }

        // [MEGA-BORNE 2026-07-22 owner] La/les sauce(s) EN PLUS du produit remontent dans le slot
        // Sauce(s) de la ligne 1, À CÔTÉ de la 1ère incluse (« FRO MAY ») — plus jamais une ligne
        // « + Sauce supplémentaire ». Le nom réel des extras ne survit que dans l'instruction
        // (extraSauceNames) → symbole. La sauce FRITES du menu reste, elle, en ligne 2 (menuLine).
        foreach ($this->extraSauceNames($instruction) as $extraSauce) {
            $sym = $this->sauceSymbol($extraSauce);
            if ($sym !== '') {
                $sauces[] = $sym;
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
    public function supplementLines(array $snapshot, ?string $instruction = null): array
    {
        $out = [];
        // [MEGA-BORNE 2026-07-22 owner] La sauce EN PLUS remonte dans le slot Sauce(s) de la ligne 1
        // (symboles) → elle n'est PLUS une ligne « + Sauce supplémentaire » DÈS QUE son nom a pu être
        // récupéré (extraSauceNames non vide = exactement ce qui alimente la ligne 1). Sinon (legacy
        // sans instruction parsable) on GARDE le libellé générique pour ne pas perdre l'info que le
        // client a payé une sauce en plus.
        // [OWNER 2026-08-10 · « les sauces au bon endroit, frites ou sandwich »] Une sauce payée
        // doit apparaître UNE FOIS, à sa place — jamais une seconde fois en supplément anonyme.
        //
        // Les wizards sont FROZEN : ils facturent un extra GÉNÉRIQUE et sans nom (« Sauce
        // supplémentaire »), et l'identité de la sauce ne survit que dans le texte libre, sur
        // DEUX canaux distincts :
        //   · « Sauces en plus : … »   → sauces du PRODUIT   → repliées dans la ligne 1 ;
        //   · « Sauce frites : A, B »  → sauces des FRITES   → la 1ʳᵉ est offerte, les suivantes
        //                                 sont les payantes, et elles s'affichent déjà sur le
        //                                 badge (« MENU : KTP MAY »).
        //
        // Seul le premier canal était pris en compte. Constaté sur une commande réelle (#5835) :
        // le client prend 1 sauce sandwich et 2 sauces frites ; le ticket affichait la bonne
        // ligne 1, le bon badge… PLUS un « + Sauce supplémentaire » anonyme — une quatrième
        // sauce fantôme, sans nom, dont le cuisinier ne pouvait pas savoir où elle allait.
        //
        // On tient donc un BUDGET de sauces payantes déjà expliquées ailleurs, et on ne masque
        // que ce nombre d'unités. Tout ce qui dépasse RESTE affiché : une sauce facturée que
        // rien n'explique ne doit jamais disparaître en silence.
        $budgetSaucesExpliquees = count($this->extraSauceNames($instruction))
            + max(0, count($this->fritesSauceNames($instruction)) - 1);

        foreach (($snapshot['extras'] ?? []) as $e) {
            $name = (string) ($e['extra_name'] ?? $e['name'] ?? '');
            // Skip only FREE garnitures (folded into Line 1). Paid extras — even
            // crudité-named ones like "Oignons frits" — stay as supplement lines.
            if ($name === '' || ($this->cruditeSymbol($name) !== '' && $this->isFreeExtra($e))) {
                continue;
            }
            // La sauce en plus générique : on masque autant d'unités que le budget en explique
            // (ligne 1 pour le sandwich, badge pour les frites) et on garde le reste VISIBLE.
            if (preg_match('/sauce\s*suppl/iu', $name)) {
                $q = max(1, (int) ($e['quantity'] ?? 1));
                $restant = max(0, $q - $budgetSaucesExpliquees);
                $budgetSaucesExpliquees = max(0, $budgetSaucesExpliquees - $q);
                if ($restant === 0) {
                    continue;
                }
                $out[] = '+ '.$name.($restant > 1 ? " ×{$restant}" : '');

                continue;
            }
            $q = (int) ($e['quantity'] ?? 1);
            // [MULTIVIANDE 2026-07-24] Name the generic "Viande supplémentaire" with the
            // recovered meat name(s) so the KITCHEN ticket tells the cook WHICH meat to add
            // (mirror of the client ticket, OrderReceiptEscPosRenderer:448). Non-meat extras
            // (Cheddar, and any sauce reaching here unfolded) are returned unchanged.
            $display = $this->extraDisplayName($name, $instruction);
            // [OWNER 2026-08-03 « puis ×2 »] Noms RÉSOLUS = chaque unité déjà énumérée
            // (« Hachée, Poulet » / « 2× Poulet ») → le suffixe ×N est redondant et se lit
            // « 2× chaque ». Il ne reste que sur le libellé générique non résolu.
            $suffix = ($q > 1 && $display === $name) ? " ×{$q}" : '';
            $out[] = '+ '.$display.$suffix;
        }

        return $out;
    }

    /**
     * [MULTISAUCE 2026-07-18] Recover the NAME(s) of the extra sauce(s) that the
     * FROZEN wizards emit as a GENERIC, nameless "Sauce supplémentaire" item_extra.
     * The identity survives only in the free-text `instruction`:
     *   - caisse (pos-wizard.js:3805) : "… Sauce : <1ère>, <en plus…>" (1ère gratuite incluse)
     *   - borne/web (KioskWizardComponent.vue:2147) : "Sauces en plus : <en plus…>" (extras seuls)
     * "Sauce frites :" (dip frites, autre canal gratuit) n'est JAMAIS capté. Empty
     * when unparsable → callers render the generic label as before (retro-compatible).
     * Price-neutral display recovery — the SSOT snapshot + sealed price are untouched.
     * JS twin: resources/js/helpers/kdsSymbolic.js extraSauceNames().
     *
     * @return list<string>
     */
    public function extraSauceNames(?string $instruction): array
    {
        if (! is_string($instruction) || trim($instruction) === '') {
            return [];
        }

        // Borne/web write ONLY the extras ("Sauces en plus : …" / "Extra sauces: …").
        if (preg_match('/sauces?\s+en\s+plus\s*:\s*([^\n.]+)/iu', $instruction, $m)
            || preg_match('/extra\s+sauces?\s*:\s*([^\n.]+)/iu', $instruction, $m)) {
            return $this->splitSauceList($m[1]);
        }

        // Caisse writes ALL sauces ("… Sauce : <1ère>, <extras…>") — the 1st is the free
        // variation (sauceOrder[0]), the rest are the paid extras. "Sauce frites :" never
        // matches this ("Sauce" is not immediately followed by ":").
        if (preg_match('/(?<![\p{L}])sauces?\s*:\s*([^\n]+)/iu', $instruction, $m)) {
            $names = $this->splitSauceList($m[1]);

            return array_slice($names, 1);
        }

        return [];
    }

    /**
     * [MULTIVIANDE 2026-07-24] Recover the NAME(s) of the extra meat(s) that the FROZEN
     * wizards emit as a GENERIC, nameless "Viande supplémentaire" item_extra (@2,50). The
     * cook otherwise reads "+ Viande supplémentaire ×N" and does NOT know WHICH meat to add.
     * Strict MIRROR of extraSauceNames(): the identity survives only in the free-text
     * `instruction`, on a DEDICATED extra-meat line the wizards write like the sauce one
     * ("Sauces en plus : …") :
     *   - caisse (pos-wizard.js buildWizardInstruction) + borne/web (KioskWizardComponent.vue
     *     buildInstruction) : "Viandes en plus : <noms>" — the EXTRAS ONLY (the base meats
     *     already live in composition_snapshot['lines'] → Line 1, so they must NOT be re-listed).
     * Tolerant to the "Viande(s) supplémentaire(s) : …" wording, to accents (é/e) and to case.
     * The bare "Viandes : X, Y" composition line is NEVER parsed (it carries the base meats,
     * not the paid extra). Empty when unparsable → callers keep the generic label
     * (retro-compatible). Price-neutral display recovery — the SSOT snapshot + sealed price
     * are untouched. JS twin: resources/js/helpers/kdsSymbolic.js extraViandeNames().
     *
     * @return list<string>
     */
    public function extraViandeNames(?string $instruction): array
    {
        if (! is_string($instruction) || trim($instruction) === '') {
            return [];
        }

        // Dedicated extra-meat line (mirror of "Sauces en plus : …") OR the tolerant
        // "Viande(s) supplémentaire(s) : …" wording. NEVER the bare "Viandes : …" line.
        if (preg_match('/viandes?\s+en\s+plus\s*:\s*([^\n.]+)/iu', $instruction, $m)
            || preg_match('/viandes?\s+suppl[ée]mentaires?\s*:\s*([^\n.]+)/iu', $instruction, $m)) {
            return $this->splitViandeList($m[1]);
        }

        return [];
    }

    /**
     * [MULTISAUCE 2026-07-18] Display label for an extra: names the generic
     * "Sauce supplémentaire" with the recovered sauce name(s)
     * ("Sauce supplémentaire : Andalouse"). [MULTIVIANDE 2026-07-24] Same mirror for the
     * generic "Viande supplémentaire" (@2,50) → "Viande supplémentaire : Poulet, Merguez"
     * so the cook knows WHICH meat. Any already-named extra (Cheddar, crudités…) is returned
     * unchanged. JS twin: resources/js/helpers/kdsSymbolic.js extraDisplayName().
     */
    public function extraDisplayName(string $extraName, ?string $instruction): string
    {
        if (preg_match('/sauce\s*suppl/iu', $extraName)) {
            $names = $this->extraSauceNames($instruction);

            return $names === [] ? $extraName : $extraName.' : '.implode(', ', $names);
        }
        if (preg_match('/viande\s*suppl/iu', $extraName)) {
            $names = $this->extraViandeNames($instruction);

            return $names === [] ? $extraName : $extraName.' : '.implode(', ', $names);
        }

        return $extraName;
    }

    /** Split a "A, B, C" sauce list → trimmed, non-empty names. */
    private function splitSauceList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($n): bool => $n !== ''));
    }

    /**
     * [MULTIVIANDE 2026-07-24] Split a "A, B, C" meat list → trimmed, "+"-stripped
     * (legacy caisse prefixes an extra with "+"), non-empty, DEDUPED (order-preserving)
     * names. JS twin: resources/js/helpers/kdsSymbolic.js splitViandeList().
     */
    private function splitViandeList(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $token) {
            $name = trim((string) preg_replace('/^\+\s*/', '', trim($token)));
            if ($name !== '' && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
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

    /**
     * Sauce(s) frites du menu (depuis l'instruction) → SYMBOLE(s) court(s) (Andalouse → AND).
     *
     * [MULTIFRITES 2026-07-18] owner : « si le client a plusieurs sortes pour les frites,
     * on pourra lui mettre ça ». La sauce frites est un canal GRATUIT (dip frites, hors
     * NF525, aucun extra/prix) — quand le client en choisit plusieurs, le wizard écrit
     * « Sauce frites : Ketchup, Mayonnaise » et le ticket cuisine + le KDS doivent les
     * montrer TOUTES (« KTP MAY »), pas seulement la 1ère. On mappe CHAQUE sauce du
     * segment (split virgule, ordre de sélection préservé) — 1 seule sauce reste un
     * symbole unique (rétro-compatible). Jumeau JS : kdsSymbolic.js fritesSauceSymbol().
     */
    public function fritesSauceSymbol(?string $instruction): string
    {
        $syms = array_filter(
            array_map(fn ($n): string => $this->sauceSymbol($n), $this->fritesSauceNames($instruction)),
            static fn ($s): bool => $s !== ''
        );

        return implode(' ', $syms);
    }

    /**
     * [OWNER 2026-08-10] NOMS des sauces choisies POUR LES FRITES, dans l'ordre de sélection.
     *
     * Extrait de {@see fritesSauceSymbol()} pour que le décompte des sauces payées puisse s'appuyer
     * sur la MÊME lecture que l'affichage : la 1ʳᵉ est offerte, les suivantes sont les suppléments
     * facturés. Deux lectures séparées finiraient par diverger, et l'une des deux mentirait.
     *
     * @return list<string>
     */
    public function fritesSauceNames(?string $instruction): array
    {
        if (! is_string($instruction) || $instruction === '') {
            return [];
        }
        if (preg_match('/sauce\s*frites\s*:\s*([^\n]+)/iu', $instruction, $m)) {
            return $this->splitSauceList($m[1]);
        }

        return [];
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
     * [FRITES-SAUCE 2026-08-10] BADGE de formule tel qu'il doit être IMPRIMÉ et AFFICHÉ :
     * « MENU », « MENU : ALG », « FRITES : KTP », « BOISSON », ou rien.
     *
     * Pourquoi cette méthode existe : la règle du badge était écrite dans le moteur de rendu du
     * ticket. Le jour où un deuxième écran a eu besoin du même badge (l'aperçu d'une commande Uber
     * photographiée, qui doit montrer EXACTEMENT ce que la cuisine verra), la recopier aurait
     * créé une troisième variante à maintenir — le défaut dominant de ce projet. Elle vit
     * désormais avec les autres règles symboliques, et le rendu comme l'aperçu l'appellent.
     *
     * Le cas « frites vendues comme PRODUIT » est inclus : sans menu ni formule il n'y avait aucun
     * badge, et la sauce choisie disparaissait (le nettoyeur d'instruction retire la ligne
     * « Sauce frites : … », censée être rendue ici). Vu en base sur une commande à trois sauces.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function menuBadge(array $snapshot, string $itemName, ?string $instruction): string
    {
        $menu = $this->menuLine($snapshot);

        if ($menu === 'MENU' || $menu === 'FRITES') {
            $sym = $this->fritesSauceSymbol($instruction);

            return $sym !== '' ? $menu.' : '.$sym : $menu;
        }

        // [OWNER 2026-08-10 · 2ᵉ passe] Aucun badge, mais une sauce a bien été CHOISIE pour des
        // frites : on l'affiche quand même. La règle est volontairement large — « une sauce
        // choisie ne disparaît jamais » — parce que les frites arrivent par des chemins que le
        // badge ne couvre pas tous :
        //   · frites vendues comme PRODUIT (« Grande Frites ») → aucun menu, donc aucun badge ;
        //   · MENU ENFANT → ses frites viennent de la RECETTE (RECETTES_FIXES F:1), pas d'un
        //     addon : le bandeau de cuisson les compte, mais rien n'affichait leur sauce.
        // Le nettoyeur d'instruction supprime la ligne « Sauce frites : … » puisqu'elle est
        // censée être rendue ICI ; sans ce repli, le choix du client était purement perdu.
        if ($menu === '') {
            $sym = $this->fritesSauceSymbol($instruction);

            return $sym !== '' ? 'FRITES : '.$sym : '';
        }

        return $menu;
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
    /**
     * @param  list<string>  $knownDrinks  lignes boisson déjà émises par le canal
     *         addon (sortie de drinkLines(), ex. "1 Coca 33cl") — [D-1 GOAL-8AXES
     *         2026-08-05] le dédoublonnage historique (W6-ADV C-P1-1) ne comparait
     *         que les lignes « BOISSON: X » entre elles : quand l'addon
     *         menu_boisson porte le VRAI nom (« Coca 33cl ») ET que l'instruction
     *         contient la ligne formule, la boisson sortait DEUX FOIS (ticket ET
     *         KDS). Jumeau STRICT : kdsCustomization.js sanitizeKdsInstruction.
     */
    public function cleanInstruction(?string $raw, string $itemName, array $knownDrinks = []): string
    {
        if (! is_string($raw) || $raw === '') {
            return '';
        }
        $name = mb_strtoupper(trim($itemName));
        // [VIANDE-TICKET 2026-08-03 · RED P1 FOOD-SAFETY] « Viandes/Sauces en plus : … » =
        // compo repliée dans la ligne extra nommée (« + Viande supplémentaire : X ») → on
        // STRIPPE le SEGMENT, jamais la ligne : la borne/web joignent tout par '. ' sur UNE
        // ligne et une note client (ALLERGIE !) peut la partager. Le segment s'arrête à
        // '.' (séparateur borne) ou '|' (séparateur legacy) pour préserver ce qui suit.
        $raw = preg_replace('/(Viandes?|Sauces?)\s+en\s+plus\s*:\s*[^\n.|]*/iu', '', $raw);
        $compoRe = '/(^|\s)(Viandes?|Sauce|Suppl[ée]ment|Pain|Galette)\s*:/iu';
        $insideBracket = false;
        $kept = [];
        foreach (preg_split('/\n/', $raw) as $ln) {
            $t = trim($ln);
            // [RED 2026-08-03] Résidus du strip « en plus » : séparateurs orphelins en tête.
            $t = trim((string) preg_replace('/^[.|\s]+/u', '', $t));
            if ($t === '') {
                continue;
            }
            // [OWNER 2026-08-10] Une ligne réduite à de la PONCTUATION après le strip des segments
            // de composition n'apprend rien et ressemble à un bug. Constaté sur une commande réelle
            // (#5896) : « Viandes en plus : … · Sauces en plus : … » laissait « · . » imprimé en
            // note client. Le strip historique ne nettoyait que le DÉBUT de ligne.
            if (! preg_match('/[\p{L}\p{N}]/u', $t)) {
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
            // [D-1 GOAL-8AXES 2026-08-05] + toute ligne « Formule : … » : c'est de la compo
            // (conteneur + boisson), jamais une note client — sa boisson est extraite du
            // BRUT par extractFormuleDrinkLines AVANT ce drop (W6-ADV C-P1-1), rien n'est
            // perdu. Sans ce drop, « Formule : Menu XL (Fanta 33cl) » survivait en note.
            if (preg_match('/sauce\s*frites|menu\s*\(\s*frites|^\+\s*menu\b|^formule\s*:/iu', $t)) {
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
        // [D-1 GOAL-8AXES 2026-08-05] + dédupliquée contre le canal ADDON ($knownDrinks) :
        // les deux formats sont normalisés (préfixe quantité strippé, minuscules) car
        // « 1 Coca 33cl » (addon) et « BOISSON: Coca 33cl » (instruction) ne sont jamais
        // égaux en comparaison brute.
        $known = array_map([self::class, 'normalizeDrinkKey'], $knownDrinks);
        $lower = array_map(static fn ($l) => mb_strtolower((string) $l), $kept);
        foreach ($this->extractFormuleDrinkLines($raw) as $d) {
            if (in_array(self::normalizeDrinkKey($d), $known, true)) {
                continue; // déjà émise par drinkLines() — ne pas doubler
            }
            if (! in_array(mb_strtolower($d), $lower, true)) {
                $kept[] = $d;
                $lower[] = mb_strtolower($d);
            }
        }

        return trim(implode("\n", $kept));
    }

    /**
     * [D-1] Clé de comparaison inter-canaux : « 1 Coca 33cl » / « 2× Fanta 33cl » /
     * « BOISSON: Coca 33cl » → « coca 33cl ». Jumeau STRICT : kdsCustomization.js
     * normalizeDrinkKey.
     */
    private static function normalizeDrinkKey(string $line): string
    {
        $s = trim($line);
        $s = (string) preg_replace('/^boisson\s*:\s*/iu', '', $s);
        $s = (string) preg_replace('/^\d+\s*[x×]?\s*/u', '', $s);

        return mb_strtolower(trim($s));
    }

    /** @return array{0:string,1:string} [produit, taille] — only M/L/XL trailing tokens */
    private function produitAndSize(string $itemName): array
    {
        $raw = trim($itemName);
        if (preg_match('/\s+(XL|L|M)\s*$/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $produit = trim(mb_substr($raw, 0, $m[0][1]));
            // [MEGA-BORNE 2026-07-22 owner] Tacos : on retire le token taille du NOM pour le code
            // produit (TAC) mais on NE renvoie PAS la taille (la cuisine l'ignore — jumeau JS).
            $taille = $this->isTacos($raw) ? '' : mb_strtoupper($m[1][0]);

            return [$this->produitCode($produit), $taille];
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

    /**
     * [OWNER 2026-08-10 · « la cuisine se trompe entre CHEESE et CHICKEN, écris-les en entier »]
     * Produits dont le nom s'écrit EN TOUTES LETTRES, jamais en code 3 lettres.
     *
     * Le code court a été demandé pour rendre le ticket compact et l'écran lisible à deux mètres.
     * Mais il ne vaut que s'il DÉSIGNE sans ambiguïté. Vérifié sur le catalogue réel :
     *   · « Cheese Burger » → CHE … et « Cheddar » → CHE aussi : deux produits, un seul code ;
     *   · « Chicken Burger » → CHI, à une lettre de CHE : à deux mètres, sur un écran, en coup
     *     de feu, les deux se confondent — et le plat part faux ;
     *   · « Double Cheese » → DOU, qui ne dit RIEN de ce qu'il faut préparer ;
     *   · « Menu Enfant Chicken Burger » → « ENF CHI », que rien ne distingue à l'œil d'un
     *     poulet seul, alors que la portion et l'accompagnement diffèrent.
     *
     * Pour ces familles, la lisibilité prime sur la compacité : le nom entier tient largement
     * dans la largeur du ticket, et il ne se confond avec rien.
     *
     * @var array<int, string> motifs cherchés dans le nom NORMALISÉ (sans accent, minuscule)
     */
    private const CODE_ECRIT_EN_ENTIER = ['cheese', 'chicken', 'menu enfant'];

    // [F-KITCHEN-BOL-BASE 2026-07-15] Mots-catégorie dont la BASE distinctive suit dans le nom
    // (« Bol Frites » vs « Bol Riz » — sans variation « base », le nom est le seul porteur).
    // [OWNER 2026-08-10] « galette » ajouté pour la MÊME raison que « bol » : trois produits
    // actifs du catalogue — Galette Cayenne, Galette Normale, Galette pommes de terre — rendaient
    // TOUS « GAL », et rien d'autre sur la ligne ne les distingue. Même défaut, même remède :
    // « GAL CAY » / « GAL NOR » / « GAL POM ».
    private const CODE_BASE_WORDS = ['bol', 'galette'];

    private function produitCode(string $produit): string
    {
        $n = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $this->norm($produit)));
        if ($n === '') {
            return '';
        }
        $n = (string) preg_replace('/\s+/', ' ', $n);

        // [OWNER 2026-08-10] Familles écrites EN TOUTES LETTRES — voir CODE_ECRIT_EN_ENTIER.
        // On rend le nom NORMALISÉ en majuscules (et non le libellé d'origine) pour que le
        // ticket ESC/POS reste en pur ASCII : « Suprême » deviendrait « SUPRÊME », dont l'accent
        // ne survit pas à toutes les pages de code d'imprimante. Le marqueur « ENF » n'est PAS
        // ajouté : le nom contient déjà « MENU ENFANT ».
        foreach (self::CODE_ECRIT_EN_ENTIER as $motif) {
            if (str_contains($n, $motif)) {
                return mb_strtoupper($n);
            }
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
        $code = mb_strtoupper(mb_substr($base, 0, 3));

        // [F-KITCHEN-BOL-BASE 2026-07-15 / P1] « Bol Frites » et « Bol Riz » réduisaient TOUS
        // DEUX à « BOL » → le cuisinier ne savait pas quelle base préparer (mauvais plat). Quand
        // le 1er mot significatif est un mot-base (bol) et qu'un 2e mot significatif existe, on
        // concatène sa forme 3 lettres (→ « BOL FRI » / « BOL RIZ »). Parité stricte avec le JS.
        if (in_array($base, self::CODE_BASE_WORDS, true) && isset($significant[1])) {
            $code .= ' '.mb_strtoupper(mb_substr($significant[1], 0, 3));
        }

        // [F-01 AUDIT CUISINIER 2026-08-01 · P0] « Chicken Burger » et « Menu Enfant Chicken
        // Burger » rendaient une ligne IDENTIQUE (le mot « enfant » était strippé comme
        // générique) : portion enfant + frites/boisson incluses devenaient invisibles sur le
        // ticket ET l'écran → mauvaise préparation garantie quand les deux coexistent dans la
        // commande. On conserve le code distinctif (BUR/NUG) et on remet le marqueur enfant.
        // Parité stricte avec le JS `kdsSymbolic.js produitCode()`.
        if (in_array('enfant', $words, true)) {
            $code = 'ENF '.$code;
        }

        return $code;
    }
}
