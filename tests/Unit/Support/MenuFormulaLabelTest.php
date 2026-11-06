<?php

namespace Tests\Unit\Support;

use App\Models\OrderItem;
use App\Support\Order\CompositionCompactor;
use App\Support\Order\MenuFormulaLabel;
use PHPUnit\Framework\TestCase;

/**
 * [LIBELLÉ-FORMULE 2026-11-02] Le suivi de caisse disait « Menu » pour une formule complète, des frites
 * seules ET une boisson seule (nom scellé identique) : seul le `role` distingue les trois.
 */
class MenuFormulaLabelTest extends TestCase
{
    /** @return array<string, array{0:string,1:string}> */
    public static function roles(): array
    {
        return [
            'complet' => ['menu_full', 'Menu Frites + Boisson'],
            'frites' => ['menu_frites', 'Frites seules'],
            'boisson' => ['menu_boisson', 'Boisson seule'],
            'futur menu_*' => ['menu_formule', 'Menu Frites + Boisson'],
            'casse/espaces' => [' MENU_BOISSON ', 'Boisson seule'],
            'hors formule' => ['drink', ''],
            'vide' => ['', ''],
        ];
    }

    /** @dataProvider roles */
    public function test_label_depends_on_the_role(string $role, string $attendu): void
    {
        $this->assertSame($attendu, MenuFormulaLabel::forRole($role));
    }

    public function test_null_role_keeps_raw_name(): void
    {
        $this->assertSame('', MenuFormulaLabel::forRole(null));
    }

    /** @dataProvider roles */
    public function test_tracker_projection_names_the_real_choice(string $role, string $attendu): void
    {
        $line = new OrderItem;
        $line->composition_snapshot = ['addons' => [
            ['role' => $role, 'addon_name' => 'Menu (Frites + Boisson)', 'quantity' => 1, 'line_total' => 1.9],
        ]];

        $compact = CompositionCompactor::forLine($line);

        $this->assertSame($attendu !== '' ? $attendu : 'Menu (Frites + Boisson)', $compact['addons'][0]['name']);
    }

    public function test_two_formula_lines_stay_distinguishable(): void
    {
        $frites = new OrderItem;
        $frites->composition_snapshot = ['addons' => [['role' => 'menu_frites', 'addon_name' => 'Menu (Frites + Boisson)']]];
        $boisson = new OrderItem;
        $boisson->composition_snapshot = ['addons' => [['role' => 'menu_boisson', 'addon_name' => 'Menu (Frites + Boisson)']]];

        $this->assertNotSame(
            CompositionCompactor::forLine($frites)['addons'][0]['name'],
            CompositionCompactor::forLine($boisson)['addons'][0]['name'],
        );
    }

    public function test_named_drink_keeps_its_name(): void
    {
        $this->assertSame('Coca-Cola', MenuFormulaLabel::forAddon('menu_boisson', 'Coca-Cola'));
        $this->assertSame('Boisson seule', MenuFormulaLabel::forAddon('menu_boisson', 'Menu (Frites + Boisson)'));
        $this->assertSame('Boisson seule', MenuFormulaLabel::forAddon('menu_boisson', ''));
        $this->assertSame('Coca', MenuFormulaLabel::forAddon('drink', 'Coca'));
    }
}
