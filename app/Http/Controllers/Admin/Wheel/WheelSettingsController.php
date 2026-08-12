<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        return view('admin.wheel.reglages', $this->donneesEcran());
    }

    public function save(Request $request)
    {
        // Une adresse doit être ouvrable DEPUIS UN TÉLÉPHONE. La règle `url` de la plateforme accepte
        // des schémas qui n'ont rien à faire sur la tablette d'un client (`ftp://`, par exemple) : on
        // exige donc explicitement http(s). Vérifié : `javascript:` et `data:` sont déjà refusés.
        $adresseOuvrable = static function (string $champ, $valeur, $refuser): void {
            if (! preg_match('#^https?://#i', (string) $valeur)) {
                $refuser('Il faut une adresse complète qui commence par https:// — copie-la depuis la '
                    . 'barre du navigateur.');
            }
        };

        $regles = [
            // `nullable` + `url` : un champ vidé DOIT pouvoir l'être (on retire un compte), mais une
            // saisie non vide doit être une vraie adresse — un lien cassé sur la tablette envoie le
            // client nulle part, et c'est pire que pas de lien du tout.
            'review_url' => ['nullable', 'url', 'max:500', $adresseOuvrable],
            'instagram_url' => ['nullable', 'url', 'max:500', $adresseOuvrable],
            'snapchat_url' => ['nullable', 'url', 'max:500', $adresseOuvrable],
            'facebook_url' => ['nullable', 'url', 'max:500', $adresseOuvrable],
            'review_dwell' => ['nullable', 'integer', 'min:0', 'max:180'],
            'follow_dwell' => ['nullable', 'integer', 'min:0', 'max:180'],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:200'],
        ];

        /*
         * ── LES MESSAGES SONT ÉCRITS ICI, EN FRANÇAIS ────────────────────────────────────────
         * Les messages livrés avec la plateforme sont en anglais et citent le nom technique du champ
         * (« The review_dwell must not be greater than 180 »). Sur l'écran qui débloque le jeu, ça ne
         * dit rien à personne. Chaque message nomme le champ comme l'écran le nomme, et dit QUOI
         * FAIRE.
         */
        $messages = [
            'review_url.url' => 'Le lien d\'avis n\'est pas une adresse valide. Colle-la depuis la barre du navigateur.',
            'instagram_url.url' => 'Le lien Instagram n\'est pas une adresse valide. Colle-la depuis la barre du navigateur.',
            'snapchat_url.url' => 'Le lien Snapchat n\'est pas une adresse valide. Colle-la depuis la barre du navigateur.',
            'facebook_url.url' => 'Le lien Facebook n\'est pas une adresse valide. Colle-la depuis la barre du navigateur.',
            'review_url.max' => 'Le lien d\'avis est trop long (500 caractères maximum).',
            'instagram_url.max' => 'Le lien Instagram est trop long (500 caractères maximum).',
            'snapchat_url.max' => 'Le lien Snapchat est trop long (500 caractères maximum).',
            'facebook_url.max' => 'Le lien Facebook est trop long (500 caractères maximum).',
            'review_dwell.integer' => 'Le temps sur l\'avis se donne en secondes entières.',
            'review_dwell.min' => 'Le temps sur l\'avis va de 0 à 180 secondes.',
            'review_dwell.max' => 'Le temps sur l\'avis va de 0 à 180 secondes.',
            'follow_dwell.integer' => 'Le temps sur l\'abonnement se donne en secondes entières.',
            'follow_dwell.min' => 'Le temps sur l\'abonnement va de 0 à 180 secondes.',
            'follow_dwell.max' => 'Le temps sur l\'abonnement va de 0 à 180 secondes.',
            'min_order.numeric' => 'Le minimum de commande se donne en euros (exemple : 12 ou 12.5).',
            'min_order.min' => 'Le minimum de commande va de 0 à 200 €.',
            'min_order.max' => 'Le minimum de commande va de 0 à 200 €.',
        ];

        /*
         * ── LES LOTS : PROBABILITÉ ET NOMBRE DE CADEAUX ──────────────────────────────────────
         * [2026-08-12 · propriétaire : « je veux permettre de faire la probabilité et le nombre de
         * cadeaux que je veux faire gagner aux gens »]
         *
         * Règles ET messages sont composés à partir des lots RÉELLEMENT configurés, jamais d'une liste
         * recopiée ici : une liste en double, c'est un lot ajouté au fichier dont les champs seraient
         * rejetés en silence sur cet écran, sans que personne comprenne pourquoi.
         *
         * `max:1000` sur la probabilité : ce sont des poids RELATIFS, pas des pourcentages. 1000 laisse
         * de quoi rendre un lot largement dominant sans qu'un zéro de trop écrase tous les autres par
         * accident. Quantité à 0 = illimitée, probabilité à 0 = affiché mais jamais gagné.
         *
         * Ce bloc est POSÉ APRÈS `$messages` : le déclarer avant, et la déclaration du tableau
         * effacerait tous ces messages en silence — l'écran retomberait sur l'anglais technique.
         */
        foreach (app(\App\Services\Wheel\WheelService::class)->segments() as $lot) {
            [$cleP, $cleQ] = \App\Services\Wheel\WheelSettingsService::prizeKeys((string) $lot['key']);
            $regles[$cleP] = ['nullable', 'integer', 'min:0', 'max:1000'];
            $regles[$cleQ] = ['nullable', 'integer', 'min:0', 'max:100000'];

            $nom = (string) ($lot['label'] ?? $lot['key']);
            $messages[$cleP.'.integer'] = "La probabilité de « {$nom} » se donne en nombre entier (0 = jamais gagné).";
            $messages[$cleP.'.min'] = "La probabilité de « {$nom} » ne peut pas être négative.";
            $messages[$cleP.'.max'] = "La probabilité de « {$nom} » va de 0 à 1000.";
            $messages[$cleQ.'.integer'] = "Le nombre de « {$nom} » se donne en nombre entier (0 = illimité).";
            $messages[$cleQ.'.min'] = "Le nombre de « {$nom} » ne peut pas être négatif.";
            $messages[$cleQ.'.max'] = "Le nombre de « {$nom} » est trop grand.";
        }

        $validateur = Validator::make($request->all(), $regles, $messages);

        if ($validateur->fails()) {
            /*
             * [P1 2026-08-10 — audit E2E vague C] TOUTE SAISIE REFUSÉE ÉTAIT PERDUE EN SILENCE.
             * Le refus renvoyait un 302 vers un écran identique à l'arrivée : aucun message, valeurs
             * effacées. Le patron corrigeait à l'aveugle sur le seul écran qui débloque son jeu.
             * On revient donc à l'écran AVEC les erreurs et AVEC ce qui a été tapé, et l'adresse est
             * écrite en dur plutôt que déduite du référent — un renvoi qui dépend d'un en-tête finit
             * par tomber sur la page d'accueil un jour de service.
             */
            return redirect()->to(url('/admin/roue-reglages'))
                ->withErrors($validateur)
                ->withInput();
        }

        $data = $validateur->validated();

        // Les cases non cochées ne sont PAS envoyées par le navigateur : sans ce traitement
        // explicite, décocher une case n'aurait jamais aucun effet.
        $data['review_required'] = $request->boolean('review_required') ? '1' : '0';
        $data['follow_required'] = $request->boolean('follow_required') ? '1' : '0';

        $this->reglages->save(array_map(
            static fn ($v) => $v === null ? '' : (string) $v,
            $data
        ));

        return view('admin.wheel.reglages', $this->donneesEcran(['enregistre' => true]));
    }

    /**
     * L'état de l'écran, au même endroit pour l'arrivée et pour l'enregistrement : deux listes de
     * variables à tenir en parallèle, c'est une variable oubliée d'un côté et un écran à moitié faux.
     */
    private function donneesEcran(array $extra = []): array
    {
        return $extra + [
            's' => $this->reglages->all(),
            // `pret` = le moteur peut tourner ; `aMoi` = c'est le patron qui l'a réglé. La bannière a
            // besoin des DEUX : sans le second, elle affichait « le parcours tourne » en vert au-dessus
            // de champs tous vides.
            'pret' => $this->reglages->journeyReady(),
            'aMoi' => $this->reglages->configuredByOperator(),
            'etatLiens' => $this->reglages->linkStatuses(),
            'reviewDerive' => $this->reglages->reviewUrlIsDerived(),
            // [2026-08-12] Les lots tels qu'ils tournent VRAIMENT (fichier + réglages du patron),
            // avec ce qui est déjà parti sur la campagne : régler une quantité sans voir le restant,
            // c'est régler à l'aveugle.
            'lots' => $this->lotsAffichables(),
        ];
    }

    /**
     * Les lots à montrer sur l'écran, avec leur probabilité en pourcentage et le restant.
     *
     * Le POURCENTAGE est calculé ici et pas dans la vue : c'est la seule information que le
     * propriétaire lit vraiment (« combien de chances ? »), et les poids relatifs ne la donnent pas.
     * Un poids de 34 sur un total de 100 ne dit rien tant qu'on n'a pas fait la division.
     */
    private function lotsAffichables(): array
    {
        $segments = app(\App\Services\Wheel\WheelService::class)->segments();
        $total = 0;
        foreach ($segments as $s) {
            $total += max(0, (int) ($s['weight'] ?? 0));
        }

        $branche = (int) (request()->attributes->get('wheel_branch_id') ?: 1);
        $campagne = (string) config('wheel.campaign_key');

        return array_map(static function ($s) use ($total, $branche, $campagne) {
            $poids = max(0, (int) ($s['weight'] ?? 0));
            $quantite = max(0, (int) ($s['quantity'] ?? 0));

            $partis = \App\Models\WheelSpin::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('branch_id', $branche)
                ->where('prize_key', (string) $s['key'])
                ->where('campaign_key', $campagne)
                ->count();

            return [
                'key' => (string) $s['key'],
                'label' => (string) ($s['label'] ?? $s['key']),
                'weight' => $poids,
                'quantity' => $quantite,
                // Pourcentage réel de chances. 0 quand le lot ne peut pas sortir.
                'chance' => $total > 0 ? round($poids * 100 / $total, 1) : 0.0,
                'given' => $partis,
                'left' => $quantite > 0 ? max(0, $quantite - $partis) : null,
                'exhausted' => $quantite > 0 && $partis >= $quantite,
                'showcase' => $poids === 0,
            ];
        }, $segments);
    }

    /*
     * ── PLUS DE GARDE INTERNE ICI, ET C'EST VOULU ────────────────────────────────────────────
     * Cet écran relisait `$request->user()` pour exiger le droit caisse. La porte des quatre écrans
     * de la roue est désormais l'intergiciel `wheel.access` (session web habilitée OU code de la
     * maison, comme le Carnet). Sur le chemin du code il n'y a PAS d'utilisateur : la garde interne
     * refermait donc l'écran juste après que la porte l'avait ouvert — un 403 que personne ne pouvait
     * comprendre, sur l'écran fait pour débloquer le jeu. Une seule porte, à un seul endroit.
     */
}
