<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-12 2026-08-28] La chaîne complète du tampon : écran → règle → base → borne.
 *
 * ═══ POURQUOI CE BANC EXISTE ═══
 *
 * Rendre le tampon configurable ne sert à rien si le commerçant n'a pas d'écran pour
 * le décider. C'est le motif que cette semaine a fait apparaître **cinq fois** :
 * une chaîne complète sauf l'endroit où un humain saisit la vérité — allergènes,
 * poste de cuisine, matières premières, seuils d'alerte, horaires d'ouverture.
 *
 * Ce banc refuse de laisser le tampon devenir le sixième. Il vérifie les quatre
 * maillons dans l'ordre où le commerçant les rencontre :
 *
 *   1. la règle ACCEPTE le réglage (sinon l'écran envoie dans le vide)
 *   2. la base le CONSERVE
 *   3. la relecture le RENVOIE (sinon rouvrir la page l'efface)
 *   4. la borne le LIT
 *
 * Le maillon 3 est celui qu'on oublie : sans lui, l'écran rouvre toujours sur
 * « non déclaré », et le premier enregistrement suivant efface le choix. C'est
 * exactement le défaut corrigé sur l'identité fiscale la semaine dernière.
 */
class LeCommercantPeutDeclarerOuRetirerSonTamponTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        Permission::findOrCreate('settings', 'sanctum');
        $this->patron->givePermissionTo('settings');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');
    }

    public function test_le_commercant_declare_son_tampon_et_le_retrouve_en_rouvrant(): void
    {
        $enregistrement = $this->putJson('/api/admin/setting/kiosk-setup', [
            'kiosk_welcome_title' => 'Bienvenue',
            'kiosk_halal_stamp'   => 1,
        ]);

        $this->assertContains(
            $enregistrement->status(),
            [200, 201, 202],
            'Enregistrement refusé : ' . mb_substr((string) $enregistrement->getContent(), 0, 250)
        );

        // LE MAILLON QU'ON OUBLIE : rouvrir la page doit rendre le choix.
        $relecture = $this->getJson('/api/admin/setting/kiosk-setup');
        $relecture->assertOk();

        $donnees = $relecture->json('data') ?? $relecture->json();

        $this->assertArrayHasKey(
            'kiosk_halal_stamp',
            $donnees,
            "Le réglage n'est pas relu. L'écran rouvrirait sur « non déclaré » et le\n"
            . "prochain enregistrement effacerait le choix du commerçant — le défaut\n"
            . "exact corrigé sur l'identité fiscale."
        );

        $this->assertSame(
            1,
            (int) $donnees['kiosk_halal_stamp'],
            'Le tampon déclaré ne revient pas à l\'écran.'
        );
    }

    public function test_le_commercant_peut_aussi_le_retirer(): void
    {
        // Le sens inverse compte autant : une affirmation qu'on ne peut plus retirer
        // n'est pas un réglage, c'est un piège. Et un `0` est la valeur que les
        // formulaires perdent le plus facilement en route.
        $this->putJson('/api/admin/setting/kiosk-setup', [
            'kiosk_welcome_title' => 'Bienvenue',
            'kiosk_halal_stamp'   => 1,
        ])->assertSuccessful();

        $this->putJson('/api/admin/setting/kiosk-setup', [
            'kiosk_welcome_title' => 'Bienvenue',
            'kiosk_halal_stamp'   => 0,
        ])->assertSuccessful();

        $donnees = $this->getJson('/api/admin/setting/kiosk-setup')->json('data');

        $this->assertSame(
            0,
            (int) $donnees['kiosk_halal_stamp'],
            "Le tampon ne peut pas être retiré une fois posé : ce n'est plus un\n"
            . 'réglage, c\'est une affirmation définitive sur la nourriture servie.'
        );
    }

    public function test_l_ecran_d_administration_porte_bien_la_case(): void
    {
        // Sans cette vérification, les trois maillons serveur pourraient être verts
        // pendant que le commerçant n'a toujours aucun endroit où décider.
        $ecran = file_get_contents(
            resource_path('js/components/admin/settings/KioskSetup/KioskSetupComponent.vue')
        );

        $this->assertStringContainsString(
            'data-testid="kiosk-halal-stamp"',
            $ecran,
            "L'écran de réglage borne n'a pas de case pour le tampon : la chaîne est\n"
            . "complète partout sauf là où un humain saisit la vérité."
        );

        $this->assertStringContainsString(
            'kiosk_halal_stamp:      Boolean(Number(d.kiosk_halal_stamp ?? 0))',
            $ecran,
            "L'écran n'affiche pas la valeur enregistrée en rouvrant."
        );

        // Et le libellé doit exister, sinon la case s'affiche sous une clé brute.
        $libelles = json_decode(
            file_get_contents(resource_path('js/languages/fr.json')),
            true
        );

        $this->assertNotEmpty(
            $libelles['label']['kiosk_halal_stamp'] ?? null,
            "La case s'affiche sous « label.kiosk_halal_stamp » au lieu d'un libellé."
        );
    }
}
