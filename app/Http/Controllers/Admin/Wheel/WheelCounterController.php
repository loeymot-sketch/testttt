<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelException;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * L'écran de validation du comptoir — le maillon humain de la vérification.
 *
 * Rendu en HTML serveur, sans JavaScript : pendant un service, un écran qui charge est un écran
 * qu'on n'utilise pas. Et le QR est un SVG généré côté serveur — aucune ressource distante, donc
 * rien qui puisse manquer au mauvais moment.
 *
 * Le jeton n'apparaît QUE dans le QR et dans l'adresse affichée, jamais dans un journal : c'est
 * une signature, et une signature journalisée est une signature réutilisable.
 */
class WheelCounterController extends Controller
{
    public function __construct(private readonly WheelUnlockService $unlock) {}

    public function show(Request $request)
    {
        return view('admin.wheel.validation', ['token' => null, 'qr' => null, 'url' => null, 'ttl' => null]);
    }

    /**
     * L'ÉCRAN D'ATTENTE DE LA TABLETTE, posé au comptoir face aux clients.
     *
     * Personne ne le touche : ni l'équipe pendant un service, ni le client qui se contente de
     * scanner. Le jeton se renouvelle donc TOUT SEUL — un QR figé serait consommé par le premier
     * scan (usage unique) et les suivants tomberaient sur « validation introuvable ».
     *
     * Effet de bord voulu : une photo du QR partagée à l'extérieur ne vaut plus rien quelques
     * minutes plus tard. Il faut être DEVANT le comptoir.
     */
    public function kiosk(Request $request)
    {
        // [P0 2026-08-10] L'autorisation est portée par la PORTE (`wheel.access`), plus par une
        // relecture de `$request->user()` : sur le chemin du code de la maison il n'y a aucun
        // utilisateur, et cet `abort(403)` refermait l'écran juste après que la porte l'ait ouvert.
        // La branche et l'auteur sont résolus une seule fois, par le middleware.
        $branchId = (int) $request->attributes->get('wheel_branch_id', 1);
        $actorId = $request->attributes->get('wheel_actor_id');
        $ttl = max(1, (int) config('wheel.unlock_token_ttl_minutes', 15));

        $commun = [
            'segments' => app(\App\Services\Wheel\WheelService::class)->publicSegments($branchId),
            // [2026-08-12] Les lots sur lesquels l'animation de la vitrine a le droit de S'ARRÊTER.
            // Le Terminator est dessiné sur la roue (probabilité nulle voulue par le propriétaire)
            // mais l'animation ne doit jamais le désigner gagnant : avec un arrêt au hasard uniforme,
            // la tablette s'arrêtait dessus 1 fois sur 7, toutes les dix secondes, en salle.
            'spinnable' => app(\App\Services\Wheel\WheelService::class)->spinnableKeys($branchId),
            // [ONB-05 2026-08-28] Même porte que l'application : sinon l'écran de
            // contrôle annonce un minimum que la roue n'applique pas.
            'minOrder' => app(\App\Services\Wheel\WheelSettingsService::class)->minOrder(),
            // [2026-08-13] Ce qui REMPLACE l'acte qui réimprimait la liste des lots déjà portée par
            // la roue. Une information neuve — « ça vient de donner » — ou rien du tout : si
            // personne n'a joué depuis deux jours, l'acte est sauté plutôt que d'afficher un cadre
            // vide qui dirait au client que le jeu ne prend pas.
            // QUATRE, pas six : au-delà, la ligne des actes devient plus haute que la roue, et
            // c'est la roue qui rétrécit — or c'est elle le spectacle. Quatre suffisent à dire
            // « ça tombe souvent ».
            'gagnants' => app(\App\Services\Wheel\WheelReportService::class)
                ->derniersGagnants($branchId, 4),
            // On recharge à la MOITIÉ de la durée de vie : le QR affiché est ainsi toujours valable
            // au moins autant de temps qu'il en reste à l'écran. Recharger à l'expiration exacte
            // laisserait une fenêtre où le client scanne un jeton déjà mort.
            'refreshMs' => (int) max(30, $ttl * 30) * 1000,
        ];

        try {
            $jeton = $this->unlock->issue($branchId, $actorId !== null ? (int) $actorId : null);
        } catch (WheelException $e) {
            return view('admin.wheel.borne', $commun + ['qr' => null, 'erreur' => $e->getMessage()]);
        }

        $base = (string) config('wheel.public_url', '');
        if ($base === '') {
            return view('admin.wheel.borne', $commun + [
                'qr' => null,
                'erreur' => "L'adresse publique de la roue n'est pas configurée (WHEEL_PUBLIC_URL).",
            ]);
        }

        $url = $base . '/roue.html?t=' . urlencode($jeton['token']);
        $apercu = (string) config('wheel.preview_key', '');
        if (! (bool) config('wheel.enabled', false) && $apercu !== '') {
            $url .= '&preview=' . urlencode($apercu);
        }

        return view('admin.wheel.borne', $commun + [
            // errorCorrection H + margin 2 : le logo posé par-dessus en CSS (borne.blade.php)
            // recouvre visuellement le centre du QR sans toucher au SVG généré ici — le niveau H
            // (~30% de tolérance) et une zone tranquille plus large compensent cette occupation
            // visuelle pour que le QR reste décodable (cf. WheelQrScannabilityTest).
            'qr' => QrCode::format('svg')->size(560)->margin(2)->errorCorrection('H')->generate($url),
        ]);
    }

    public function issue(Request $request)
    {
        // La branche ne vient JAMAIS du corps de la requête — sinon on valide chez le voisin. Elle
        // vient du compte quand il y en a un, sinon de la configuration de la machine ; dans les
        // deux cas c'est la porte qui l'a résolue (voir EnsureWheelAccess).
        $branchId = (int) $request->attributes->get('wheel_branch_id', 1);
        $actorId = $request->attributes->get('wheel_actor_id');

        try {
            // L'auteur reste NUL quand la porte a été ouverte par le code : on n'attribue pas un
            // geste à quelqu'un qui ne s'est pas identifié.
            $jeton = $this->unlock->issue($branchId, $actorId !== null ? (int) $actorId : null);
        } catch (WheelException $e) {
            return view('admin.wheel.validation', [
                'token' => null, 'qr' => null, 'url' => null, 'ttl' => null,
                'erreur' => $e->getMessage(),
            ]);
        }

        $base = (string) config('wheel.public_url', '');
        if ($base === '') {
            return view('admin.wheel.validation', [
                'token' => null, 'qr' => null, 'url' => null, 'ttl' => null,
                // Mieux vaut le dire que d'afficher un QR qui mène nulle part.
                'erreur' => "L'adresse publique de la roue n'est pas configurée (WHEEL_PUBLIC_URL).",
            ]);
        }

        $url = $base . '/roue.html?t=' . urlencode($jeton['token']);
        // Tant que la roue est fermée au public, on ajoute la clé de prévisualisation : sans elle
        // le client scannerait un QR qui répond « pas encore ouvert », ce qui gâche la démonstration.
        $apercu = (string) config('wheel.preview_key', '');
        if (! (bool) config('wheel.enabled', false) && $apercu !== '') {
            $url .= '&preview=' . urlencode($apercu);
        }

        return view('admin.wheel.validation', [
            'token' => $jeton['token'],
            // Même raisonnement que kiosk() : errorCorrection H + margin 2 pour le logo overlay.
            'qr'    => QrCode::format('svg')->size(520)->margin(2)->errorCorrection('H')->generate($url),
            'url'   => $url,
            'ttl'   => (int) config('wheel.unlock_token_ttl_minutes', 15),
        ]);
    }
}
