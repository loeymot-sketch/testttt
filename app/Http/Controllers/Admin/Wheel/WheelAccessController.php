<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWheelAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * L'ACCUEIL DE LA ROUE — la seule porte d'entrée, et la seule page qui liste les autres.
 *
 * ── POURQUOI CETTE PAGE EXISTE ───────────────────────────────────────────────────────────────
 * [P0 2026-08-10] Deux défauts se cumulaient et se cachaient l'un l'autre :
 *
 *   1. les quatre écrans de la roue étaient INACCESSIBLES dans un navigateur (voir
 *      {@see EnsureWheelAccess}) ;
 *   2. AUCUN lien ne menait à eux, où que ce soit dans l'application. Il fallait taper l'URL de
 *      mémoire — `/admin/roue-reglages`, `/admin/roue-lot`… Un écran de service qu'on ne peut pas
 *      trouver n'existe pas, même quand il fonctionne.
 *
 * Cette page répond aux deux : elle ouvre l'accès par un code, puis elle NOMME les quatre écrans en
 * disant à quoi chacun sert et quand on s'en sert.
 */
class WheelAccessController extends Controller
{
    public function show(Request $request)
    {
        $ouvert = $this->ouvert($request);

        return view('admin.wheel.acces', [
            'ouvert' => $ouvert,
            // Le tableau de contrôle n'est calculé que si la porte est ouverte : personne n'a à
            // connaître les chiffres du restaurant depuis l'écran de code.
            'bilan' => $ouvert
                ? app(\App\Services\Wheel\WheelReportService::class)
                    ->tableau((int) ($request->attributes->get('wheel_branch_id')
                        ?: config('wheel.counter_branch_id', 1)))
                : null,
            'pinConfigure' => (string) config('wheel.access.pin', '') !== '',
            'message' => $request->session()->get('wheel_locked'),
            'jeuOuvertAuPublic' => (bool) config('wheel.enabled', false),
            'reglages' => app(\App\Services\Wheel\WheelSettingsService::class),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $attendu = (string) config('wheel.access.pin', '');

        if ($attendu === '') {
            // Fail-closed : sans code configuré, on n'ouvre RIEN. Ces écrans distribuent des lots.
            return back()->with('wheel_locked', 'Aucun code n\'est configuré sur cette machine.');
        }

        $data = $request->validate(['pin' => ['required', 'string', 'max:32']]);

        // Comparaison en temps constant : un `===` fuit, par son temps d'exécution, le nombre de
        // caractères corrects — et un code à quatre chiffres n'a pas de marge à donner.
        if (! hash_equals($attendu, (string) $data['pin'])) {
            Log::channel('daily')->warning('wheel.access_denied', [
                'ip' => $request->ip(),
            ]);

            return back()->with('wheel_locked', 'Code incorrect.');
        }

        // Anti-fixation de session au moment du déverrouillage.
        $request->session()->regenerate();
        $request->session()->put(EnsureWheelAccess::SESSION_KEY, now()->getTimestamp());

        return redirect()->route('admin.wheel.home');
    }

    /**
     * LA PASSE SIGNÉE — comment on entre depuis une caisse SANS retaper le code.
     *
     * [2026-08-13 · propriétaire : « ce n'est pas le bouton, c'est le code PIN »]
     *
     * ── POURQUOI IL FALLAIT UN DÉTOUR ────────────────────────────────────────────────────────
     * La porte accepte en théorie « une session web habilitée `pos` ». En pratique ce chemin ne
     * s'ouvre jamais, et pour une raison structurelle : la garde par défaut est `sanctum`,
     * `LoginController` DÉTRUIT la session web et rend un jeton Bearer que l'application garde en
     * mémoire, et **une navigation de document ne porte jamais d'en-tête `Authorization`**. Un
     * clic sur un lien arrive donc au serveur anonyme, quoi qu'ait fait le caissier avant.
     *
     * ── LA SOLUTION, ET SA LIMITE, DITE ──────────────────────────────────────────────────────
     * L'application, elle, A le jeton : elle peut appeler cette méthode. On lui rend une URL
     * SIGNÉE, valable 2 minutes et à USAGE UNIQUE, qu'elle ouvre dans un onglet. Le navigateur
     * n'a rien à porter : la preuve est dans l'adresse.
     *
     * Ce que ça expose, franchement : une URL interceptée dans les deux minutes ouvre les écrans
     * une fois. Le code partagé, lui, vaut quatre heures, est le même pour toute l'équipe et ne
     * change jamais. Le chemin ajouté est le MOINS exposé des deux — et il n'en retire aucun :
     * une tablette nue, sans session, continue d'entrer par le code.
     *
     * L'usage unique est ce qui empêche une adresse recopiée dans une conversation de resservir.
     */
    public function screenPass(Request $request)
    {
        $user = $request->user();

        if ($user === null || ! $this->habilitePos($user)) {
            return response()->json([
                'status' => false,
                'message' => "Ce compte n'a pas le droit d'ouvrir les écrans de la roue.",
            ], 403);
        }

        // L'écran demandé est choisi dans une liste FERMÉE : accepter un chemin libre ferait de
        // cette route une redirection ouverte signée par nos soins — exactement l'outil rêvé pour
        // faire cliquer quelqu'un ailleurs avec notre signature dessus.
        $ecrans = [
            'accueil' => 'admin.wheel.home',
            'vitrine' => 'admin.wheel.kiosk',
            'validation' => 'admin.wheel.counter',
            'lot' => 'admin.wheel.prize',
            'reglages' => 'admin.wheel.settings',
        ];
        /*
         * ON NORMALISE AVANT DE SIGNER, et c'est un test qui me l'a appris.
         *
         * Ma première version reprenait le texte de l'appelant tel quel dans l'adresse, en se
         * reposant sur le fait que la route filtre `[a-z]{1,20}` et que l'écran inconnu retombe
         * sur l'accueil. Ce n'était pas exploitable — mais on se retrouvait à APPOSER NOTRE
         * SIGNATURE sur une chaîne fournie par l'appelant, et à rendre une adresse morte (404).
         * Une signature ne doit porter que des valeurs que nous avons nous-mêmes choisies.
         */
        $cle = isset($ecrans[$demande = (string) $request->input('ecran', 'accueil')])
            ? $demande
            : 'accueil';

        $jeton = bin2hex(random_bytes(16));
        Cache::put('wheel.pass.'.$jeton, (int) $user->id, now()->addMinutes(2));

        return response()->json([
            'status' => true,
            'url' => URL::temporarySignedRoute('admin.wheel.pass', now()->addMinutes(2), [
                'jeton' => $jeton,
                'ecran' => $cle,
            ]),
        ]);
    }

    /**
     * L'ENTRÉE PAR LA PASSE. La signature est vérifiée par le middleware `signed` ; ici on ne
     * traite plus que l'usage unique et l'ouverture de la session.
     *
     * `Cache::pull` LIT ET SUPPRIME dans le même geste : deux onglets ouverts sur la même adresse
     * ne peuvent pas entrer tous les deux. Un `get` puis un `forget` laisserait la fenêtre entre
     * les deux, qui est précisément ce qu'on referme.
     */
    public function enterWithPass(Request $request, string $jeton, string $ecran): RedirectResponse
    {
        $auteur = Cache::pull('wheel.pass.'.$jeton);

        if ($auteur === null) {
            return redirect()->route('admin.wheel.home')
                ->with('wheel_locked', 'Ce lien d\'accès a déjà servi ou a expiré. Entre le code.');
        }

        $request->session()->regenerate();
        $request->session()->put(EnsureWheelAccess::SESSION_KEY, now()->getTimestamp());

        Log::channel('daily')->info('wheel.pass_used', ['user_id' => $auteur, 'ecran' => $ecran]);

        $ecrans = [
            'accueil' => 'admin.wheel.home',
            'vitrine' => 'admin.wheel.kiosk',
            'validation' => 'admin.wheel.counter',
            'lot' => 'admin.wheel.prize',
            'reglages' => 'admin.wheel.settings',
        ];

        return redirect()->route($ecrans[$ecran] ?? $ecrans['accueil']);
    }

    /**
     * LA PERMISSION `pos`, CHERCHÉE SUR LES DEUX GARDES.
     *
     * Les permissions de ce projet sont enregistrées sous `sanctum`. Un `$user->can('pos')` sur un
     * utilisateur résolu par la garde `web` ne les trouve pas — c'est le piège déjà payé le 10/08,
     * et il vit encore quelques lignes plus bas dans `ouvert()`. Une garde qui a l'air de
     * fonctionner et qui ne fait rien est pire qu'une garde absente : personne ne la relit.
     */
    private function habilitePos($user): bool
    {
        foreach (['sanctum', 'web'] as $garde) {
            try {
                if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos', $garde)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Permission inconnue pour cette garde : on essaie la suivante.
            }
        }

        return false;
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureWheelAccess::SESSION_KEY);

        return redirect()->route('admin.wheel.home')
            ->with('wheel_locked', 'Écrans refermés.');
    }

    private function ouvert(Request $request): bool
    {
        $user = Auth::guard('web')->user();
        if ($user && $user->can('pos')) {
            return true;
        }

        if ((string) config('wheel.access.pin', '') === '') {
            return false;
        }

        $depuis = (int) $request->session()->get(EnsureWheelAccess::SESSION_KEY, 0);
        $duree = ((int) config('wheel.access.session_minutes', 240)) * 60;

        return $depuis > 0 && (now()->getTimestamp() - $depuis) <= $duree;
    }
}
