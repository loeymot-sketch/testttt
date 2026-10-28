<?php

namespace Tests\Unit;

use App\Support\KioskAutoLoginGate;
use PHPUnit\Framework\TestCase;

/**
 * [QA_LOOP_NEXT_ACTION_2026-09-29] Un refus d'auto-login borne doit DIRE pourquoi.
 *
 * Le garde est volontairement fermé, et c'est bien : sans voie autorisée, aucun
 * identifiant machine ne part dans le HTML. Mais un refus ne laissait AUCUNE trace —
 * la borne affichait « Borne momentanément indisponible », le HTML portait
 * `kioskAutoLogin: null`, et la recette du 29/09 a dû remonter la cause en lisant le
 * code puis en testant à l'aveugle avec et sans `machine_key`.
 *
 * `motifDeRefus` ne décide rien : elle nomme la première condition manquante, pour que
 * l'exploitant sache quel geste faire. Ces tests figent les deux moitiés du contrat :
 * le motif est juste, ET il ne contient jamais de secret.
 */
class KioskAutoLoginMotifDeRefusTest extends TestCase
{
    private const PAYLOAD = ['username' => 'kiosk-x', 'password' => 'mot-de-passe-machine'];

    public function test_hors_chemin_borne(): void
    {
        $this->assertSame(
            'chemin_hors_borne',
            KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, false, false, [], null)
        );
    }

    public function test_identifiants_machine_absents_est_la_cause_de_mise_en_service(): void
    {
        $this->assertSame(
            'identifiants_machine_absents',
            KioskAutoLoginGate::motifDeRefus(null, true, false, ['10.0.0.0/8'], '10.0.0.5'),
            'Payload nul = identifiants absents de la config ou aucune machine active : '
            . 'c\'est la cause la plus fréquente à la mise en service, elle doit être nommée.'
        );
    }

    public function test_aucune_voie_configuree_quand_ni_secret_ni_plage(): void
    {
        $this->assertSame(
            'aucune_voie_configuree',
            KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, [], '203.0.113.7'),
            '« Rien n\'est configuré » et « configuré mais cette borne n\'y est pas » '
            . 'appellent deux gestes différents : il faut les distinguer.'
        );
    }

    public function test_secret_fourni_mais_invalide(): void
    {
        $this->assertSame(
            'secret_fourni_invalide',
            KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, [], '203.0.113.7', 'mauvais', 'le-vrai-secret')
        );
    }

    public function test_borne_hors_des_plages_de_confiance(): void
    {
        $this->assertSame(
            'borne_non_autorisee',
            KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, ['10.0.0.0/8'], '203.0.113.7')
        );
    }

    /** Toute voie autorisée rend `null` : le motif ne doit jamais contredire la décision. */
    public function test_aucun_motif_quand_l_acces_est_accorde(): void
    {
        $accordes = [
            'bypass local' => KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, true, [], null),
            'grant persistant' => KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, [], null, null, '', true),
            'secret valide' => KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, [], null, 'secret', 'secret'),
            'IP de confiance' => KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, ['10.0.0.0/8'], '10.1.2.3'),
        ];
        foreach ($accordes as $voie => $motif) {
            $this->assertNull($motif, "Voie « {$voie} » : accès accordé, donc aucun motif de refus.");
        }
    }

    /**
     * LE CONTRÔLE QUI COMPTE : le motif part au journal. Il ne doit jamais porter le
     * secret, le mot de passe machine, ni l'adresse de la borne.
     */
    public function test_le_motif_ne_fuit_jamais_de_secret(): void
    {
        $motifs = [
            KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, ['10.0.0.0/8'], '203.0.113.7', 'secret-fourni', 'le-vrai-secret'),
            KioskAutoLoginGate::motifDeRefus(self::PAYLOAD, true, false, [], '203.0.113.7', null, ''),
            KioskAutoLoginGate::motifDeRefus(null, true, false, [], '203.0.113.7'),
        ];
        foreach ($motifs as $motif) {
            $this->assertIsString($motif);
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $motif, 'Motif court et stable, jamais une donnée.');
            foreach (['le-vrai-secret', 'secret-fourni', 'mot-de-passe-machine', 'kiosk-x', '203.0.113.7', '10.0.0.0'] as $sensible) {
                $this->assertStringNotContainsString($sensible, $motif);
            }
        }
    }

    /** La décision elle-même est inchangée : le diagnostic n'ouvre aucune porte. */
    public function test_le_diagnostic_ne_change_pas_la_decision(): void
    {
        $refuse = KioskAutoLoginGate::resolvePayload(self::PAYLOAD, true, false, [], '203.0.113.7');
        $this->assertNull($refuse, 'Sans voie autorisée, aucun identifiant ne doit sortir.');

        $accorde = KioskAutoLoginGate::resolvePayload(self::PAYLOAD, true, false, ['10.0.0.0/8'], '10.1.2.3');
        $this->assertSame(self::PAYLOAD, $accorde);
    }
}
