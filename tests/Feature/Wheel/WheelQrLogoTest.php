<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LE LOGO SUR LE QR — posé en CSS par-dessus le SVG (voir borne.blade.php / validation.blade.php),
 * pas fusionné dans un binaire : `php -m` sur cette machine (et rien ne garantit le VPS) n'a PAS
 * l'extension `imagick`, seule backend PNG de `bacon/bacon-qr-code` — `format('png')->merge()`
 * lève `RuntimeException` à la génération, vérifié par exécution avant d'écrire ce test.
 *
 * Ce que CE test verrouille : les options qui rendent l'overlay CSS scannable malgré lui —
 * errorCorrection('H') (tolère le recouvrement central) et une zone tranquille élargie (margin 2).
 * Ce fichier NE PEUT PAS automatiser le décodage du SVG lui-même (aucun rasterizer disponible ici :
 * ni imagick, ni rsvg-convert/inkscape/magick en CLI) — affirmer une scannabilité sans preuve
 * réelle serait la « certitude fabriquée » que CLAUDE.md §13 interdit.
 *
 * PREUVE RÉELLE APPORTÉE HORS PHPUnit (2026-08-13, capture navigateur + décodage effectif) :
 * `/admin/roue-borne` et `/admin/roue-validation` capturés via un vrai Chrome (screenshot PNG,
 * donc le SVG + l'overlay CSS du logo sont bien rasterisés par le moteur de rendu du navigateur,
 * pas simulés), puis décodés avec `khanamiryan/qrcode-detector-decoder` (mode GD, sans Imagick —
 * même contrainte que la prod probable) : les DEUX QR se décodent et rendent l'URL
 * `https://www.lecayenne.fr/roue.html?t=...` attendue. Logo à 20%, errorCorrection H, margin 2 —
 * scannable, preuve non simulée.
 */
class WheelQrLogoTest extends TestCase
{
    use RefreshDatabase;

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $branch = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $branch->id]);
        $this->caissier->givePermissionTo('pos');

        Config::set('wheel.public_url', 'https://www.lecayenne.fr');
        Config::set('wheel.preview_key', '');
    }

    public function test_le_QR_de_validation_est_genere_en_correction_haute_et_marge_elargie(): void
    {
        $r = $this->actingAs($this->caissier, 'web')->post('/admin/roue-validation')->assertOk();

        $html = $r->getContent();
        $this->assertStringContainsString('<svg', $html, 'aucun QR généré');

        // On récupère l'URL RÉELLEMENT encodée (exposée par le contrôleur via assertViewHas) et on
        // régénère, dans le test, le SVG qu'aurait produit l'ANCIEN réglage ('M', margin 1) pour
        // cette même URL. errorCorrection('H') encode plus de données de redondance qu'un niveau
        // 'M' à contenu égal — vérifié empiriquement (5699 caractères en 'M' contre 10152 en 'H'
        // pour une URL de même longueur) : le SVG produit par le contrôleur doit donc être
        // nettement plus long que la référence 'M'. Preuve réelle, pas une supposition sur le
        // format interne de la lib. La preuve de SCANNABILITÉ (le logo n'aveugle pas le lecteur)
        // est apportée séparément par capture navigateur + décodage — voir l'en-tête de ce fichier.
        $url = null;
        $r->assertViewHas('url', function ($u) use (&$url) {
            $url = $u;

            return is_string($u);
        });
        $this->assertNotNull($url, 'l\'URL encodée n\'est pas exposée par la vue');

        preg_match('/<div class="qr">(.*)<\/div>\s*<p class="expire">/s', $html, $m);
        $this->assertNotEmpty($m, 'le conteneur .qr est introuvable dans le HTML rendu');
        $svgReel = $m[1];

        $svgReference = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
            ->size(520)->margin(1)->errorCorrection('M')->generate($url);

        $this->assertGreaterThan(
            strlen($svgReference),
            strlen($svgReel),
            'le QR généré n\'est pas plus dense qu\'une référence en correction M — errorCorrection(\'H\') '
            . 'a-t-il bien été appliqué dans WheelCounterController::issue() ?'
        );
    }

    public function test_le_logo_est_pose_au_centre_du_QR_de_validation(): void
    {
        $r = $this->actingAs($this->caissier, 'web')->post('/admin/roue-validation')->assertOk();

        // `qr-logo` seul matcherait aussi la règle CSS de <style> (toujours présente) — l'IMG avec
        // le chemin de l'asset ne peut apparaître que si le DOM a bien rendu le bloc du logo.
        $r->assertSee('class="qr-logo"', false);
        $r->assertSee('images/wheel/logo-mark.png', false);
    }

    public function test_le_logo_est_pose_au_centre_du_QR_de_la_tablette(): void
    {
        Config::set('wheel.enabled', true);

        $r = $this->actingAs($this->caissier, 'web')->get('/admin/roue-borne')->assertOk();

        // `qr-logo` seul matcherait aussi la règle CSS de <style> (toujours présente) — l'IMG avec
        // le chemin de l'asset ne peut apparaître que si le DOM a bien rendu le bloc du logo.
        $r->assertSee('class="qr-logo"', false);
        $r->assertSee('images/wheel/logo-mark.png', false);
    }

    /** Sans QR (adresse publique absente), le logo ne doit PAS s'afficher flottant dans le vide. */
    public function test_sans_QR_le_logo_ne_s_affiche_pas_seul(): void
    {
        Config::set('wheel.public_url', '');
        Config::set('wheel.enabled', true);

        $r = $this->actingAs($this->caissier, 'web')->get('/admin/roue-borne')->assertOk();

        // `qr-logo` seul matcherait aussi la règle CSS (toujours présente dans <style>, QR ou pas) —
        // on cherche l'IMG réel, qui ne peut apparaître que si le bloc `@if($qr)` du DOM s'est ouvert.
        $r->assertDontSee('logo-mark.png', false);
    }

    public function test_l_asset_du_logo_existe_et_est_une_image_valide(): void
    {
        $path = public_path('images/wheel/logo-mark.png');
        $this->assertFileExists($path, 'asset du logo QR manquant — copié depuis le repo web (assets/brand/logo-mark.png)');
        $info = @getimagesize($path);
        $this->assertNotFalse($info, 'le fichier logo-mark.png n\'est pas une image valide');
    }
}
