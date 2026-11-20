<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneTemperatureReading;
use App\Services\Hygiene\ReportBuilder;
use Carbon\Carbon;
use Dompdf\FrameReflower\Text as DompdfText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M8] Dossier PDF d'inspection : dompdf coupe une ligne APRÈS un trait d'union (`Text::$_wordbreak_pattern`), donc
 * « -19 » d'un congélateur s'imprimait « - » puis « 19 » à la ligne : un inspecteur pouvait lire +19 °C. Toute valeur signée du
 * PDF (relevé, seuils, réception, bain, incidents) s'écrit avec le VRAI signe moins (U+2212, jamais une coupure pour dompdf),
 * collé au nombre et à « °C » (espaces insécables), dans un élément `white-space: nowrap`. Et les seuils se lisent comme à
 * l'écran (`limitText`) : « ≥ +63 °C » pour un bain-marie, jamais « 63 à — ».
 */
class HygieneReportPdfSignTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";
    private const MINUS = "\u{2212}";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function equipment(string $name, string $kind, ?float $min, ?float $max): HygieneEquipment
    {
        $e = new HygieneEquipment(['name' => $name, 'kind' => $kind, 'min_c' => $min, 'max_c' => $max, 'sort_order' => 10, 'is_active' => true]);
        $e->branch_id = 1;
        $e->created_at = Carbon::parse('2026-10-10');
        $e->save();

        return $e;
    }

    private function reading(HygieneEquipment $e, float $v, string $status, ?string $reason = null): void
    {
        $r = new HygieneTemperatureReading([
            'equipment_id' => $e->id, 'equipment_name' => $e->name, 'equipment_kind' => $e->kind, 'min_c' => $e->min_c, 'max_c' => $e->max_c,
            'value_c' => $v, 'status' => $status, 'alert_reason' => $reason, 'period' => 'morning',
            'recorded_at' => Carbon::parse('2026-10-20 08:00', config('app.timezone')), 'operator_name' => 'Karim Benali',
        ]);
        $r->branch_id = 1;
        $r->save();
    }

    private function html(): string
    {
        $freezer = $this->equipment('Congélateur 1', 'freezer', null, -18.0);
        $hot = $this->equipment('Bain-marie', 'hot_holding', 63.0, null);
        $fridge = $this->equipment('Frigo 1', 'fridge', 0.0, 4.0);
        $this->reading($freezer, -19.0, 'ok');
        $this->reading($freezer, -12.0, 'alert', 'too_warm');
        $this->reading($hot, 70.0, 'ok');
        $this->reading($fridge, -2.0, 'alert', 'too_cold');

        $report = ReportBuilder::between('2026-10-19', '2026-10-20')->build(true);
        // une réception avec un surgelé, et un bain de friture : mêmes formes que ReportBuilder
        $report['receptions']['rows'] = [[
            'at' => '2026-10-20T07:00:00+02:00', 'supplier' => 'Metro', 'delivery_note' => 'BL1', 'driver' => null,
            'temperatures' => [['label' => 'Surgelés', 'value_c' => -20.0, 'max_c' => -18.0, 'status' => 'ok']],
            'checks_ko' => [], 'status' => 'ok', 'decision' => 'Acceptée', 'note' => null, 'operator' => 'Karim', 'voided' => false, 'void_reason' => null,
        ]];
        $report['receptions']['rows_total'] = 1;
        $report['oil']['rows'] = [[
            'at' => '2026-10-20T07:30:00+02:00', 'fryer' => 'Friteuse 1', 'type' => 'Test de l’huile', 'polar_pct' => 12.0, 'bath_temp_c' => 172.0,
            'oil_litres' => null, 'oil_brand' => null, 'status' => 'ok', 'action' => null, 'note' => null, 'operator' => 'Karim', 'voided' => false, 'void_reason' => null,
        ]];
        $report['oil']['rows_total'] = 1;

        $html = view('pdf.hygiene_report', ['report' => $report, 'company' => [], 'generatedAt' => 'x', 'generatedBy' => 'y', 'fingerprint' => 'z'])->render();

        return $html;
    }

    public function test_aucun_signe_moins_ne_peut_etre_separe_de_son_nombre(): void
    {
        $html = $this->html();
        $body = substr($html, strpos($html, '<body'));

        // 1) plus aucun trait d'union devant un chiffre : c'est LÀ que dompdf coupe la ligne
        $this->assertSame(1, preg_match(DompdfText::$_wordbreak_pattern, '-19'), 'garde : dompdf coupe bien après un trait d\'union');
        $this->assertSame(0, preg_match('/(?<![\w])-\d/u', strip_tags($body)), 'un « -19 » restant se couperait en « - » / « 19 »');
        // 2) et le vrai signe moins n'est PAS un point de coupure pour dompdf
        $this->assertSame(0, preg_match(DompdfText::$_wordbreak_pattern, self::MINUS.'19'.self::NBSP.'°C'));

        // relevés (colonne °C) : valeur insécable
        $this->assertStringContainsString('<span class="nb">'.self::MINUS.'19</span>', $body);
        $this->assertStringContainsString('<span class="nb">'.self::MINUS.'2</span>', $body);
        // seuils écrits comme à l'écran : « ≤ −18 °C », « 0 à +4 °C », « ≥ +63 °C » — jamais « 63 à — »
        $this->assertStringContainsString('<span class="nb">≤'.self::NBSP.self::MINUS.'18'.self::NBSP.'°C</span>', $body);
        $this->assertStringContainsString('<span class="nb">0</span> à <span class="nb">+4'.self::NBSP.'°C</span>', $body);
        $this->assertStringContainsString('<span class="nb">≥'.self::NBSP.'+63'.self::NBSP.'°C</span>', $body);
        $this->assertStringNotContainsString('63 à —', $body);
        // réception d'un surgelé, bain de friture
        $this->assertStringContainsString('Surgelés <span class="nb">'.self::MINUS.'20'.self::NBSP.'°C</span>', $body);
        $this->assertStringContainsString('<span class="nb">172</span>', $body);
        // incidents : « Congélateur 1 : −12 °C (trop chaud) », signe et unité collés
        $this->assertStringContainsString('Congélateur 1 : '.self::MINUS.'12'.self::NBSP.'°C (trop chaud)', $body);

        // la règle qui rend l'élément insécable
        $this->assertMatchesRegularExpression('/\.nb\s*\{\s*white-space:\s*nowrap;/', $html);
    }
}
