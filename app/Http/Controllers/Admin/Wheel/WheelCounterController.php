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

    public function issue(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->can('pos')) {
            abort(403);
        }

        // La branche vient du COMPTE, jamais de la requête : sinon on valide chez le voisin.
        $branchId = (int) ($user->branch_id ?: 1);

        try {
            $jeton = $this->unlock->issue($branchId, (int) $user->id);
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
            'qr'    => QrCode::format('svg')->size(520)->margin(1)->errorCorrection('M')->generate($url),
            'url'   => $url,
            'ttl'   => (int) config('wheel.unlock_token_ttl_minutes', 15),
        ]);
    }
}
