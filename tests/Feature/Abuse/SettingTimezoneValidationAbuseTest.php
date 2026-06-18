<?php

namespace Tests\Feature\Abuse;

use App\Http\Requests\SiteRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * ABUSE #3 — site_default_timezone accepts any string (P2).
 *
 * SiteRequest::rules() typed `site_default_timezone` as
 * ['required','string','max:190'] WITHOUT Laravel's `timezone` rule. An
 * invalid TZ ("Invalid/Timezone") therefore persisted and later threw deep
 * inside Carbon / DateTimeZone / the scheduler when the app tried to use it.
 *
 * This proves the rule now rejects an invalid TZ identifier and still accepts
 * a real one. Pure validation-layer test — no DB / env side-effects (the
 * SiteService write path calls Artisan::call('optimize:clear') + EnvEditor,
 * which is out of scope for proving the rule gap).
 *
 * @see app/Http/Requests/SiteRequest.php
 */
class SettingTimezoneValidationAbuseTest extends TestCase
{
    private function rules(): array
    {
        return (new SiteRequest())->rules();
    }

    /** A fully-populated, otherwise-valid Site payload. */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'site_date_format'                => 'd-m-Y',
            'site_time_format'                => 'H:i',
            'site_default_timezone'           => 'Europe/Paris',
            'site_default_branch'             => 1,
            'site_default_currency'           => 1,
            'site_currency_position'          => 10,
            'site_digit_after_decimal_point'  => 2,
            'site_email_verification'         => 0,
            'site_phone_verification'         => 0,
            'site_default_language'           => 1,
            'site_language_switch'            => 0,
            'site_app_debug'                  => 0,
            'site_auto_update'                => 0,
            'site_google_map_key'             => 'AIza-test-key',
            'site_android_app_link'           => null,
            'site_ios_app_link'               => null,
            'site_copyright'                  => '© 2026 FoodKing',
            'site_online_payment_gateway'     => 0,
            'site_default_sms_gateway'        => null,
            'site_guest_login'                => 1,
            'site_default_phone_digit_length' => 10,
        ], $overrides);
    }

    /** @test */
    public function it_rejects_an_invalid_timezone_identifier(): void
    {
        $validator = Validator::make(
            $this->basePayload(['site_default_timezone' => 'Invalid/Timezone']),
            $this->rules()
        );

        $this->assertTrue($validator->fails(), 'An invalid timezone must be rejected.');
        $this->assertArrayHasKey('site_default_timezone', $validator->errors()->toArray());
    }

    /** @test */
    public function it_rejects_a_garbage_string_as_timezone(): void
    {
        $validator = Validator::make(
            $this->basePayload(['site_default_timezone' => 'not-a-tz-at-all']),
            $this->rules()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('site_default_timezone', $validator->errors()->toArray());
    }

    /** @test */
    public function it_accepts_a_valid_iana_timezone(): void
    {
        $validator = Validator::make(
            $this->basePayload(['site_default_timezone' => 'Europe/Paris']),
            $this->rules()
        );

        $this->assertFalse(
            $validator->errors()->has('site_default_timezone'),
            'A valid IANA timezone (Europe/Paris) must pass.'
        );
    }
}
