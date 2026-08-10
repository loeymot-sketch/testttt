<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Domain\Kds\KitchenReleaseRule;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Kitchen\KitchenTicketAutoPrinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 *  - **Caisse et téléphone sont exclus.** Ils impriment déjà leur ticket cuisine à
 *    l'encaissement ; les inclure ferait sortir DEUX papiers par commande. La liste des
 *    surfaces est le miroir exact de PrintKioskKitchenTicketOnOrderCreated.
 *  - **Seules les commandes RELÂCHÉES en cuisine** (mêmes prédicats que le board : statut
 *    visible + paiement encaissé ou différé comptoir). Une commande dont le paiement est encore
 *    en vol ne doit pas produire de papier.
 */
class KitchenTicketQueueController extends Controller
{
    /**
     * Surfaces dont le ticket cuisine doit sortir automatiquement. Miroir EXACT de
     * PrintKioskKitchenTicketOnOrderCreated — 'pos' et 'phone' en sont absents à dessein
     * (ils impriment au checkout ; les ajouter ici doublerait le papier).
     */
    private const SURFACES = ['kiosk', 'web', 'online', 'delivery', 'uber_eats'];

    /** Nombre maximum de tickets réclamés par sondage — évite une rafale de papier. */
    private const MAX_PAR_CYCLE = 5;

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

        $branchId = (int) ($request->user()->branch_id ?? 0);

        $fenetreMinutes = max(1, (int) config('kds.bridge_print_window_minutes', 30));

        $query = Order::query()
            ->select(['id', 'order_serial_no', 'queue_number', 'branch_id', 'source_surface', 'created_at'])
            ->whereNull('kitchen_ticket_printed_at')
            ->whereIn('source_surface', self::SURFACES)
            ->whereIn('status', KitchenReleaseRule::visibleStatuses())
            ->where('created_at', '>=', now()->subMinutes($fenetreMinutes))
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('created_at');

        KitchenReleaseRule::applyBoardReleaseFilter($query);

        $candidates = $query->limit(self::MAX_PAR_CYCLE)->get();

        $reclamees = [];
        foreach ($candidates as $order) {
            // La base arbitre : si un autre onglet a gagné la course, on passe.
            if (! $printer->claimForBridge((int) $order->id)) {
                continue;
            }

            $reclamees[] = [
                'id'              => (int) $order->id,
                'order_serial_no' => $order->order_serial_no,
                'queue_number'    => $order->queue_number,
                'source_surface'  => $order->source_surface,
            ];
        }

        return response()->json(['orders' => $reclamees]);
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
            'success' => ['required', 'boolean'],
            'error'   => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = (int) ($request->user()->branch_id ?? 0);

        // Portée branche : un poste ne libère que des commandes de SA caisse.
        $found = Order::query()
            ->whereKey($order)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->firstOrFail(['id', 'branch_id']);

        if (! $validated['success']) {
            $printer->releaseClaim((int) $found->id);

            Log::warning('[KitchenTicketQueue] ticket cuisine non sorti — commande remise en file', [
                'order_id' => (int) $found->id,
                'error'    => $validated['error'] ?? null,
            ]);

            return response()->json(['order_id' => (int) $found->id, 'requeued' => true]);
        }

        Log::info('[KitchenTicketQueue] ticket cuisine imprimé par le pont caisse', [
            'order_id' => (int) $found->id,
        ]);

        return response()->json(['order_id' => (int) $found->id, 'requeued' => false]);
    }
}
