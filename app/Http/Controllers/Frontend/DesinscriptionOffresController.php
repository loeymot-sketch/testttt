<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * [test-e2e stores S13-04 · 2026-11-13] Lien de désinscription présent dans CHAQUE e-mail d'offre (LCEN / CPCE L34-5,
 * RGPD art. 21). Lien SIGNÉ propre au destinataire : personne ne désinscrit l'adresse d'un autre en devinant l'URL.
 * [S14-04] GET = page de confirmation avec un bouton (les antivirus des messageries ouvrent les liens : un GET qui agit
 * désinscrirait des clients à leur insu) ; POST = désinscription (bouton, ou « un clic » RFC 8058 de la messagerie).
 */
class DesinscriptionOffresController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // [P-026] Signature vérifiée ICI (et non par le middleware `signed`) : un lien abîmé ou ancien affiche une page en
        // français, au lieu de « 403 | INVALID SIGNATURE. ».
        if (! $request->hasValidSignature()) {
            return $this->page('Lien de désinscription invalide',
                'Ce lien est incomplet ou n\'est plus valable. Vous pouvez arrêter les offres à tout moment dans l\'application, rubrique « Notifications » de votre profil.')
                ->setStatusCode(403);
        }
        $email = mb_strtolower(trim((string) $request->query('email', '')));

        if ($request->isMethod('post')) {
            if ($email !== '') {
                Subscriber::query()->whereRaw('LOWER(email) = ?', [$email])->delete();
            }

            return $this->page('Vous êtes désinscrit',
                'Vous ne recevrez plus les offres du Cayenne par e-mail. Vous pouvez les réactiver à tout moment dans l\'application, rubrique « Notifications » de votre profil.');
        }

        $action = e($request->fullUrl());
        $form = '<form method="post" action="' . $action . '" style="margin-top:20px">'
            . '<button type="submit" style="font:700 16px system-ui,sans-serif;background:#C73E18;color:#fff;border:0;border-radius:12px;padding:14px 24px;min-height:48px;cursor:pointer">Me désinscrire</button>'
            . '</form>';

        return $this->page('Se désinscrire des offres', 'Vous ne recevrez plus les offres et nouveautés du Cayenne par e-mail.', $form);
    }

    private function page(string $titre, string $texte, string $suite = ''): Response
    {
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($titre) . ' — Le Cayenne</title></head>'
            . '<body style="font-family:system-ui,-apple-system,sans-serif;background:#FAF7F2;color:#111;margin:0;padding:48px 20px;text-align:center">'
            . '<h1 style="font-size:22px;margin:0 0 12px">' . e($titre) . '</h1>'
            . '<p style="margin:0 auto;max-width:420px;line-height:1.5">' . e($texte) . '</p>' . $suite
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8')->header('X-Robots-Tag', 'noindex');
    }
}
