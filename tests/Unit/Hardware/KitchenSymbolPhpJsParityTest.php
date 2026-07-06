<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use ReflectionClass;
use Tests\TestCase;

/**
 * [TICKET-CONTRACT 2026-06-29] SENTINEL DE PARITÉ — les symboles cuisine DOIVENT être
 * identiques entre le ticket CUISINE imprimé (PHP KitchenTicketSymbolicFormatter) et
 * l'écran KDS (JS resources/js/helpers/kdsSymbolic.js). Les deux tables sont maintenues
 * À LA MAIN dans deux fichiers : ce test lit les deux sources et compare les tables
 * viandes / sauces / crudités + l'ordre des crudités. Toute divergence future (ajout
 * d'un symbole d'un seul côté) casse le CI → le cuisinier ne verra jamais des symboles
 * différents à l'écran et sur le papier. Voir docs/TICKET_CONTRACT.md.
 */
class KitchenSymbolPhpJsParityTest extends TestCase
{
    /** Tables PHP (constantes privées du formatter), via réflexion. */
    private function phpTable(string $const): array
    {
        $consts = (new ReflectionClass(KitchenTicketSymbolicFormatter::class))->getConstants();
        $this->assertArrayHasKey($const, $consts, "Constante PHP $const introuvable");

        // Format PHP : [['/regex/', 'SYM'], ...] → on normalise en ['regex' => 'SYM'].
        $out = [];
        foreach ($consts[$const] as [$pattern, $sym]) {
            $out[$this->stripDelimiters($pattern)] = $sym;
        }

        return $out;
    }

    /** Retire les délimiteurs et flags d'un motif PHP : '/poulet/i' → 'poulet'. */
    private function stripDelimiters(string $pattern): string
    {
        return preg_replace('#^/(.*)/[a-z]*$#s', '$1', $pattern);
    }

    /** Extrait une table [/regex/, 'SYM'] depuis le source JS kdsSymbolic.js. */
    private function jsTable(string $name): array
    {
        $js = file_get_contents(base_path('resources/js/helpers/kdsSymbolic.js'));
        $this->assertNotFalse($js, 'kdsSymbolic.js illisible');

        // Bloc : const NAME = [ ... ];
        $this->assertSame(1, preg_match('/const\s+'.$name.'\s*=\s*\[(.*?)\];/s', $js, $block), "Bloc JS $name introuvable");

        // Entrées : [/regex/flags, 'SYM']  (regex sans '/' interne dans nos tables)
        preg_match_all('#\[\s*/([^/]+)/[a-z]*\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]#s', $block[1], $rows, PREG_SET_ORDER);
        $out = [];
        foreach ($rows as $r) {
            $out[$r[1]] = $r[2];
        }

        return $out;
    }

    public function test_meat_symbols_match_between_php_and_js(): void
    {
        $this->assertSame($this->phpTable('MEAT_TABLE'), $this->jsTable('MEAT_TABLE'),
            'Table VIANDES divergente entre le ticket cuisine (PHP) et l\'écran KDS (JS).');
    }

    public function test_sauce_symbols_match_between_php_and_js(): void
    {
        $this->assertSame($this->phpTable('SAUCE_TABLE'), $this->jsTable('SAUCE_TABLE'),
            'Table SAUCES divergente entre le ticket cuisine (PHP) et l\'écran KDS (JS).');
    }

    public function test_crudite_symbols_match_between_php_and_js(): void
    {
        $this->assertSame($this->phpTable('CRUDITE_TABLE'), $this->jsTable('CRUDITE_TABLE'),
            'Table CRUDITÉS divergente entre le ticket cuisine (PHP) et l\'écran KDS (JS).');
    }

    public function test_crudite_print_order_matches(): void
    {
        $phpOrder = (new ReflectionClass(KitchenTicketSymbolicFormatter::class))->getConstants()['CRUDITE_ORDER'];

        $js = file_get_contents(base_path('resources/js/helpers/kdsSymbolic.js'));
        $this->assertSame(1, preg_match('/const\s+CRUDITE_ORDER\s*=\s*\[(.*?)\];/s', $js, $m), 'CRUDITE_ORDER JS introuvable');
        // [OWNER8 2026-07-06] O̲ (oignons cuits) = O + U+0332 → la classe accepte le combining.
        preg_match_all('/[\'"]([A-Z\x{0332}]+)[\'"]/u', $m[1], $jsOrder);

        $this->assertSame($phpOrder, $jsOrder[1], 'Ordre des crudités (STOO̲) divergent PHP/JS.');
        $this->assertSame(['S', 'T', 'O', "O\u{0332}"], $phpOrder, 'Ordre crudités canonique attendu = S,T,O,O̲.');
    }

    public function test_tables_are_non_empty(): void
    {
        // Garde-fou : si l'extraction regex casse (refacto de format), on ne veut pas
        // un faux-vert "deux tables vides identiques".
        $this->assertNotEmpty($this->phpTable('MEAT_TABLE'));
        $this->assertNotEmpty($this->phpTable('SAUCE_TABLE'));
        $this->assertGreaterThanOrEqual(7, count($this->phpTable('MEAT_TABLE')));
        $this->assertGreaterThanOrEqual(13, count($this->phpTable('SAUCE_TABLE')));
    }
}
