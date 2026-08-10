<?php

namespace App\Services\Loyalty;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RATTACHER UN CLIENT À UNE VENTE DE CAISSE — pour que ses points lui soient crédités.
 *
 * ── LE CONSTAT QUI JUSTIFIE CE SERVICE ───────────────────────────────────────────────────────
 * Mesuré en base le 10 août : **1411 ventes de caisse arrivées à DELIVERED, UNE SEULE rattachée à
 * un client.** Le mécanisme de crédit fonctionne (`AwardLoyaltyPointsOnDelivery`), mais il cherche
 * `orders.loyalty_customer_code`, et personne ne pouvait le renseigner depuis la caisse. Le
 * programme de fidélité existait sur le papier et ne tournait que pour la borne et le site.
 *
 * ── POURQUOI RATTACHER *APRÈS* LA CRÉATION, ET NON PENDANT ───────────────────────────────────
 * La commande de caisse part de `PaymentComponent.vue`, qui est une ZONE GELÉE (CLAUDE.md §7).
 * Y ajouter un champ exigerait une autorisation explicite du propriétaire pour un gain nul : le
 * rattachement n'est pas un prix, il ne touche ni le total, ni la TVA, ni l'empreinte de
 * composition, donc rien qui relève du NF525. On rattache donc après, depuis une surface libre.
 *
 * ── MAIS LE GUETTEUR EST DÉJÀ PASSÉ ──────────────────────────────────────────────────────────
 * Une vente de caisse atteint DELIVERED tout de suite (1411 cas sur 1411). Le guetteur de points a
 * donc déjà tourné, n'a trouvé personne, et remis sa sentinelle à zéro. Rattacher sans plus rien
 * faire laisserait le client avec zéro point : le rattachement doit RELANCER le crédit.
 *
 * ── ET ON RELANCE LE GUETTEUR, PAS L'ÉVÉNEMENT ───────────────────────────────────────────────
 * `OrderStatusChanged` porte QUATRE auditeurs : l'outbox, l'impression automatique du ticket
 * cuisine, le crédit des points, et la notification. Rediffuser l'événement ferait ressortir un
 * SECOND ticket cuisine et une seconde notification pour une vente déjà servie. On appelle donc
 * uniquement l'auditeur des points — il porte sa propre garde atomique
 * (`orders.loyalty_points_awarded`), donc un double appel ne crédite pas deux fois.
 *
 * Aucune ligne du calcul n'est recopiée ici : c'est le même code qui crédite, quelle que soit la
 * surface. Recopier « combien de points vaut cette commande » aurait été le cinquième jumeau de la
 * journée, et le plus coûteux — un barème qui diverge, c'est de l'argent qui diverge.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyAttachTest.php
 */
final class PosLoyaltyAttachService
{
    public function __construct(
        private PosCustomerLookupService $recherche,
    ) {
    }

    /**
     * @return array{ok: bool, code?: string, message?: string, customer?: array, points_awarded?: int}
     */
    public function attach(Order $order, string $loyaltyCode, ?int $cashierId = null): array
    {
        // Une vente annulée, rejetée ou remboursée ne fait gagner aucun point. Rattacher un client à
        // une commande morte, c'est lui promettre des points qu'il ne verra jamais — et l'auditeur
        // les refuserait de toute façon.
        $statut = (int) $order->status;
        if (in_array($statut, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
            return $this->refus('ORDER_TERMINAL', 'Cette commande est annulée ou remboursée.');
        }

        $trouve = $this->recherche->byCode($loyaltyCode);
        if (($trouve['status'] ?? '') !== PosCustomerLookupService::TROUVE) {
            return $this->refus('NO_ACCOUNT', 'Aucun compte client ne correspond à ce code.');
        }
        $client = $trouve['customer'];

        $dejaRattache = trim((string) ($order->loyalty_customer_code ?? ''));

        // DÉJÀ RATTACHÉ À QUELQU'UN D'AUTRE : on refuse. Réécrire le code déplacerait les points
        // d'un humain vers un autre, et si le crédit a déjà eu lieu, le premier ne les rendrait pas.
        // Un caissier qui s'est trompé doit passer par un responsable, pas écraser en silence.
        if ($dejaRattache !== '' && strcasecmp($dejaRattache, $client['loyalty_code']) !== 0) {
            return $this->refus(
                'ALREADY_ATTACHED_OTHER',
                'Cette commande est déjà au nom d\'un autre client — un responsable doit intervenir.'
            );
        }

        DB::table('orders')->where('id', $order->id)
            ->update(['loyalty_customer_code' => $client['loyalty_code']]);

        $order->refresh();

        $pointsCredites = $this->crediterSiLeGuetteurEstDejaPasse($order);

        Log::info('pos.loyalty.rattachement', [
            'order'   => $order->id,
            'points'  => $pointsCredites,
            'cashier' => $cashierId,
        ]);

        return [
            'ok'             => true,
            'customer'       => $this->recherche->byCode($client['loyalty_code'])['customer'] ?? $client,
            'points_awarded' => $pointsCredites,
        ];
    }

    /**
     * Relance UNIQUEMENT l'auditeur des points, pour le statut courant de la commande.
     *
     * Rien à faire si la commande n'a pas encore atteint son déclencheur : l'auditeur créditera tout
     * seul le moment venu, par la voie normale. On ne le devance pas — devancer, ce serait créditer
     * des points pour une commande qui peut encore être annulée en cuisine.
     */
    private function crediterSiLeGuetteurEstDejaPasse(Order $order): int
    {
        $avant = $order->loyalty_points_awarded;

        // `loyalty_points_awarded` non nul veut dire « déjà traité » : la garde atomique de
        // l'auditeur s'en occupe, mais on évite un appel inutile.
        if ($avant !== null) {
            return 0;
        }

        try {
            // L'auditeur relit le statut depuis l'objet. On lui passe le statut courant en ancien ET
            // en nouveau : il n'y a pas eu de transition, on lui demande de reconsidérer l'état réel.
            app(AwardLoyaltyPointsOnDelivery::class)->handle(
                new OrderStatusChanged($order, (int) $order->status, (int) $order->status)
            );
        } catch (\Throwable $e) {
            // Le rattachement, lui, a réussi et reste acquis : si le crédit échoue, le client garde
            // sa commande à son nom et un responsable peut régulariser. On ne défait pas le
            // rattachement, et on ne fait pas tomber la caisse.
            Log::error('pos.loyalty.credit_apres_rattachement_echoue', [
                'order' => $order->id, 'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $apres = DB::table('orders')->where('id', $order->id)->value('loyalty_points_awarded');

        return $apres !== null && (int) $apres > 0 ? (int) $apres : 0;
    }

    private function refus(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
