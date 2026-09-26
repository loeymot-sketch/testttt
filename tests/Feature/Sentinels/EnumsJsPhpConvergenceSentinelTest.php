<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use ReflectionClass;
use Tests\TestCase;

/**
 * [GOAL G1 · V-13 2026-09-03] Les enums du navigateur ne peuvent pas diverger de PHP en silence.
 *
 * LE DÉFAUT — une DETTE, pas une panne
 * ------------------------------------
 * Les quatre files du tiroir de contrôle décident du sort d'une commande à partir de nombres :
 * 8 = prête, 13 = livrée, 15 = paiement au comptoir. Ces nombres vivaient RECOPIÉS À LA MAIN
 * dans `resources/js/support/filesControle.js` et `resources/js/support/fileCuisine.js` — treize
 * littéraux au total. Ils concordaient le jour de la revue. Rien n'empêchait la divergence du
 * lendemain : changer `App\Enums\OrderStatus::PREPARED` n'aurait fait rougir AUCUN banc, et la
 * file « prêtes » se serait vidée en silence sur la caisse, un soir de service.
 *
 * CE QUE CE BANC VERROUILLE
 * -------------------------
 *  §1 les quatre modules d'enums JS canoniques valent EXACTEMENT leurs interfaces PHP —
 *     mêmes clés, mêmes valeurs, ni en plus ni en moins ;
 *  §2 les deux modules des files IMPORTENT ces enums au lieu de recopier des nombres : une
 *     recopie réintroduite fait rougir ici, avant d'atteindre un écran.
 *
 * Preuve que ce banc mord : `reports/supervision/2026-09-03/G1-bancs-mordent.txt` — la valeur
 * `PREPARED` du module JS a été mutée à 9, le banc a rougi, la valeur a été restaurée.
 */
class EnumsJsPhpConvergenceSentinelTest extends TestCase
{
    /** Interface PHP ⇒ module JS canonique. */
    private const APPARIEMENT = [
        OrderStatus::class      => 'resources/js/enums/modules/orderStatusEnum.js',
        PaymentStatus::class    => 'resources/js/enums/modules/paymentStatusEnum.js',
        OrderType::class        => 'resources/js/enums/modules/orderTypeEnum.js',
        PosPaymentMethod::class => 'resources/js/enums/modules/posPaymentMethodEnum.js',
    ];

    /** Les modules qui décident des quatre files : ils lisent les enums, ils ne les recopient pas. */
    private const MODULES_DES_FILES = [
        'resources/js/support/filesControle.js',
        'resources/js/support/fileCuisine.js',
    ];

    /** Lit un module d'enum JS et en rend la table clé ⇒ entier. */
    private function enumJs(string $chemin): array
    {
        $absolu = base_path($chemin);
        $this->assertFileExists($absolu, "Module d'enum JS introuvable : {$chemin}");

        $source = file_get_contents($absolu);
        // Un seul objet gelé par fichier, une paire par ligne : `CLE: 12,`.
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*:\s*(\d+)\s*,?\s*$/m', $source, $paires, PREG_SET_ORDER);

        $table = [];
        foreach ($paires as $paire) {
            $table[$paire[1]] = (int) $paire[2];
        }

        $this->assertNotEmpty($table, "Aucune paire clé/valeur lue dans {$chemin} — le banc mesurerait le vide.");

        return $table;
    }

    /** §1 — Parité stricte PHP ⇄ JS, dans les deux sens. */
    public function test_les_enums_js_valent_exactement_les_enums_php(): void
    {
        foreach (self::APPARIEMENT as $interface => $module) {
            $php = (new ReflectionClass($interface))->getConstants();
            $js = $this->enumJs($module);

            ksort($php);
            ksort($js);

            $this->assertSame(
                $php,
                $js,
                "Divergence entre {$interface} et {$module}. Les quatre files de la caisse décident ".
                "du sort d'une commande sur ces nombres : un écart ici vide une file en silence, ".
                "sans erreur, sans message, un soir de service."
            );
        }
    }

    /** §2 — Les modules des files lisent les enums canoniques ; ils ne recopient plus de nombres. */
    public function test_les_modules_des_files_importent_les_enums_au_lieu_de_les_recopier(): void
    {
        foreach (self::MODULES_DES_FILES as $module) {
            $absolu = base_path($module);
            $this->assertFileExists($absolu);
            $source = file_get_contents($absolu);

            $this->assertMatchesRegularExpression(
                '#from\s+[\'"][^\'"]*enums/modules/#',
                $source,
                "{$module} doit IMPORTER les enums canoniques (resources/js/enums/modules/) ".
                "plutôt que recopier des nombres à la main."
            );

            // Une constante de statut/paiement réintroduite en dur — le motif exact de la dette V-13.
            preg_match_all(
                '/^\s*const\s+(STATUT|PAIEMENT|TYPE|REGLEMENT)_[A-Z0-9_]+\s*=\s*\d+\s*;/m',
                $source,
                $recopies
            );

            $this->assertSame(
                [],
                $recopies[0],
                "{$module} recopie à la main des valeurs d'enum : ".implode(' | ', $recopies[0]).
                ". C'est exactement la dette que ce banc ferme — la valeur doit venir de ".
                "resources/js/enums/modules/, seul miroir vérifié de App\\Enums\\."
            );
        }
    }
}
