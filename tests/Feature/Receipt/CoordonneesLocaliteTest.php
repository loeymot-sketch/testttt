<?php

namespace Tests\Feature\Receipt;

use App\Support\CoordonneesClientTicket;
use PHPUnit\Framework\TestCase;

/**
 * [SUPERVISION 2026-11-10] La ligne « code postal commune » sous la voie : UNE règle (`CoordonneesClientTicket::localite`)
 * pour le ticket, le reçu, la planche, l'écran du livreur et la page de remise. Jumeau JS : `ReceiptComponent.vue`
 * (`tests/js/receiptComponentLivraison.spec.js` rejoue la MÊME matrice — si l'un bouge, l'autre doit bouger).
 *
 * Trouvé par la relecture adverse du jour : « 10 Rue Test 62110 » + commune absente sortait « 62110 Hénin-Beaumont »
 * (code postal doublé) ; « rue du Hameau » faisait croire que la commune « Ham » était déjà écrite.
 */
class CoordonneesLocaliteTest extends TestCase
{
    /** @return array<string, array{0:string,1:string,2:string,3:string}> */
    public static function cas(): array
    {
        return [
            'voie BAN complète → rien à ajouter' => ['20 Place de la République 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', ''],
            'voie BAN, commune en base sans accents ni casse' => ['20 Place de la République 62110 Hénin-Beaumont', '62110', 'HENIN-BEAUMONT', ''],
            'voie saisie avec la commune sans le code → le code seul' => ['10 Rue Test, Hénin-Beaumont', '62110', 'Hénin-Beaumont', '62110'],
            'voie saisie avec le code sans la commune → la commune seule' => ['10 Rue Test 62110', '62110', 'Hénin-Beaumont', 'Hénin-Beaumont'],
            'ni code ni commune dans la voie → « CP commune »' => ['10 Rue Test', '62110', 'Hénin-Beaumont', '62110 Hénin-Beaumont'],
            'commune vide → le code seul' => ['10 Rue Test', '62110', '', '62110'],
            'code vide → la commune seule' => ['10 Rue Test', '', 'Hénin-Beaumont', 'Hénin-Beaumont'],
            'tout vide' => ['', '', '', ''],
            'voie vide → « CP commune » quand même' => ['', '62110', 'Hénin-Beaumont', '62110 Hénin-Beaumont'],
            '« Ham » n\'est pas dans « Hameau » (mot entier)' => ['12 rue du Hameau', '80400', 'Ham', '80400 Ham'],
            '« 6211 » n\'est pas « 62110 » (nombre entier)' => ['3 impasse du 6211', '62110', 'Hénin-Beaumont', '62110 Hénin-Beaumont'],
            'apostrophe typographique en base, droite dans la voie' => ["4 rue X, L'Hay-les-Roses", '94240', 'L’Haÿ-les-Roses', '94240'],
            '« œ » en base, « oe » dans la voie' => ['2 rue Y 51480 Oeuilly', '51480', 'Œuilly', ''],
        ];
    }

    /** @dataProvider cas */
    public function test_la_ligne_localite(string $voie, string $cp, string $commune, string $attendu): void
    {
        $this->assertSame($attendu, CoordonneesClientTicket::localite($voie, $cp, $commune));
    }
}
