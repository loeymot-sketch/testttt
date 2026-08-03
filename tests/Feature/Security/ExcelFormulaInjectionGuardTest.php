<?php

namespace Tests\Feature\Security;

use App\Support\Excel\FormulaGuardValueBinder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V3 2026-07-02 — P2] Les exports Excel ne doivent JAMAIS lier une valeur utilisateur
 * en FORMULE (injection CSV/Excel amorçable via, ex., le name d'un signup public → CustomerExport).
 */
class ExcelFormulaInjectionGuardTest extends TestCase
{
    private function bind($value)
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $cell = $sheet->getCell('A1');
        (new FormulaGuardValueBinder())->bindValue($cell, $value);
        return $cell;
    }

    /** @test */
    public function le_binder_global_est_bien_cable_dans_la_config()
    {
        $this->assertSame(
            FormulaGuardValueBinder::class,
            config('excel.value_binder.default'),
            'le value_binder anti-injection doit être le binder par défaut des exports'
        );
    }

    /**
     * @test
     * @dataProvider dangerousLeadingProvider
     */
    public function les_valeurs_a_caractere_dangereux_sont_neutralisees_en_texte(string $payload)
    {
        $cell = $this->bind($payload);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "[$payload] doit être une cellule TEXTE, pas une formule");
        $this->assertNotSame('f', $cell->getDataType(), 'ne doit jamais être dataType formule');
        // La charge est préservée (préfixée) mais inerte.
        $this->assertStringContainsString(ltrim($payload, "=+-@"), $cell->getValue());
    }

    public static function dangerousLeadingProvider(): array
    {
        return [
            'formule ='   => ['=1+1'],
            'RCE cmd'     => ['=cmd|"/c calc"!A1'],
            'plus +'      => ['+1+1'],
            'moins -'     => ['-1+1'],
            'at @'        => ['@SUM(A1:A9)'],
        ];
    }

    /**
     * @test
     * Intégration bout-en-bout : un VRAI export Maatwebsite (avec une charge malveillante contrôlée
     * par l'utilisateur) doit produire une cellule TEXTE, pas une formule, une fois le fichier relu.
     */
    public function un_vrai_export_neutralise_la_formule_injectee()
    {
        $export = new class implements FromCollection {
            public function collection()
            {
                return collect([['=cmd|"/c calc"!A1', 'Alice', '=1+1']]);
            }
        };

        $binary = Excel::raw($export, ExcelWriter::XLSX);
        $tmp = tempnam(sys_get_temp_dir(), 'fkinj') . '.xlsx';
        file_put_contents($tmp, $binary);
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType(), 'A1 (=cmd RCE) doit être TEXTE, pas formule');
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C1')->getDataType(), 'C1 (=1+1) doit être TEXTE, pas formule');
        $this->assertSame('Alice', $sheet->getCell('B1')->getValue(), 'B1 (valeur normale) inchangée');

        @unlink($tmp);
    }

    /** @test */
    public function les_valeurs_normales_et_numeriques_passent_inchangees()
    {
        // Chaîne normale → pas de préfixe parasite.
        $name = $this->bind('Alice Dupont');
        $this->assertSame('Alice Dupont', $name->getValue());

        // Nombre → cellule numérique (pas de régression sur montants/quantités).
        $amount = $this->bind(42.5);
        $this->assertSame(DataType::TYPE_NUMERIC, $amount->getDataType());

        // Email normal → inchangé.
        $email = $this->bind('client@example.com');
        $this->assertSame('client@example.com', $email->getValue());
    }
}
