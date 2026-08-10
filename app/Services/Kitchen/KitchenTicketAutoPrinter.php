<?php

namespace App\Services\Kitchen;

use App\Enums\Status;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [KITCHEN-AUTOPRINT 2026-08-07 owner] Impression AUTOMATIQUE du ticket cuisine.
 *
 * « Chaque commande qui vient de la borne ou bien de la caisse, ou chaque commande qui rentre
 * sur l'écran de cuisine, ça s'imprime automatiquement, sans cliquer sur imprimer. »
 *
 * POURQUOI CE SERVICE PLUTÔT QU'UN LISTENER DE PLUS
 * -------------------------------------------------
 * Plusieurs chemins mènent à la même commande : création (borne, web), passage au statut
 * ACCEPTÉ (caisse), rejeu d'un job en file. Chacun est un déclencheur légitime, mais le
 * rouleau ne doit sortir QU'UNE fois. La garde vit donc ici, à un seul endroit, et tous les
 * déclencheurs passent par elle.
 *
 * LA GARDE EST ATOMIQUE
 * ---------------------
 * On « réclame » la commande par un UPDATE conditionnel (`WHERE kitchen_ticket_printed_at IS
 * NULL`) : la base arbitre. Deux workers qui traitent le même ordre au même instant ne peuvent
 * pas gagner tous les deux. Un simple `if (déjà imprimé)` suivi d'un `save()` laisserait passer
 * les deux — c'est le genre de course qui produit un doublon un vendredi soir et jamais en test.
 *
 * AUCUN AFFICHAGE, JAMAIS
 * -----------------------
 * L'impression part du SERVEUR vers l'imprimante en TCP. Aucune boîte de dialogue navigateur,
 * aucun « impression en cours » : l'écran de cuisine n'est même pas au courant. C'est ce que
 * l'owner demande — « que ça se passe comme un professionnel, rien d'affiché ».
 *
 * SILENCIEUX N'EST PAS MUET
 * -------------------------
 * Un échec d'impression ne doit jamais faire tomber une commande : la cuisine garde son écran.
 * Mais il est journalisé avec sa cause — une imprimante débranchée qui échoue sans trace, c'est
 * un service entier qui tourne à l'aveugle sans le savoir.
 */
final class KitchenTicketAutoPrinter
{
    /** Stations cuisine, par ordre de préférence (la chaude d'abord). */
    private const STATIONS = ['kitchen_hot', 'kitchen', 'kitchen_cold'];

    public function __construct(
        private readonly OrderReceiptEscPosRenderer $renderer = new OrderReceiptEscPosRenderer,
    ) {
    }

    /**
     * Imprime le ticket cuisine UNE SEULE FOIS pour cette commande.
     *
     * @param  object  $order    Order ou FrontendOrder
     * @param  string  $trigger  d'où vient le déclenchement (journalisation)
     * @return string  'printed' | 'already' | 'no_printer' | 'no_order' | 'failed'
     */
    public function printOnce(object $order, string $trigger): string
    {
        $orderId = (int) ($order->id ?? 0);
        $branchId = (int) ($order->branch_id ?? 0);

        if ($orderId <= 0 || $branchId <= 0) {
            return 'no_order';
        }

        $printer = $this->kitchenPrinter($branchId);
        if ($printer === null) {
            // Pas d'imprimante cuisine configurée → la cuisine travaille à l'écran. On ne
            // RÉCLAME pas la commande : le jour où l'imprimante est branchée, la prochaine
            // impression doit pouvoir partir au lieu d'être considérée comme déjà faite.
            return 'no_printer';
        }

        if (! $this->claim($orderId)) {
            return 'already';
        }

        try {
            $this->hydrate($order);

            $bytes = $this->renderer->renderKitchenTicket($order, [
                'width_chars' => (int) ($printer->width_chars ?: 48),
            ]);
            app(EscPosPrinterService::class)->sendRaw($printer, $bytes);

            Log::info('[KitchenTicketAutoPrinter] ticket cuisine imprimé', [
                'order_id' => $orderId,
                'trigger' => $trigger,
                'printer' => $printer->name ?? $printer->host,
            ]);

            return 'printed';
        } catch (Throwable $e) {
            // La réclamation est ANNULÉE : sinon un échec transitoire (imprimante hors tension,
            // câble débranché) condamnerait la commande à ne jamais s'imprimer, même après
            // remise en service. Mieux vaut un risque de doublon qu'un ticket perdu en cuisine.
            $this->release($orderId);

            Log::warning('[KitchenTicketAutoPrinter] impression du ticket cuisine échouée', [
                'order_id' => $orderId,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Réclame la commande de façon ATOMIQUE. Retourne false UNIQUEMENT si un autre chemin l'a
     * déjà prise. C'est la base qui arbitre — un test-puis-écrit en PHP laisserait passer deux
     * workers concurrents.
     *
     * SENS DE LA DÉFAILLANCE : si la commande n'existe pas encore en base (rendu direct, test,
     * flux non persisté), on ne peut pas dédupliquer — et on imprime QUAND MÊME. Un ticket qui
     * manque en cuisine est bien pire qu'un ticket en double : le premier fait oublier un plat,
     * le second fait au pire jeter un bout de papier. Mon premier jet retournait false dans ce
     * cas et supprimait purement et simplement l'impression de la borne et du web.
     */
    private function claim(int $orderId): bool
    {
        $reclamee = DB::table('orders')
            ->where('id', $orderId)
            ->whereNull('kitchen_ticket_printed_at')
            ->update(['kitchen_ticket_printed_at' => now()]) === 1;

        if ($reclamee) {
            return true;
        }

        // Zéro ligne mise à jour : soit déjà réclamée, soit inexistante. Seule la première
        // justifie de ne pas imprimer.
        return ! DB::table('orders')->where('id', $orderId)->exists();
    }

    private function release(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->update(['kitchen_ticket_printed_at' => null]);
    }

    /**
     * [PONT-CAISSE 2026-08-10] Même garde, pour le chemin où l'imprimante est INATTEIGNABLE
     * depuis le serveur.
     *
     * En production le serveur applicatif est chez l'hébergeur et l'imprimante est au bout du
     * réseau local du restaurant : `printOnce()` ci-dessus sort en `no_printer` et rien ne sort
     * jamais (mesuré — la table `printers` est vide, zéro ligne de journal depuis l'origine).
     * C'est le PC caisse qui doit tirer le ticket, exactement comme pour le ticket promo.
     *
     * Ce chemin réclame donc SANS exiger de row `Printer` — le pont local EST l'imprimante. La
     * garde reste celle-ci, une seule, partagée : un jour où une imprimante serveur serait
     * enfin configurée, les deux chemins se disputeraient la même colonne et la base
     * trancherait. C'est précisément pour ça que la garde ne doit pas être recopiée ailleurs.
     */
    public function claimForBridge(int $orderId): bool
    {
        return $this->claim($orderId);
    }

    /**
     * Rend la commande à la file. Appelé quand le pont n'a rien pu sortir (papier, hors tension,
     * pont arrêté) : sans ça, une commande réclamée puis non imprimée serait perdue pour de bon,
     * marquée « imprimée » alors qu'aucun papier n'est sorti — le pire des deux mondes.
     */
    public function releaseClaim(int $orderId): void
    {
        $this->release($orderId);
    }

    /** Imprimante cuisine ACTIVE de la branche, station chaude en priorité. */
    private function kitchenPrinter(int $branchId): ?Printer
    {
        foreach (self::STATIONS as $station) {
            $printer = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('station', $station)
                ->where('status', Status::ACTIVE)
                ->orderBy('id')
                ->first();

            if ($printer) {
                return $printer;
            }
        }

        return null;
    }

    /**
     * Charge ce dont le rendu a besoin. Les lignes sont relues SANS le scope de branche :
     * l'impression peut partir d'un job en file, où aucun utilisateur n'est authentifié — le
     * scope renverrait alors une commande vide et le ticket sortirait sans aucun article.
     */
    private function hydrate(object $order): void
    {
        if (! method_exists($order, 'loadMissing')) {
            return;
        }

        $order->loadMissing(['branch', 'user']);

        if (method_exists($order, 'relationLoaded') && ! $order->relationLoaded('orderItems')) {
            $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());
        }
    }
}
