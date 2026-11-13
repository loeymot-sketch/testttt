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
                return $m->hasTo($email) && is_string($m->lienDesinscription)
                    && str_contains($m->lienDesinscription, 'signature=') && str_contains($m->lienDesinscription, urlencode($email));
            });
        }
    }

    public function test_le_lien_signe_desinscrit(): void
    {
        Subscriber::query()->create(['email' => 'client@example.test']);
        $lien = URL::signedRoute('newsletter.desinscription', ['email' => 'client@example.test']);

        $this->get($lien)->assertOk()->assertSee('Vous êtes désinscrit', false);
        $this->assertSame(0, Subscriber::query()->where('email', 'client@example.test')->count());
    }

    public function test_une_url_forgee_ne_desinscrit_personne(): void
    {
        Subscriber::query()->create(['email' => 'client@example.test']);

        $this->get('/desinscription-offres?email=client@example.test')->assertStatus(403);
        $this->get('/desinscription-offres?email=client@example.test&signature=faux')->assertStatus(403);
        $this->assertSame(1, Subscriber::query()->where('email', 'client@example.test')->count());
    }
}
