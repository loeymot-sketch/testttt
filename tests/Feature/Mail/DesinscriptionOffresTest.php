<?php

namespace Tests\Feature\Mail;

use App\Mail\SubscriberMail;
use App\Models\Subscriber;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * [test-e2e stores S13-04 · 2026-11-13] Chaque e-mail d'offre porte un lien de désinscription SIGNÉ, propre à son
 * destinataire ; un clic désinscrit, une URL forgée ne désinscrit personne.
 */
class DesinscriptionOffresTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'subscribers', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first()->givePermissionTo(['subscribers']);
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        return $admin;
    }

    public function test_un_email_par_abonne_avec_son_propre_lien_de_desinscription(): void
    {
        Mail::fake();
        $admin = $this->admin();
        Subscriber::query()->create(['email' => 'a@example.test']);
        Subscriber::query()->create(['email' => 'b@example.test']);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/subscriber/send-email', ['subject' => 'Menu de Noël', 'message' => 'Découvrez la carte.'])
            ->assertOk();

        Mail::assertSent(SubscriberMail::class, 2);
        foreach (['a@example.test', 'b@example.test'] as $email) {
            Mail::assertSent(SubscriberMail::class, function (SubscriberMail $m) use ($email) {
                $m->build();
                return $m->hasTo($email) && is_string($m->lienDesinscription)
                    && str_contains($m->lienDesinscription, 'signature=') && str_contains($m->lienDesinscription, urlencode($email));
            });
        }
    }

    public function test_le_lien_signe_desinscrit(): void
    {
        Subscriber::query()->create(['email' => 'client@example.test']);
        $lien = URL::signedRoute('newsletter.desinscription', ['email' => 'client@example.test']);

        // [S14-04] Ouvrir le lien (antivirus, aperçu) ne désinscrit PAS : page de confirmation avec un bouton.
        $this->get($lien)->assertOk()->assertSee('Me désinscrire', false);
        $this->assertSame(1, Subscriber::query()->where('email', 'client@example.test')->count());
        // Le bouton (ou le « un clic » de la messagerie) désinscrit.
        $this->post($lien)->assertOk()->assertSee('Vous êtes désinscrit', false);
        $this->assertSame(0, Subscriber::query()->where('email', 'client@example.test')->count());
    }

    /** [S14-04] Mise en page : titre et paragraphes, jamais un bloc de code (indentation Markdown) ; lien présent. */
    public function test_l_email_d_offre_est_mis_en_page_et_porte_le_lien(): void
    {
        $this->seedMinimalSettings();
        $html = (new SubscriberMail('Menu de Noël', 'Découvrez la carte.', 'https://exemple.test/desinscription-offres?signature=x'))->render();
        $this->assertStringNotContainsString('<code>', $html);
        $this->assertStringNotContainsString('<pre>', $html);
        $this->assertStringContainsString('Découvrez la carte.', $html);
        $this->assertStringContainsString('Se désinscrire', $html);
        $this->assertStringNotContainsString('All rights reserved', $html); // [P-026] pied de page en français
    }

    public function test_une_url_forgee_ne_desinscrit_personne(): void
    {
        Subscriber::query()->create(['email' => 'client@example.test']);

        $this->get('/desinscription-offres?email=client@example.test')->assertStatus(403);
        $this->get('/desinscription-offres?email=client@example.test&signature=faux')->assertStatus(403);
        // [P-026] La page de refus est en français (plus « 403 | INVALID SIGNATURE. »).
        $this->get('/desinscription-offres?email=client@example.test&signature=faux')->assertSee('Lien de désinscription invalide', false);
        $this->post('/desinscription-offres?email=client@example.test&signature=faux')->assertStatus(403);
        $this->assertSame(1, Subscriber::query()->where('email', 'client@example.test')->count());
    }
}
