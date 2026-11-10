<?php

namespace App\Support;

use App\Contracts\BroadcastableOrder;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.1.2/3/4] Ce que les tickets (client, cuisine, reçu) disent du CLIENT d'une
 * commande passée À DISTANCE : son téléphone, son adresse de livraison et le règlement qu'il a choisi.
 *
 * Pourquoi un seul endroit : le ticket thermique ne lisait que `pos_customer_phone` (saisie caisse), jamais le compte
 * du client du site — l'écran caisse, lui, retombait sur le compte (OrderDetailsResource). Le livreur partait donc
 * avec un ticket sans téléphone ni adresse, marqué « À RÉGLER EN CAISSE » alors que le client avait choisi les
 * espèces, la carte au livreur ou un titre-restaurant. Même périmètre que le NOM (renderer::nomClientPourTicket) :
 * par SURFACE (web / online / delivery), jamais par type de commande — le compte porteur d'une commande de caisse
 * ou de borne est technique et ne prête rien.
 *
 * Aucune dépendance à une table de réglages : un ticket doit sortir même si la base de réglages est en panne.
 */
final class CoordonneesClientTicket
{
    /** Surfaces où le titulaire du compte EST le client (absent du comptoir). */
    public const SURFACES_CLIENT_ABSENT = ['web', 'online', 'delivery'];

    /** Téléphone à imprimer : saisi en caisse d'abord, sinon le compte du client des surfaces distantes, sinon rien. */
    public static function telephone(BroadcastableOrder $order): string
    {
        $saisi = trim((string) ($order->pos_customer_phone ?? ''));
        if ($saisi !== '') {
            return $saisi;
        }
        if (! self::clientAbsent($order)) {
            return '';
        }
        $user = $order->relationLoaded('user') ? $order->getRelation('user') : (($order->id ?? null) ? $order->user : null);
        if (! $user || ! method_exists($user, 'numeroJoignable')) {
            return '';
        }

        return trim((string) ($user->numeroJoignable() ?? ''));
    }

    /**
     * Adresse de livraison (lignes déjà prêtes à imprimer) pour une LIVRAISON de nos tournées ; [] sinon
     * (retrait, borne, table, agrégateur Uber dont l'adresse n'est pas la nôtre à livrer).
     *
     * @return array<int, string>
     */
    public static function lignesAdresseLivraison(BroadcastableOrder $order): array
    {
        if ((int) ($order->order_type ?? 0) !== OrderType::DELIVERY || self::estUber($order)) {
            return [];
        }
        $a = self::adresse($order);
        if (! $a) {
            return [];
        }
        $lignes = [];
        $voie = trim((string) ($a->address ?? ''));
        if ($voie !== '') {
            $lignes[] = $voie;
        }
        $complement = trim((string) ($a->apartment ?? ''));
        if ($complement !== '') {
            $lignes[] = $complement;
        }
        $cp = trim((string) ($a->postal_code ?? ''));
        $commune = trim((string) ($a->city ?? ''));
        // La voie venue de la BAN porte souvent déjà « 62110 Hénin-Beaumont » : on n'imprime pas deux fois la commune.
        // Saisie à la main, la voie peut porter la commune SANS le code postal : on n'ajoute alors que le code postal
        // (T-1.2.3, 2026-11-10 : « 10 Rue Test, Hénin-Beaumont » sortait suivi de « 62110 Hénin-Beaumont »).
        $voieAvecCommune = $voie !== '' && $commune !== '' && str_contains(self::cle($voie), self::cle($commune));
        $voieAvecCp = $voie !== '' && $cp !== '' && str_contains($voie, $cp);
        $ville = $voieAvecCommune ? ($voieAvecCp ? '' : $cp) : trim($cp.' '.$commune);
        if ($ville !== '') {
            $lignes[] = $ville;
        }

        return $lignes;
    }

    /** Même adresse sur UNE ligne (en-tête cuisine, planche) ; '' si aucune. */
    public static function adresseLivraisonUneLigne(BroadcastableOrder $order): string
    {
        return implode(', ', self::lignesAdresseLivraison($order));
    }

    /**
     * Règlement choisi par le client pour une livraison PAS ENCORE encaissée, libellé prêt à imprimer en capitales
     * (« ESPÈCES », « CARTE AU LIVREUR », « TITRE-RESTAURANT ») ; null quand ce n'est pas une livraison à encaisser à
     * la porte (payée, caisse, borne, retrait, Uber) — le ticket garde alors sa règle habituelle.
     *
     * Vérité partagée avec le site (funnel.jsx lcfReglementLivraison) et le livreur (DeliveryHandoverService) :
     * payment_method 4 = carte EN LIGNE ; `door_payment_mode='card'` = carte au livreur ; 5 = titre-restaurant ; sinon espèces.
     */
    public static function reglementALaPorte(BroadcastableOrder $order): ?string
    {
        if ((int) ($order->order_type ?? 0) !== OrderType::DELIVERY || self::estUber($order)) {
            return null;
        }
        if ((int) ($order->payment_status ?? 0) === PaymentStatus::PAID) {
            return null;
        }
        $methode = (int) ($order->payment_method ?? 0);
        if ($methode === PaymentGateway::CARD || $methode === PaymentGateway::E_WALLET || $methode === PaymentGateway::PAYPAL) {
            return null; // paiement en ligne en attente : ni « à régler à la porte », ni « payé » — règle habituelle
        }
        if ($methode === PaymentGateway::TICKET_RESTAURANT) {
            return 'TITRE-RESTAURANT';
        }

        return (string) ($order->door_payment_mode ?? '') === 'card' ? 'CARTE AU LIVREUR' : 'ESPÈCES';
    }

    public static function clientAbsent(BroadcastableOrder $order): bool
    {
        return in_array((string) ($order->source_surface ?? ''), self::SURFACES_CLIENT_ABSENT, true);
    }

    private static function estUber(BroadcastableOrder $order): bool
    {
        $s = (string) ($order->source_surface ?? '');

        return str_starts_with($s, 'uber') || (string) ($order->source ?? '') === 'uber_eats';
    }

    private static function adresse(BroadcastableOrder $order): ?object
    {
        if (! method_exists($order, 'address')) {
            return null;
        }
        if ($order->relationLoaded('address')) {
            return $order->getRelation('address');
        }
        if (! ($order->id ?? null)) {
            return null;
        }
        try {
            return $order->address()->first();
        } catch (\Throwable) {
            return null; // best-effort : une adresse illisible ne doit jamais empêcher le ticket
        }
    }

    private static function cle(string $s): string
    {
        return mb_strtolower(\Illuminate\Support\Str::ascii($s));
    }
}
