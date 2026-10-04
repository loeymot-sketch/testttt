<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-01 2026-08-28 · P0 · NF525] Le SIRET saisi hier ne doit pas s'effacer demain.
 *
 * CE QUI SE PASSAIT. Le commerçant saisit son SIRET dans Réglages > Filiales,
 * enregistre : succès, ligne en base, ticket conforme. Il rouvre la fiche le
 * lendemain pour corriger un numéro de téléphone, enregistre — et **son SIRET part
 * à NULL**. Les tickets suivants sortent sans SIRET. Un ticket de caisse français
 * sans SIRET n'est pas conforme, et rien ne l'en avertit.
 *
 * LA CHAÎNE, en quatre maillons :
 *
 * 1. `BranchResource::toArray()` ne renvoyait PAS `siret`, `vat_intra`,
 *    `legal_footer`, `register_id`.
 * 2. `BranchListComponent.vue:201` fait `siret: branch.siret ?? ""` — sur un objet
 *    qui ne porte pas la clé. Toujours `undefined`, donc toujours `""`.
 * 3. `ConvertEmptyStringsToNull` (`Kernel.php:29`) transforme `""` en `null`.
 * 4. `nullable` laisse passer, `update()` écrit `null`.
 *
 * LE PLUS AMER : l'écran portait une garde explicite, écrite exprès, dont le
 * commentaire annonce « sans ces trois lignes, ouvrir une filiale existante puis
 * enregistrer EFFACERAIT son identité fiscale ». Elle était INERTE — elle relisait
 * une clé que l'API n'envoyait jamais. Une garde qui se croyait protectrice.
 *
 * POURQUOI CE BANC PASSE PAR HTTP. Le banc livré avec le correctif d'origine
 * (`BranchFiscalIdentityFormTest`) n'émet AUCUNE requête : il appelle
 * `Validator::make()` puis `Branch::create()`, court-circuitant le contrôleur ET la
 * ressource — exactement les deux maillons qui cassaient. Il serait resté vert
 * pendant tout l'effacement. Celui-ci exerce le vrai aller-retour : on lit ce que
 * l'écran lit, on renvoie ce que l'écran renverrait.
 */
class IdentiteFiscaleSurvitAUnSecondEnregistrementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $filiale;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->filiale = Branch::factory()->create([
            'name'         => 'Chez Nadia',
            'siret'        => '81234567800015',
            'vat_intra'    => 'FR12812345678',
            'legal_footer' => 'SARL Chez Nadia — RCS Lille 812 345 678',
        ]);

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
        Permission::findOrCreate('settings', 'sanctum');
        $this->admin->givePermissionTo('settings');
    }

    /** Ce que l'écran REÇOIT quand il ouvre la fiche. */
    private function fichLue(): array
    {
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/setting/branch/show/' . $this->filiale->id);

        $this->assertContains($reponse->status(), [200, 201], 'La fiche filiale doit se charger.');

        return $reponse->json('data') ?? $reponse->json() ?? [];
    }

    public function test_l_ecran_recoit_l_identite_fiscale_quand_il_ouvre_la_fiche(): void
    {
        // LE MAILLON QUI CASSAIT. Sans ces clés dans la réponse, la garde de l'écran
        // est inerte et l'effacement est inévitable.
        $fiche = $this->fichLue();

        foreach (['siret', 'vat_intra', 'legal_footer', 'register_id'] as $champ) {
            $this->assertArrayHasKey(
                $champ,
                $fiche,
                "`{$champ}` est absent de la réponse. L'écran fait `branch.{$champ} ?? \"\"`\n"
                . "sur une clé qui n'existe pas : il renverra une chaîne vide, que\n"
                . "`ConvertEmptyStringsToNull` transformera en NULL, et l'identité\n"
                . 'fiscale sera effacée au prochain enregistrement.'
            );
        }

        $this->assertSame('81234567800015', $fiche['siret']);
    }

    public function test_un_second_enregistrement_ne_detruit_pas_le_SIRET(): void
    {
        // LE SCÉNARIO COMPLET, tel que le commerçant le vit : il rouvre sa fiche pour
        // changer AUTRE CHOSE, et enregistre.
        $fiche = $this->fichLue();

        $charge = [
            'name'         => $fiche['name'] ?? 'Chez Nadia',
            'email'        => $fiche['email'] ?? 'nadia@example.test',
            'phone'        => '0320000000',   // le seul champ qu'il voulait corriger
            'address'      => $fiche['address'] ?? 'rue de Lille',
            'city'         => $fiche['city'] ?? 'Lille',
            'state'        => $fiche['state'] ?? 'Nord',
            'zip_code'     => $fiche['zip_code'] ?? '59000',
            'latitude'     => $fiche['latitude'] ?? '50.6',
            'longitude'    => $fiche['longitude'] ?? '3.05',
            'status'       => $fiche['status'] ?? \App\Enums\Status::ACTIVE,
            // Ce que l'écran RENVOIE : exactement ce qu'il a reçu.
            'siret'        => $fiche['siret'] ?? '',
            'vat_intra'    => $fiche['vat_intra'] ?? '',
            'legal_footer' => $fiche['legal_footer'] ?? '',
        ];

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/branch/' . $this->filiale->id, $charge);

        $apres = $this->filiale->fresh();

        $this->assertSame(
            '81234567800015',
            $apres->siret,
            "LE SIRET A ÉTÉ EFFACÉ (valeur : " . var_export($apres->siret, true) . ").\n"
            . "Le commerçant a seulement corrigé un numéro de téléphone. Les tickets\n"
            . "suivants sortiront sans SIRET — un ticket de caisse français sans SIRET\n"
            . "n'est pas conforme."
        );

        $this->assertSame('FR12812345678', $apres->vat_intra, 'La TVA intracom a été effacée.');
        $this->assertNotNull($apres->legal_footer, 'La mention légale a été effacée.');
    }

    public function test_le_commercant_peut_toujours_VIDER_volontairement_un_champ(): void
    {
        // Contrôle négatif : protéger contre l'effacement ACCIDENTEL ne doit pas
        // empêcher l'effacement VOLONTAIRE. Un commerçant qui retire sa TVA
        // intracommunautaire — parce qu'il n'y est pas assujetti — doit pouvoir.
        $fiche = $this->fichLue();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/branch/' . $this->filiale->id, [
                'name'      => $fiche['name'] ?? 'Chez Nadia',
                'email'     => $fiche['email'] ?? 'nadia@example.test',
                'phone'     => $fiche['phone'] ?? '0320000000',
                'address'   => $fiche['address'] ?? 'rue de Lille',
                'city'      => $fiche['city'] ?? 'Lille',
                'state'     => $fiche['state'] ?? 'Nord',
                'zip_code'  => $fiche['zip_code'] ?? '59000',
                'latitude'  => $fiche['latitude'] ?? '50.6',
                'longitude' => $fiche['longitude'] ?? '3.05',
                'status'    => $fiche['status'] ?? \App\Enums\Status::ACTIVE,
                'siret'     => $fiche['siret'] ?? '',
                'vat_intra' => '',            // effacement DÉLIBÉRÉ
                'legal_footer' => $fiche['legal_footer'] ?? '',
            ]);

        $apres = $this->filiale->fresh();

        $this->assertSame('81234567800015', $apres->siret, 'Le SIRET ne devait pas bouger.');
        $this->assertNull($apres->vat_intra, "L'effacement volontaire doit rester possible.");
    }

    public function test_la_ressource_expose_bien_les_quatre_champs(): void
    {
        // Garde de source : c'est l'omission dans la ressource qui a tout causé, et
        // elle est facile à réintroduire en « nettoyant » la liste des clés.
        $source = file_get_contents(app_path('Http/Resources/BranchResource.php'));

        foreach (['siret', 'vat_intra', 'legal_footer', 'register_id'] as $champ) {
            $this->assertStringContainsString(
                '"' . $champ . '"',
                $source,
                "BranchResource n'expose plus `{$champ}` : la garde de l'écran redevient\n"
                . "inerte et l'identité fiscale repart à NULL au prochain enregistrement."
            );
        }
    }
}
