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
     * [PONT-CAISSE 2026-08-10 → DEUX POSTES 2026-08-12] La garde du chemin où l'imprimante est
     * INATTEIGNABLE depuis le serveur.
     *
     * En production le serveur applicatif est chez l'hébergeur et les imprimantes sont au bout du
     * réseau local du restaurant : `printOnce()` ci-dessus sort en `no_printer` et rien ne sort
     * jamais (mesuré — la table `printers` est vide, zéro ligne de journal depuis l'origine).
     * Ce sont les PC caisse et cuisine qui tirent le ticket, comme pour le ticket promo.
     *
     * POURQUOI CETTE GARDE N'EST PAS CELLE DE `printOnce()`
     * -----------------------------------------------------
     * `printOnce()` s'appuie sur `orders.kitchen_ticket_printed_at`, une colonne qui répond à UNE
     * question binaire : « ce ticket est-il sorti ? ». Elle ne peut donc servir qu'UN poste. Or
     * l'owner veut un papier à la caisse ET un en cuisine : le PC caisse et le PC cuisine se
     * seraient volé les tickets à tour de rôle, chacun n'en sortant qu'un sur deux.
     *
     * La réclamation du pont vit donc dans `kitchen_ticket_claims`, clé (commande, destination).
     * Même doctrine, même arbitre : c'est un INSERT protégé par une contrainte d'unicité, pas un
     * « si absent alors insérer » écrit en PHP — deux onglets ne peuvent pas gagner tous les deux.
     *
     * Les deux gardes sont indépendantes parce que les deux CHEMINS le sont : le jour où une
     * imprimante serveur serait enfin joignable, elle garderait sa colonne, le pont garderait sa
     * table, et aucun des deux ne ferait taire l'autre par accident.
     *
     * @param  string  $destination  'counter' (pont caisse 9100) ou 'kitchen' (pont cuisine 9101)
     */
    public function claimForBridge(int $orderId, string $destination): bool
    {
        $pris = DB::table('kitchen_ticket_claims')->insertOrIgnore([
            'order_id'    => $orderId,
            'destination' => $destination,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]) === 1;

        if ($pris) {
            return true;
        }

        // [RÉCLAMATION ORPHELINE 2026-08-12] Une ligne existe déjà. Deux cas très différents :
        //
        //  - le papier est sorti, ou un poste est en train de le sortir → on ne touche à rien ;
        //  - un poste a réclamé puis est mort sans accuser (onglet fermé, PC redémarré, `ack`
        //    perdu) → la réclamation est ABANDONNÉE et le ticket doit repartir, sinon il est
        //    perdu pour toujours.
        //
        // La reprise est un UPDATE CONDITIONNEL : c'est encore la base qui arbitre. Deux postes
        // qui tentent la reprise au même instant ne peuvent pas gagner tous les deux — le second
        // ré-évalue la condition après le verrou de ligne et ne trouve plus de réclamation
        // périmée, donc zéro ligne touchée.
        $ttl = max(10, (int) config('kds.bridge_claim_ttl_seconds', 90));

        return DB::table('kitchen_ticket_claims')
            ->where('order_id', $orderId)
            ->where('destination', $destination)
            ->whereNull('printed_at')
            ->where('updated_at', '<', now()->subSeconds($ttl))
            ->update(['updated_at' => now(), 'error' => null]) === 1;
    }

    /**
     * Rend la commande à la file POUR CETTE DESTINATION. Appelé quand le pont n'a rien pu sortir
     * (papier, hors tension, pont arrêté) : sans ça, une commande réclamée puis non imprimée
     * serait perdue pour de bon, comptée « imprimée » alors qu'aucun papier n'est sorti — le pire
     * des deux mondes. La destination jumelle n'est pas touchée : le papier sorti à la caisse
     * reste sorti même si celui de la cuisine a échoué.
     */
    public function releaseClaim(int $orderId, string $destination): void
    {
        DB::table('kitchen_ticket_claims')
            ->where('order_id', $orderId)
            ->where('destination', $destination)
            ->delete();
    }

    /** Confirme la sortie papier : la réclamation devient une impression avérée. */
    public function markClaimPrinted(int $orderId, string $destination): void
    {
        DB::table('kitchen_ticket_claims')
            ->where('order_id', $orderId)
            ->where('destination', $destination)
            ->update(['printed_at' => now(), 'updated_at' => now()]);
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
