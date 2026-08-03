<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W5-PERF A2 2026-07-06] Contrat source anti-régression : les assets pos-wizard
 * de master.blade.php doivent être cache-bustés par `filemtime()` (URL stable
 * tant que le fichier ne change pas), plus JAMAIS par `time()` (URL différente à
 * CHAQUE reload → ~341 Ko re-téléchargés systématiquement, mesuré verdicts.md A2).
 *
 * admin-pos-v4.blade.php (FROZEN §7) est hors périmètre : il garde son time()
 * historique tant qu'un LOCK owner ne l'ouvre pas.
 */
class AssetCacheBusterTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_blade_has_no_time_based_cache_buster(): void
    {
        $blade = file_get_contents(resource_path('views/master.blade.php'));

        $this->assertStringNotContainsString(
            '{{ time() }}',
            $blade,
            'master.blade.php ne doit plus utiliser time() comme cache-buster (re-download à chaque reload)'
        );
    }

    public function test_master_blade_busts_pos_wizard_assets_with_filemtime(): void
    {
        $blade = file_get_contents(resource_path('views/master.blade.php'));

        $this->assertMatchesRegularExpression(
            '/pos-wizard\.css.*filemtime\(public_path\(\'css\/pos-wizard\.css\'\)\)/s',
            $blade,
            'pos-wizard.css doit être versionné par filemtime du fichier'
        );
        $this->assertMatchesRegularExpression(
            '/pos-wizard\.js.*filemtime\(public_path\(\'js\/pos-wizard\.js\'\)\)/s',
            $blade,
            'pos-wizard.js doit être versionné par filemtime du fichier'
        );
    }

    public function test_rendered_master_view_embeds_stable_filemtime_version(): void
    {
        // Rendu réel du blade : le ?v= embarqué doit être IDENTIQUE entre deux
        // rendus (c'était l'epoch courant avant W5 → différent à chaque requête).
        $vars = ['analytics' => collect(), 'favicon' => 'favicon.png'];
        $first = view('master', $vars)->render();
        sleep(1);
        $second = view('master', $vars)->render();

        preg_match('/pos-wizard\.js\?v=([^"]+)"/', $first, $a);
        preg_match('/pos-wizard\.js\?v=([^"]+)"/', $second, $b);

        $this->assertNotEmpty($a[1] ?? null, 'le rendu doit contenir pos-wizard.js?v=…');
        $this->assertSame($a[1], $b[1], 'le buster doit être stable entre deux rendus (filemtime, pas time())');

        preg_match('/pos-wizard\.css\?v=([^"]+)"/', $first, $c);
        preg_match('/pos-wizard\.css\?v=([^"]+)"/', $second, $d);
        $this->assertNotEmpty($c[1] ?? null, 'le rendu doit contenir pos-wizard.css?v=…');
        $this->assertSame($c[1], $d[1]);
    }
}
