<?php

namespace Database\Seeders;


use App\Enums\Activity;
use App\Enums\CurrencyPosition;
use App\Models\Currency;
use App\Models\Language;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Smartisan\Settings\Facades\Settings;

class SiteTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $envService = new EnvEditor();
        Settings::group('site')->set([
            'site_date_format' => 'd-m-Y',
            // [QA 2026-09-28 · P1-43] Semait 'h:i A' (12 h en-US) alors que la valeur
            // canonique est 'H:i' — 24 h FR, verrou ADR-007 rappele dans AppLibrary.
            // Toute nouvelle installation partait donc en 12 h, pendant que le ticket
            // imprime, le KDS et les exports formatent en dur en 24 h : choisir 12 h
            // scindait reellement le produit.
            'site_time_format' => 'H:i',
            'site_default_timezone' => 'Europe/Paris',
            'site_default_branch' => 1,
            'site_default_currency' => 1,
            'site_default_currency_symbol' => '€',
            'site_currency_position' => CurrencyPosition::RIGHT,
            'site_digit_after_decimal_point' => '2',
            'site_email_verification' => Activity::ENABLE,
            'site_phone_verification' => Activity::DISABLE,
            'site_default_language' => Language::query()->where('code', 'fr')->value('id') ?? 2,
            'site_google_map_key' => $envService->getValue(
                'DEMO'
            ) ? 'Fake-map-key' : '',
            'site_android_app_link' => $envService->getValue('DEMO') ? 'http://android.com' : '',
            'site_ios_app_link' => $envService->getValue('DEMO') ? 'http://ios.com' : '',
            'site_copyright' => $envService->getValue(
                'DEMO'
            ) ? '© Le Cayenne 2026, Tous Droits Réservés' : '',
            'site_language_switch' => Activity::ENABLE,
            'site_app_debug' => Activity::DISABLE,
            'site_auto_update' => Activity::DISABLE,
            // [INCIDENT PRODUCTION 2026-09-28] Cette ligne a tué le paiement en ligne.
            //
            // Elle posait DISABLE sur toute installation NON-démo. Tant que rien ne lisait
            // ce réglage, c'était sans effet. Le 2026-09-26, le commit 633349c1f a ajouté
            // un garde légitime dans MolliePaymentController — « seule une désactivation
            // EXPLICITE ferme désormais le chemin ». Sauf qu'aucune désactivation explicite
            // n'avait eu lieu : le seeder fermait la capacité tout seul. Résultat mesuré en
            // production : site_online_payment_gateway = 10, et carte + Apple Pay + Google
            // Pay refusés en 503 AVANT tout appel à Mollie — donc aucune erreur Mollie dans
            // les journaux pour mettre sur la piste. Dernier paiement réussi : 22/09.
            //
            // On aligne désormais le seeder sur le défaut du garde lui-même
            // (Settings::get(..., Activity::ENABLE)) : une capacité offerte par l'interface
            // ne doit pas se fermer sans décision. Le propriétaire garde la main — une
            // désactivation explicite reste respectée (test de non-régression dédié).
            //
            // Cela ne peut PAS faire encaisser à tort : le contrôleur refuse toujours en 503
            // « Mollie non configuré. » tant que la clé d'API n'est pas posée. Les deux
            // gardes sont indépendants.
            'site_online_payment_gateway' => Activity::ENABLE,
            'site_default_sms_gateway' => 0,
            'site_guest_login' => Activity::ENABLE,
            'site_default_phone_digit_length' => 10,
        ]);

        $envService->addData([
            'APP_DEBUG' => 'false',
            'TIMEZONE' => 'Europe/Paris',
            'CURRENCY' => 'EUR',
            'CURRENCY_SYMBOL' => '€',
            'CURRENCY_POSITION' => '10', // Right usually
            'CURRENCY_DECIMAL_POINT' => '2',
            'DATE_FORMAT' => 'd-m-Y',
            'TIME_FORMAT' => 'h:i A'
        ]);
        Artisan::call('optimize:clear');
    }
}
