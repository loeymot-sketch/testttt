<?php

namespace Tests\Feature\Support;

use App\Libraries\AppLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [FINITION 2026-11-04] L'historique de la caisse affichait « 02:04 AM, 04-11-2026 » : AppLibrary lisait
 * TIME_FORMAT du `.env` (« h:i A ») alors que le réglage du restaurant en base — la source de vérité, que
 * SiteService recopie dans le `.env` — vaut « H:i ». Un écart `.env` ↔ base ne doit plus jamais produire
 * d'affichage 12 h anglais : le réglage en base prime, le `.env` n'est plus qu'un secours.
 */
class AppLibraryFormatReglageDuSiteTest extends TestCase
{
    use RefreshDatabase;

    private ?string $envAvant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envAvant = getenv('TIME_FORMAT') === false ? null : getenv('TIME_FORMAT');
        putenv('TIME_FORMAT=h:i A');
        $_ENV['TIME_FORMAT'] = $_SERVER['TIME_FORMAT'] = 'h:i A';
        AppLibrary::resetFormatCache();
    }

    protected function tearDown(): void
    {
        if ($this->envAvant === null) {
            putenv('TIME_FORMAT');
            unset($_ENV['TIME_FORMAT'], $_SERVER['TIME_FORMAT']);
        } else {
            putenv('TIME_FORMAT=' . $this->envAvant);
            $_ENV['TIME_FORMAT'] = $_SERVER['TIME_FORMAT'] = $this->envAvant;
        }
        AppLibrary::resetFormatCache();
        parent::tearDown();
    }

    public function test_le_reglage_en_base_prime_sur_un_env_divergent(): void
    {
        Settings::group('site')->set(['site_time_format' => 'H:i', 'site_date_format' => 'd-m-Y']);
        AppLibrary::resetFormatCache();

        $this->assertSame('14:04, 04-11-2026', AppLibrary::datetime('2026-11-04 14:04:00'));
        $this->assertSame('14:04', AppLibrary::time('2026-11-04 14:04:00'));
        $this->assertSame('11:00 - 11:30', AppLibrary::deliveryTime('11:00 - 11:30'));
    }

    public function test_un_choix_12_h_du_proprietaire_en_base_reste_respecte(): void
    {
        Settings::group('site')->set(['site_time_format' => 'h:i A']);
        AppLibrary::resetFormatCache();

        $this->assertSame('02:04 PM', AppLibrary::time('2026-11-04 14:04:00'));
    }

    public function test_un_pattern_explicite_n_est_jamais_remplace(): void
    {
        $this->assertSame('04/11', AppLibrary::date('2026-11-04', 'd/m'));
    }
}
