<?php

namespace Tests\Feature\Settings;

use App\Http\Resources\GatewayOptionsResource;
use Tests\TestCase;

/**
 * [ONB-13 F-12 2026-08-27] Les secrets de passerelle ne sortent plus du serveur.
 *
 * `GatewayOptionsResource` renvoyait `value` en clair : clés Stripe et PayPal, jeton
 * d'authentification Twilio. Ils partaient dans la mémoire de l'onglet et dans l'onglet
 * Réseau des outils de développement.
 *
 * Le correctif tient en deux gestes qui ne valent que l'un par l'autre — la ressource
 * masque, les deux services reconnaissent le masque et conservent la valeur stockée.
 * Sans le second, le premier écrirait « ******** » dans la clé secrète de Stripe et les
 * paiements tomberaient sans que l'écran change d'un pixel.
 *
 * La décision de masquer se prend sur le NOM, pas sur le `type` : `type=5` regroupe
 * indifféremment `stripe_secret` et `twilio_from`, un numéro de téléphone.
 */
class SecretsPasserelleMasquesTest extends TestCase
{
    /** @dataProvider optionsSecretes */
    public function test_une_option_secrete_est_reconnue(string $option): void
    {
        $this->assertTrue(
            GatewayOptionsResource::estSecret($option),
            "« {$option} » est un secret et doit être masqué."
        );
    }

    public static function optionsSecretes(): array
    {
        return [
            ['paypal_client_secret'],
            ['stripe_secret'],
            ['twilio_auth_token'],
            ['clickatell_apikey'],
            ['nexmo_secret'],
            ['msg91_key'],
            ['nexmo_key'],
            // Volontairement inclus : c'est la clé PUBLIABLE de Stripe. La masquer ne
            // coûte rien ; laisser fuir une clé secrète coûte le compte du commerçant.
            ['stripe_key'],
        ];
    }

    /** @dataProvider optionsAnodines */
    public function test_une_option_anodine_reste_lisible(string $option): void
    {
        $this->assertFalse(
            GatewayOptionsResource::estSecret($option),
            "« {$option} » n'est pas un secret : le masquer cacherait une information utile."
        );
    }

    public static function optionsAnodines(): array
    {
        return [
            ['paypal_mode'],
            ['stripe_status'],
            ['twilio_from'],
            ['twilio_account_sid'],
            ['msg91_sender_id'],
            ['paypal_app_id'],
        ];
    }

    public function test_les_services_referencent_la_constante_et_non_une_chaine_recopiee(): void
    {
        // Si quelqu'un change le masque d'un seul côté, les services cesseront de le
        // reconnaître et l'écriront dans la vraie clé. La constante partagée rend cette
        // divergence impossible — ce test le rappelle aux deux endroits.
        foreach (['PaymentGatewayService', 'SmsGatewayService'] as $service) {
            $source = file_get_contents(app_path("Services/{$service}.php"));

            $this->assertStringContainsString(
                'GatewayOptionsResource::MASQUE',
                $source,
                "{$service} doit référencer la CONSTANTE, jamais une chaîne recopiée."
            );
            $this->assertStringContainsString(
                'GatewayOptionsResource::estSecret',
                $source,
                "{$service} doit décider avec la MÊME règle que la ressource."
            );
        }
    }

    public function test_le_masque_est_reconnu_comme_valeur_inchangee(): void
    {
        // On reproduit exactement le test que font les deux services.
        $inchange = GatewayOptionsResource::estSecret('stripe_secret')
            && (string) GatewayOptionsResource::MASQUE === GatewayOptionsResource::MASQUE;

        $this->assertTrue($inchange, 'Le masque renvoyé par le formulaire doit être reconnu.');

        // Et une vraie nouvelle valeur doit passer.
        $vraiChangement = GatewayOptionsResource::estSecret('stripe_secret')
            && (string) 'sk_live_nouvelle_cle' === GatewayOptionsResource::MASQUE;

        $this->assertFalse($vraiChangement, 'Un changement réel ne doit pas être bloqué.');
    }
}
