<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelSettingsService;
use Illuminate\Http\Request;

/**
 * L'ÉCRAN QUI DÉBLOQUE LE JEU.
 *
 * Les trois adresses du parcours sont les comptes du propriétaire : lui seul peut les fournir. Tant
 * qu'elles vivaient dans des variables d'environnement, le jeu restait à attendre qu'on les pose sur
 * le serveur — la fonctionnalité était finie et dormait. Ici il les colle lui-même, et le parcours
 * s'active immédiatement.
 *
 * L'écran DIT SON ÉTAT en haut. Un réglage dont on ne voit pas l'effet est un réglage qu'on ne
 * touche pas.
 */
class WheelSettingsController extends Controller
{
    public function __construct(private readonly WheelSettingsService $reglages) {}

    public function show(Request $request)
    {
        $this->autoriser($request);

        return view('admin.wheel.reglages', [
            's' => $this->reglages->all(),
            'pret' => $this->reglages->journeyReady(),
        ]);
    }

    public function save(Request $request)
    {
        $this->autoriser($request);

        $data = $request->validate([
            // `nullable` + `url` : un champ vidé DOIT pouvoir l'être (on retire un compte), mais une
            // saisie non vide doit être une vraie adresse — un lien cassé sur la tablette envoie le
            // client nulle part, et c'est pire que pas de lien du tout.
            'review_url' => ['nullable', 'url', 'max:500'],
            'instagram_url' => ['nullable', 'url', 'max:500'],
            'snapchat_url' => ['nullable', 'url', 'max:500'],
            'review_dwell' => ['nullable', 'integer', 'min:0', 'max:180'],
            'follow_dwell' => ['nullable', 'integer', 'min:0', 'max:180'],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:200'],
        ]);

        // Les cases non cochées ne sont PAS envoyées par le navigateur : sans ce traitement
        // explicite, décocher une case n'aurait jamais aucun effet.
        $data['review_required'] = $request->boolean('review_required') ? '1' : '0';
        $data['follow_required'] = $request->boolean('follow_required') ? '1' : '0';

        $this->reglages->save(array_map(
            static fn ($v) => $v === null ? '' : (string) $v,
            $data
        ));

        return view('admin.wheel.reglages', [
            's' => $this->reglages->all(),
            'pret' => $this->reglages->journeyReady(),
            'enregistre' => true,
        ]);
    }

    /** Réglages d'un jeu qui distribue des lots : réservé aux comptes de la caisse. */
    private function autoriser(Request $request): void
    {
        $u = $request->user();
        if (! $u || ! $u->can('pos')) {
            abort(403);
        }
    }
}
