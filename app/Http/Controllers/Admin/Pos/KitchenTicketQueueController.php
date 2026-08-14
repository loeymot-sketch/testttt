<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Domain\Kds\KitchenReleaseRule;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Kitchen\KitchenTicketAutoPrinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] La file des tickets cuisine que le PC caisse vient
 * CHERCHER lui-même.
 *
 * POURQUOI CETTE FILE EXISTE
 * --------------------------
 * `KitchenTicketAutoPrinter::printOnce()` pousse le ticket du SERVEUR vers l'imprimante en TCP.
 * En production ça ne peut pas marcher et ça n'a jamais marché : le serveur applicatif est chez
 * l'hébergeur, l'imprimante est au bout du réseau local du restaurant. Constat du 2026-08-10 :
 * la table `printers` est VIDE en production, `printOnce()` sort donc en `no_printer` — sans
 * erreur, sans trace — et il n'existe pas UNE ligne `[KitchenTicketAutoPrinter]` dans les
 * journaux depuis l'origine. Une commande web payée 31,40 € (#440) est restée invisible et
 * muette : aucun papier, aucun signal en caisse, seulement une carte sur l'écran KDS.
 *
 * La topologie impose l'inversion : le serveur ne peut pas atteindre l'imprimante, donc c'est le
 * PC caisse qui réclame. C'est déjà le modèle éprouvé du ticket promo (PromoFlyerController +
 * PromoFlyerPrintListener) ; on le reprend tel quel plutôt que d'en inventer un second.
 *
 * CE QUI EST DÉLIBÉRÉ ICI
 * -----------------------
 *  - **La garde « une seule fois » n'est PAS recopiée.** Elle vit dans KitchenTicketAutoPrinter
 *    et s'appuie sur un UPDATE conditionnel : la base arbitre. Deux onglets caisse ouverts ne
 *    peuvent pas sortir le même ticket, et si un jour une imprimante serveur est configurée les
 *    deux chemins se disputent la même colonne au lieu de doubler le papier.
 *  - **Fenêtre courte obligatoire.** À la première mise en service, TOUTES les commandes
 *    historiques ont `kitchen_ticket_printed_at` à NULL. Sans borne basse, le premier sondage
 *    viderait des centaines de tickets d'un coup. La fenêtre est donc de quelques minutes : un
 *    ticket plus vieux n'a de toute façon plus d'intérêt en cuisine.
 *  - **Caisse et téléphone sont exclus DU COMPTOIR.** Le caissier imprime déjà (ou pas, à sa
 *    main) leur ticket COMPTOIR à l'encaissement — un poste de ticket cuisine que rien
 *    ne réclame côté serveur ; les inclure ici ferait sortir un DEUXIÈME papier comptoir.
 *  - **[OWNER 2026-08-13 « je veux tout imprime direct »] Caisse et téléphone SONT inclus EN
 *    CUISINE.** Avant ce jour, le poste cuisine (pont 9101) n'avait JAMAIS reçu le ticket d'une
 *    vente caisse — seul le comptoir en avait un, et seulement si le caissier cliquait
 *    « Imprimer ticket cuisine ». Un oubli de clic = zéro papier en cuisine, sans filet. Ce
 *    canal-ci est sans risque de doublon avec le clic manuel : `tryCaisseBridge('kitchen')`
 *    (ReceiptComponent.vue) POSTe au pont COMPTOIR (127.0.0.1:9100, le poste du caissier),
 *    jamais au pont CUISINE (127.0.0.1:9101, un poste physiquement différent) — les deux
 *    canaux visent des imprimantes distinctes, aucun ne peut doubler l'autre.
 *  - **Seules les commandes RELÂCHÉES en cuisine** (mêmes prédicats que le board : statut
 *    visible + paiement encaissé ou différé comptoir). Une commande dont le paiement est encore
 *    en vol ne doit pas produire de papier.
 */
class KitchenTicketQueueController extends Controller
{
    /**
     * Surfaces dont le ticket cuisine doit sortir automatiquement AU COMPTOIR (destination
     * 'counter', pont 9100). 'pos' et 'phone' en sont absents à dessein : leur ticket comptoir
     * sort déjà (ou pas) au clic caissier — les ajouter ici doublerait ce papier-là.
     */
    private const SURFACES_COMPTOIR = ['kiosk', 'web', 'online', 'delivery', 'uber_eats'];

    /**
     * [OWNER 2026-08-13] Surfaces dont le ticket cuisine doit sortir automatiquement EN CUISINE
     * (destination 'kitchen', pont 9101) — 'pos' et 'phone' AJOUTÉS ICI SEULEMENT : ce poste n'a
     * jamais eu de filet pour eux (cf. doc de classe). Additif au clic manuel comptoir, ne le
     * remplace pas — le clic reste disponible pour une réimpression comptoir.
     */
    private const SURFACES_CUISINE = ['kiosk', 'web', 'online', 'delivery', 'uber_eats', 'pos', 'phone'];

    /** Nombre maximum de tickets réclamés par sondage — évite une rafale de papier. */
    private const MAX_PAR_CYCLE = 5;

    /**
     * [TICKET-CUISINE-DEUX-POSTES 2026-08-12 · owner « les deux »] Les deux sorties papier.
     *
     * 'counter' = pont caisse (127.0.0.1:9100 sur le PC caisse)
     * 'kitchen' = pont cuisine (127.0.0.1:9101 sur le PC cuisine)
     *
     * Chaque poste réclame POUR SA destination. Sans cette séparation, le premier arrivé prive
     * l'autre : c'est la raison d'être de la table `kitchen_ticket_claims`.
     */
    private const DESTINATION_COMPTOIR = 'counter';
    private const DESTINATIONS = [self::DESTINATION_COMPTOIR, 'kitchen'];

    /**
     * Réclame les tickets cuisine à sortir sur CE poste. Chaque commande renvoyée est déjà
     * réclamée : l'appelant DOIT accuser réception (`ack`), sinon rien ne la remettra en file.
     */
    public function pending(Request $request, KitchenTicketAutoPrinter $printer): JsonResponse
    {
        // Le pont tourne dans la coquille admin — présent sur le poste caisse, mais l'écran
        // cuisine (rôle Chef) est un poste tout aussi légitime pour sortir un ticket cuisine.
        // Même porte que la lecture des octets du ticket cuisine (PosTicketBytesController).
        abort_unless(
            $request->user()?->can('pos') || $request->user()?->can('kitchen-display-system'),
            403
        );

        $destination = $this->destination($request);
        $branchId = (int) ($request->user()->branch_id ?? 0);

        $fenetreMinutes = max(1, (int) config('kds.bridge_print_window_minutes', 30));

        $query = Order::query()
            ->select(['id', 'order_serial_no', 'queue_number', 'branch_id', 'source_surface', 'created_at'])
            // « Pas encore réclamé POUR CETTE DESTINATION » — et non « pas encore imprimé » tout
            // court. C'est toute la différence entre deux postes qui impriment chacun leur papier
            // et deux postes qui se volent les tickets à tour de rôle.
            // [RÉCLAMATION ORPHELINE 2026-08-12] Une réclamation ne bloque le ticket que si elle
            // est ENCORE VALABLE : soit le papier est réellement sorti, soit le poste est encore
            // en train de le sortir. Une réclamation sans accusé passé le délai est un poste MORT
            // (onglet fermé, PC redémarré, `ack` perdu) et ne doit plus retenir le ticket —
            // sinon il est perdu pour toujours, ce qui en cuisine veut dire un plat oublié.
            ->whereNotExists(function ($sub) use ($destination) {
                $sub->select(DB::raw(1))
                    ->from('kitchen_ticket_claims')
                    ->whereColumn('kitchen_ticket_claims.order_id', 'orders.id')
                    ->where('kitchen_ticket_claims.destination', $destination)
                    ->where(function ($q) {
                        $q->whereNotNull('kitchen_ticket_claims.printed_at')
                            ->orWhere('kitchen_ticket_claims.updated_at', '>=', $this->reclamationValideDepuis());
                    });
            })
            ->whereIn('source_surface', $destination === 'kitchen' ? self::SURFACES_CUISINE : self::SURFACES_COMPTOIR)
            ->whereIn('status', KitchenReleaseRule::visibleStatuses())
            ->where('created_at', '>=', now()->subMinutes($fenetreMinutes))
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('created_at');

        KitchenReleaseRule::applyBoardReleaseFilter($query);

        $candidates = $query->limit(self::MAX_PAR_CYCLE)->get();

        $reclamees = [];
        foreach ($candidates as $order) {
            // La base arbitre : si un autre onglet a gagné la course, on passe.
            if (! $printer->claimForBridge((int) $order->id, $destination)) {
                continue;
            }

            $reclamees[] = [
                'id'              => (int) $order->id,
                'order_serial_no' => $order->order_serial_no,
                'queue_number'    => $order->queue_number,
                'source_surface'  => $order->source_surface,
            ];
        }

        // [RÉIMPRESSION 2026-08-12 · owner] Second chemin, ADDITIF : les demandes explicites.
        //
        // Le bloc ci-dessus est le chemin AUTOMATIQUE, inchangé — il vient de servir 10 tickets
        // réels en production et on n'y touche pas pour ajouter un confort. Celui-ci sert les
        // commandes qu'un humain a explicitement réclamées depuis un écran, et lui seul ignore la
        // fenêtre de 30 minutes et le statut cuisine : on réimprime justement un ticket perdu,
        // bourré ou taché, souvent bien après le coup de feu. Une heuristique de fraîcheur ne
        // doit pas contredire quelqu'un qui a regardé et décidé.
        foreach ($this->reimpressionsDemandees($destination, $branchId) as $orderId) {
            DB::table('kitchen_ticket_claims')
                ->where('order_id', $orderId)
                ->where('destination', $destination)
                // La demande est consommée AVANT d'être servie : si le pont meurt entre les deux,
                // on perd un papier — pas de boucle qui recracherait le même ticket sans fin.
                ->update(['reprint_requested_at' => null, 'printed_at' => null, 'error' => null, 'updated_at' => now()]);

            $order = Order::query()
                ->select(['id', 'order_serial_no', 'queue_number', 'source_surface'])
                ->find($orderId);

            if ($order) {
                $reclamees[] = [
                    'id'              => (int) $order->id,
                    'order_serial_no' => $order->order_serial_no,
                    'queue_number'    => $order->queue_number,
                    'source_surface'  => $order->source_surface,
                    'reimpression'    => true,
                ];
            }
        }

        return response()->json(['orders' => $reclamees, 'destination' => $destination]);
    }

    /**
     * Instant avant lequel une réclamation non confirmée est réputée ABANDONNÉE.
     *
     * On se base sur `updated_at` et non sur `created_at` : une réimpression touche la ligne
     * existante, et une demande fraîche doit repartir avec un délai plein plutôt que d'hériter
     * de l'âge de la réclamation d'origine.
     */
    private function reclamationValideDepuis(): \Illuminate\Support\Carbon
    {
        return now()->subSeconds(max(10, (int) config('kds.bridge_claim_ttl_seconds', 90)));
    }

    /**
     * Les commandes dont un humain réclame le papier, pour CETTE destination et CETTE branche.
     *
     * @return list<int>
     */
    private function reimpressionsDemandees(string $destination, int $branchId): array
    {
        return DB::table('kitchen_ticket_claims')
            ->join('orders', 'orders.id', '=', 'kitchen_ticket_claims.order_id')
            ->where('kitchen_ticket_claims.destination', $destination)
            ->whereNotNull('kitchen_ticket_claims.reprint_requested_at')
            ->when($branchId > 0, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->orderBy('kitchen_ticket_claims.reprint_requested_at')
            ->limit(self::MAX_PAR_CYCLE)
            ->pluck('kitchen_ticket_claims.order_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Destination demandée par le poste appelant.
     *
     * Défaut 'counter' À DESSEIN : pendant la fenêtre de déploiement, un navigateur peut encore
     * exécuter l'ancien paquet, qui n'envoie aucune destination. Le faire échouer priverait la
     * caisse de ses tickets le temps qu'elle recharge ; on le traite donc comme ce qu'il est —
     * un poste caisse.
     */
    private function destination(Request $request): string
    {
        $valide = $request->validate([
            'destination' => ['nullable', 'string', 'in:'.implode(',', self::DESTINATIONS)],
        ]);

        return $valide['destination'] ?? self::DESTINATION_COMPTOIR;
    }

    /**
     * Accusé de réception d'une réclamation. En cas d'échec la commande RETOURNE en file :
     * une commande marquée « imprimée » dont aucun papier n'est sorti est le pire des deux
     * mondes — la cuisine ne l'a pas, et plus rien ne la lui donnera.
     */
    public function acknowledge(Request $request, int $order, KitchenTicketAutoPrinter $printer): JsonResponse
    {
        abort_unless(
            $request->user()?->can('pos') || $request->user()?->can('kitchen-display-system'),
            403
        );

        $validated = $request->validate([
            'success'     => ['required', 'boolean'],
            'error'       => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'in:'.implode(',', self::DESTINATIONS)],
        ]);

        $destination = $validated['destination'] ?? self::DESTINATION_COMPTOIR;
        $branchId = (int) ($request->user()->branch_id ?? 0);

        // Portée branche : un poste ne libère que des commandes de SA caisse.
        $found = Order::query()
            ->whereKey($order)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->firstOrFail(['id', 'branch_id']);

        if (! $validated['success']) {
            $printer->releaseClaim((int) $found->id, $destination);

            Log::warning('[KitchenTicketQueue] ticket cuisine non sorti — commande remise en file', [
                'order_id'    => (int) $found->id,
                'destination' => $destination,
                'error'       => $validated['error'] ?? null,
            ]);

            return response()->json([
                'order_id' => (int) $found->id, 'destination' => $destination, 'requeued' => true,
            ]);
        }

        $printer->markClaimPrinted((int) $found->id, $destination);

        Log::info('[KitchenTicketQueue] ticket cuisine imprimé par le pont local', [
            'order_id'    => (int) $found->id,
            'destination' => $destination,
        ]);

        return response()->json([
            'order_id' => (int) $found->id, 'destination' => $destination, 'requeued' => false,
        ]);
    }
}
