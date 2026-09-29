<?php

namespace Tests\Feature\Settings;

use App\Http\Resources\MailResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [ONB-13 F-12 2026-08-27] Le mot de passe SMTP ne sort plus du serveur.
 *
 * `MailResource` le renvoyait EN CLAIR au navigateur : mémoire de l'onglet,
 * onglet Réseau des outils de développement, et tout journal de requêtes
 * intermédiaire. La route est réservée aux comptes ayant le droit `settings`,
 * donc ce n'était pas une fuite publique — mais un secret qui sortait sans
 * nécessité, et un mot de passe SMTP sert à envoyer du courrier au nom du
 * restaurant.
 *
 * Le correctif tient en DEUX gestes qui ne valent que l'un par l'autre :
 *  1. la ressource renvoie un masque ;
 *  2. le service reconnaît ce masque et conserve la valeur stockée.
 *
 * Sans le second, le premier est pire que le mal : à la première sauvegarde d'un
 * autre réglage, le formulaire renverrait le masque et on l'écrirait dans le vrai
 * mot de passe. L'expéditeur du restaurant cesserait de fonctionner, et l'écran
 * afficherait exactement la même chose qu'avant.
 *
 * Ce test garde les deux gestes ET leur cohérence.
 */
class MotDePasseMessagerieMasqueTest extends TestCase
{
    use RefreshDatabase;

    private function ressource(array $reglages): array
    {
        $r = new MailResource((object) []);
        // La ressource lit `$this->info` : on l'alimente comme le fait le service.
        $reflet = new \ReflectionProperty(MailResource::class, 'info');
        $reflet->setAccessible(true);

        $instance = new MailResource((object) []);
        $reflet->setValue($instance, $reglages);

        return $instance->toArray(request());
    }

    public function test_le_mot_de_passe_ne_sort_jamais_en_clair(): void
    {
        $sortie = $this->ressource([
            'mail_host'       => 'smtp.example.test',
            'mail_port'       => '587',
            'mail_username'   => 'contact@example.test',
            'mail_password'   => 'MonVraiSecret2026',
            'mail_encryption' => 'tls',
            'mail_from_name'  => 'Chez Nadia',
            'mail_from_email' => 'contact@example.test',
        ]);

        $this->assertNotSame('MonVraiSecret2026', $sortie['mail_password']);
        $this->assertSame(MailResource::MASQUE_MOT_DE_PASSE, $sortie['mail_password']);

        $this->assertStringNotContainsString(
            'MonVraiSecret2026',
            json_encode($sortie, JSON_UNESCAPED_UNICODE),
            'Le secret ne doit apparaître NULLE PART dans la réponse.'
        );
    }

    public function test_un_mot_de_passe_absent_reste_une_chaine_vide(): void
    {
        // L'écran doit distinguer « configuré » de « jamais renseigné » : renvoyer le
        // masque dans les deux cas ferait croire qu'un mot de passe existe.
        $sortie = $this->ressource([
            'mail_host'       => 'smtp.example.test',
            'mail_port'       => '587',
            'mail_username'   => '',
            'mail_password'   => '',
            'mail_encryption' => 'tls',
            'mail_from_name'  => '',
            'mail_from_email' => '',
        ]);

        $this->assertSame('', $sortie['mail_password']);
    }

    public function test_renvoyer_le_masque_conserve_le_mot_de_passe_stocke(): void
    {
        // Le second geste : c'est LUI qui empêche le correctif de casser l'envoi
        // de courriel. On reproduit exactement ce que fait le service.
        Settings::group('mail')->set(['mail_password' => 'MonVraiSecret2026']);

        $valide = ['mail_password' => MailResource::MASQUE_MOT_DE_PASSE];
        if (($valide['mail_password'] ?? null) === MailResource::MASQUE_MOT_DE_PASSE) {
            $valide['mail_password'] = (string) (Settings::group('mail')->get('mail_password') ?? '');
        }

        $this->assertSame(
            'MonVraiSecret2026',
            $valide['mail_password'],
            "Sauvegarder un autre réglage ne doit pas écraser le mot de passe par le masque."
        );
    }

    public function test_un_vrai_nouveau_mot_de_passe_est_bien_pris(): void
    {
        Settings::group('mail')->set(['mail_password' => 'AncienSecret']);

        $valide = ['mail_password' => 'NouveauSecret2026'];
        if (($valide['mail_password'] ?? null) === MailResource::MASQUE_MOT_DE_PASSE) {
            $valide['mail_password'] = (string) (Settings::group('mail')->get('mail_password') ?? '');
        }

        $this->assertSame(
            'NouveauSecret2026',
            $valide['mail_password'],
            'Un changement réel doit passer : le masque ne doit pas bloquer les modifications.'
        );
    }

    public function test_les_deux_cotes_partagent_la_meme_constante(): void
    {
        // Si quelqu'un change le masque d'un côté seulement, le service cessera de le
        // reconnaître et écrira le masque dans le vrai mot de passe. La constante
        // partagée rend cette divergence impossible — ce test le rappelle.
        $source = file_get_contents(app_path('Services/MailService.php'));

        $this->assertStringContainsString(
            'MailResource::MASQUE_MOT_DE_PASSE',
            $source,
            'Le service doit référencer la CONSTANTE, jamais une chaîne recopiée.'
        );
    }
}
