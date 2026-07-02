<?php

namespace Tests\Feature\Security;

use App\Http\Requests\CompanyRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V4-DEPLOY 2026-07-02] Durcissement des settings admin :
 *  - company_name (écrit dans .env APP_NAME=…) ne doit pas permettre l'injection de ligne .env ;
 *  - la lecture des settings notification (credentials FCM) doit être gatée `permission:settings`.
 */
class SettingsHardeningTest extends TestCase
{
    /** @test */
    public function company_name_rejette_l_injection_de_ligne_env(): void
    {
        $rules = (new CompanyRequest())->rules();

        foreach (["Foo\nAPP_DEBUG=true", "Bar\rKEY=1", 'Baz"quote'] as $payload) {
            $v = Validator::make(['company_name' => $payload], ['company_name' => $rules['company_name']]);
            $this->assertTrue($v->fails(), "company_name malveillant [$payload] doit être rejeté");
        }

        // Un nom légitime passe.
        $ok = Validator::make(['company_name' => 'Le Cayenne'], ['company_name' => $rules['company_name']]);
        $this->assertFalse($ok->fails(), 'un nom légitime doit passer');
    }

    /** @test */
    public function la_lecture_des_settings_notification_est_gatee_permission_settings(): void
    {
        $route = collect(Route::getRoutes())->first(function ($r) {
            return str_contains($r->uri(), 'setting/notification')
                && in_array('GET', $r->methods(), true);
        });

        $this->assertNotNull($route, 'route GET setting/notification introuvable');
        $mw = implode(',', $route->gatherMiddleware());
        $this->assertStringContainsString(
            'permission:settings',
            $mw,
            'la lecture des settings notification (FCM api key + service-account) DOIT être gatée permission:settings'
        );
    }
}
