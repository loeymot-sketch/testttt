<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [PROFIL APP 2026-11-12] Consentement « offres et nouveautés par e-mail » du CLIENT CONNECTÉ, lu et modifié depuis son
 * profil (application et site).
 *
 * Il a un effet RÉEL : la liste `subscribers` est celle à laquelle l'écran d'administration « Abonnés › Envoyer un
 * e-mail » écrit (SubscriberService::sendEmail, Mail::bcc). Activer = l'adresse e-mail du compte y est inscrite ;
 * désactiver = elle en est retirée, tout de suite. Rien n'est coché d'office (consentement explicite, RGPD / CNIL), et
 * un compte sans e-mail ne peut pas s'abonner (l'écran le dit au lieu d'un interrupteur sans effet).
 */
class NewsletterConsentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $email = $this->email($request);

        return response()->json(['status' => true, 'data' => [
            'has_email'  => $email !== null,
            'subscribed' => $email !== null && $this->abonnes($email)->exists(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['subscribed' => ['required', 'boolean']], [
            'subscribed.required' => 'Choix manquant.',
            'subscribed.boolean'  => 'Choix illisible.',
        ]);
        $email = $this->email($request);
        if ($email === null) {
            return response()->json([
                'status'  => false,
                'code'    => 'EMAIL_REQUIRED',
                'message' => 'Votre compte n\'a pas d\'adresse e-mail : nous ne pouvons pas vous écrire.',
            ], 422);
        }

        if ($request->boolean('subscribed')) {
            // [test-e2e stores P-021 · 2026-11-13] Trois appuis simultanés : le 2e et le 3e heurtaient l'unicité de l'e-mail
            // (422 « erreur de base de données »). Déjà abonné par un appui concurrent = succès.
            if (! $this->abonnes($email)->exists()) {
                try {
                    Subscriber::create(['email' => $email]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if (! $this->abonnes($email)->exists()) {
                        throw $e;
                    }
                }
            }
        } else {
            $this->abonnes($email)->delete();
        }

        return response()->json(['status' => true, 'data' => [
            'has_email'  => true,
            'subscribed' => $this->abonnes($email)->exists(),
        ]]);
    }

    private function email(Request $request): ?string
    {
        $email = mb_strtolower(trim((string) ($request->user()?->email ?? '')));

        return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }

    private function abonnes(string $email)
    {
        // Comparaison insensible à la casse : une inscription faite autrefois en « Jean@… » est bien la même personne.
        return Subscriber::query()->whereRaw('LOWER(email) = ?', [$email]);
    }
}
