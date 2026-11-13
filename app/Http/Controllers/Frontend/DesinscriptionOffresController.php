<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * [test-e2e stores S13-04 · 2026-11-13] Lien de désinscription présent dans CHAQUE e-mail d'offre (obligation légale :
 * LCEN art. L34-5 CPCE / RGPD art. 21). Lien SIGNÉ propre au destinataire : personne ne peut désinscrire l'adresse d'un
 * autre en devinant l'URL. Aucune connexion requise : un clic suffit, comme l'exige la loi.
 */
class DesinscriptionOffresController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));
        if ($email !== '') {
            Subscriber::query()->whereRaw('LOWER(email) = ?', [$email])->delete();
        }

        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Désinscription — Le Cayenne</title></head>'
            . '<body style="font-family:system-ui,-apple-system,sans-serif;background:#FAF7F2;color:#111;margin:0;padding:48px 20px;text-align:center">'
            . '<h1 style="font-size:22px;margin:0 0 12px">Vous êtes désinscrit</h1>'
            . '<p style="margin:0 auto;max-width:420px;line-height:1.5">Vous ne recevrez plus les offres du Cayenne par e-mail. '
            . 'Vous pouvez les réactiver à tout moment dans l\'application, rubrique « Notifications » de votre profil.</p>'
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8')->header('X-Robots-Tag', 'noindex');
    }
}
