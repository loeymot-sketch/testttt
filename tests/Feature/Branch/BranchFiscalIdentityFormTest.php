<?php

namespace Tests\Feature\Branch;

use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [ONB-01 T-1.2.1 2026-08-27] L'identité fiscale doit être SAISISSABLE, pas
 * seulement stockable.
 *
 * Pourquoi ce fichier existe alors que BranchFiscalIdentityTest existe déjà :
 * ce dernier prouve que le MODÈLE accepte siret / vat_intra / legal_footer, en
 * appelant `Branch::create([...])` directement. Il était vert depuis avril
 * pendant que ces champs étaient, dans les faits, impossibles à renseigner :
 * `BranchService` enregistre `$request->validated()`, et `BranchRequest` n'avait
 * aucune règle pour eux — un champ sans règle n'est jamais dans `validated()`,
 * donc jamais écrit. Le ticket, lui, lisait déjà `branch->siret`.
 *
 * Un ticket de caisse français sans SIRET n'est pas conforme. Ce test garde le
 * chemin qui va du formulaire à la base, celui que l'ancien test ne touchait pas.
 */
class BranchFiscalIdentityFormTest extends TestCase
{
    use RefreshDatabase;

    /** Construit les règles de BranchRequest hors contexte HTTP. */
    private function reglesFiliale(): array
    {
        return (new BranchRequest())->rules();
    }

    /** Charge utile minimale acceptée par les règles obligatoires existantes. */
    private function chargeValide(array $enPlus = []): array
    {
        return array_merge([
            'name'     => 'Chez Nadia',
            'city'     => 'Hénin-Beaumont',
            'state'    => 'Hauts-de-France',
            'zip_code' => '62110',
            'address'  => '12 rue des Lilas',
            'status'   => 5,
        ], $enPlus);
    }

    public function test_les_regles_couvrent_les_trois_champs_fiscaux(): void
    {
        $regles = $this->reglesFiliale();

        foreach (['siret', 'vat_intra', 'legal_footer'] as $champ) {
            $this->assertArrayHasKey(
                $champ,
                $regles,
                "BranchRequest doit valider « {$champ} » : sans règle, BranchService "
                . "ne l'enregistre jamais (Branch::create(\$request->validated())), "
                . "et le ticket sort sans identité fiscale."
            );
        }
    }

    public function test_un_siret_valide_traverse_la_validation_et_arrive_en_base(): void
    {
        $charge = $this->chargeValide([
            'siret'        => '81234567800015',
            'vat_intra'    => 'FR12345678901',
            'legal_footer' => 'TVA non applicable, art. 293 B du CGI',
        ]);

        $validateur = Validator::make($charge, $this->reglesFiliale());
        $this->assertFalse($validateur->fails(), 'Une identité fiscale bien formée doit passer.');

        $valide = $validateur->validated();
        $this->assertSame('81234567800015', $valide['siret'] ?? null);
        $this->assertSame('FR12345678901', $valide['vat_intra'] ?? null);
        $this->assertSame('TVA non applicable, art. 293 B du CGI', $valide['legal_footer'] ?? null);

        // Le vrai chemin d'écriture du service, reproduit tel quel.
        $filiale = Branch::create($valide);
        $this->assertSame('81234567800015', $filiale->fresh()->siret);
    }

    public function test_un_siret_de_treize_chiffres_est_refuse(): void
    {
        $validateur = Validator::make(
            $this->chargeValide(['siret' => '8123456780001']),
            $this->reglesFiliale()
        );

        $this->assertTrue($validateur->fails(), 'Un SIRET de 13 chiffres doit être refusé.');
        $this->assertArrayHasKey('siret', $validateur->errors()->toArray());
    }

    public function test_un_siret_avec_des_lettres_ou_des_espaces_est_refuse(): void
    {
        foreach (['812 345 678 00015', '8123456780001X'] as $mauvais) {
            $validateur = Validator::make(
                $this->chargeValide(['siret' => $mauvais]),
                $this->reglesFiliale()
            );
            $this->assertTrue(
                $validateur->fails(),
                "« {$mauvais} » ne doit pas être accepté comme SIRET."
            );
        }
    }

    public function test_l_identite_fiscale_reste_facultative_pour_les_filiales_existantes(): void
    {
        // `required` aurait bloqué rétroactivement l'enregistrement de toute
        // filiale créée avant cette fonctionnalité. Ce test interdit ce durcissement.
        $validateur = Validator::make($this->chargeValide(), $this->reglesFiliale());

        $this->assertFalse(
            $validateur->fails(),
            'Une filiale sans identité fiscale doit continuer à s\'enregistrer.'
        );
    }

    public function test_le_message_d_erreur_du_siret_est_en_francais_et_sans_expression_rationnelle(): void
    {
        $validateur = Validator::make(
            $this->chargeValide(['siret' => 'nawak']),
            $this->reglesFiliale(),
            (new BranchRequest())->messages()
        );

        $this->assertTrue($validateur->fails());
        $message = $validateur->errors()->first('siret');

        $this->assertStringContainsString('14 chiffres', $message);
        $this->assertStringNotContainsString('regex', strtolower($message));
        $this->assertStringNotContainsString('/^', $message);
    }
}
