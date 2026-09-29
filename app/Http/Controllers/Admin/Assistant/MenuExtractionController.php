<?php

namespace App\Http\Controllers\Admin\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\ApplicationDeCarteRequest;
use App\Http\Requests\Assistant\LectureDeCarteRequest;
use App\Services\Menu\MenuDraftApplier;
use App\Services\Menu\Vision\MenuExtractionContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * [ONB-04 2026-08-28] Les deux gestes de la lecture de carte.
 *
 *   1. LIRE    — la photo entre, une PROPOSITION sort. Rien n'est écrit en base.
 *   2. APPLIQUER — les lignes que le commerçant a relues et corrigées entrent,
 *                  le catalogue est créé par les services existants.
 *
 * AUCUN ÉTAT SERVEUR ENTRE LES DEUX. La proposition part à l'écran, revient
 * corrigée, et l'application la revalide intégralement — elle ne fait aucune
 * confiance à ce qu'elle a proposé cinq minutes plus tôt. C'est ce qui rend le
 * dispositif sûr : même un client compromis ne peut créer que ce que
 * {@see \App\Http\Requests\ItemRequest} accepte, taxe obligatoire comprise.
 *
 * ⏳ CE QUI MANQUE ENCORE, ET POURQUOI. Le cahier des charges ONB-04 prévoit en
 * plus des BROUILLONS VERSIONNÉS (`menu_drafts`) et un JOURNAL D'ACTIONS
 * (`assistant_actions`). Les deux exigent une migration de schéma, donc le gate
 * propriétaire **G-DATA**, qui est EN ATTENTE (`plans/GOAL_INDEX_…:163`). Je ne
 * les invente pas dans un cache : un brouillon « versionné » qui s'évapore au
 * redémarrage serait un mensonge plus coûteux que leur absence. La boucle
 * proposer → corriger → appliquer fonctionne sans eux ; la traçabilité longue
 * attend une signature.
 *
 * ⏳ Et aucun appel réel n'est possible : `assistant.enabled` vaut faux par défaut
 * et le plafond de dépense vaut 0 tant que **G-IA** n'est pas tranché. Tout ce
 * chemin fonctionne de bout en bout sur le bouchon déterministe.
 */
class MenuExtractionController extends Controller
{
    public function __construct(
        private MenuExtractionContract $lecteur,
        private MenuDraftApplier $applicateur,
    ) {
    }

    /**
     * Lit la carte photographiée et rend une proposition. N'écrit RIEN.
     */
    public function lire(LectureDeCarteRequest $request): JsonResponse
    {
        // La photo va sur un disque NON servi par le web, le temps de la lecture,
        // puis elle est effacée. Une carte de restaurant n'a pas à rester sur le
        // serveur une fois lue, et surtout pas à une URL devinable.
        $chemin = $request->file('photo')->store('assistant/cartes', 'local');

        try {
            $proposition = $this->lecteur->lireCarte(Storage::disk('local')->path($chemin));
        } catch (\Throwable $e) {
            Log::info('[ONB-04] lecture de carte impossible : ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => "La carte n'a pas pu être lue. Réessayez avec une photo plus nette, "
                    . "ou saisissez vos produits à la main.",
            ], 422);
        } finally {
            Storage::disk('local')->delete($chemin);
        }

        $seuil = (float) config('assistant.menu_extraction.seuil_confiance', 0.75);

        return response()->json([
            'status'      => true,
            'proposition' => $proposition,
            // Le seuil part avec la réponse pour que l'écran marque « à vérifier »
            // les mêmes lignes que le serveur — et non selon sa propre idée.
            'seuil_confiance' => $seuil,
            // Dire D'OÙ vient ce qui est affiché. Un commerçant doit pouvoir
            // distinguer une démonstration d'une vraie lecture de sa carte.
            'source'      => $proposition['source'] ?? 'inconnue',
        ]);
    }

    /**
     * Applique les lignes VALIDÉES par le commerçant.
     */
    public function appliquer(ApplicationDeCarteRequest $request): JsonResponse
    {
        $valide = $request->validated();

        $rapport = $this->applicateur->appliquer(
            $valide['articles'],
            ['tax_id' => (int) $valide['tax_id'], 'item_type' => (int) $valide['item_type']]
        );

        return response()->json([
            'status'  => true,
            'rapport' => $rapport,
            'resume'  => $this->resume($rapport),
        ]);
    }

    /**
     * Une phrase que le commerçant peut lire, plutôt que cinq listes à recouper.
     *
     * @param  array<string, mixed>  $rapport
     */
    private function resume(array $rapport): string
    {
        $morceaux = [];

        $crees = count($rapport['articles_crees']);
        if ($crees > 0) {
            $morceaux[] = $crees . ' produit' . ($crees > 1 ? 's ajoutés' : ' ajouté');
        }

        $dejaLa = count($rapport['articles_deja_la']);
        if ($dejaLa > 0) {
            $morceaux[] = $dejaLa . ' déjà dans votre carte';
        }

        /*
         * [ONB 2026-08-28] Les doublons internes à la lecture, dits à part.
         *
         * Ils étaient comptés avec les « déjà dans votre carte » — le mot le plus
         * rassurant possible pour désigner un produit que le commerçant vient de
         * perdre.
         */
        $doublons = count($rapport['doublons_dans_la_lecture'] ?? []);
        if ($doublons > 0) {
            $morceaux[] = $doublons . ' à renommer (nom en double dans la lecture)';
        }

        $refus = count($rapport['refus']);
        if ($refus > 0) {
            $morceaux[] = $refus . ' à corriger';
        }

        return $morceaux === []
            ? "Rien à ajouter."
            : implode(', ', $morceaux) . '.';
    }
}
