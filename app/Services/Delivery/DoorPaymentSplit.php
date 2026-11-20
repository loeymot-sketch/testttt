<?php

namespace App\Services\Delivery;

use App\Enums\PosPaymentMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [LIVREUR CAISSE 2026-11-02] Règlement d'une livraison à la porte en PLUSIEURS moyens (espèces, carte, titre-restaurant).
 *
 * Ce service ne touche à aucune base : il vérifie la liste de tranches envoyée par le téléphone du livreur et la ramène à une forme
 * canonique en CENTIMES. Le total de référence est TOUJOURS celui de la commande lu côté serveur (jamais un montant venu du client) :
 *  - 1 à 4 tranches, un seul tranche par moyen, chaque montant ≥ 0,01 € et exprimé au centime ;
 *  - la somme est EXACTEMENT le total (au centime près) ;
 *  - ordre canonique : espèces, carte, titre-restaurant (les audits et les lignes `order_payments` suivent cet ordre).
 * Toute irrégularité lève une HttpException 422 avec un message français lisible par le livreur — avant qu'une seule ligne soit écrite.
 */
final class DoorPaymentSplit
{
    public const CASH = 'cash';
    public const CARD = 'card';
    public const MEAL_VOUCHER = 'meal_voucher';

    /** Ordre canonique des moyens. */
    public const MODES = [self::CASH, self::CARD, self::MEAL_VOUCHER];

    public const MAX_TRANCHES = 4;

    public const LABELS = [
        self::CASH => 'Espèces',
        self::CARD => 'Carte',
        self::MEAL_VOUCHER => 'Titre-restaurant',
    ];

    /**
     * @param  mixed  $payments    liste `[{mode, amount}]`, `amount` en euros
     * @param  int    $totalCents  total de la commande, relu côté serveur
     * @return list<array{mode: string, cents: int}>  triées dans l'ordre canonique
     *
     * @throws HttpException 422
     */
    public static function normalize(mixed $payments, int $totalCents): array
    {
        $refuse = static fn (string $msg): HttpException => new HttpException(422, $msg);

        if (! is_array($payments) || $payments === [] || ! array_is_list($payments)) {
            throw $refuse('Indiquez au moins un moyen de paiement avec son montant.');
        }
        if (count($payments) > self::MAX_TRANCHES) {
            throw $refuse('Au plus '.self::MAX_TRANCHES.' moyens de paiement.');
        }
        if ($totalCents <= 0) {
            throw $refuse('Cette commande n\'a rien à encaisser.');
        }

        $byMode = [];
        $sum = 0;
        foreach ($payments as $t) {
            if (! is_array($t)) {
                throw $refuse('Moyen de paiement illisible : recommencez la saisie.');
            }
            $mode = $t['mode'] ?? null;
            if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
                throw $refuse('Moyen de paiement inconnu : choisissez espèces, carte ou titre-restaurant.');
            }
            if (isset($byMode[$mode])) {
                throw $refuse('« '.self::LABELS[$mode].' » est indiqué deux fois : regroupez-le en un seul montant.');
            }
            $amount = $t['amount'] ?? null;
            if (is_int($amount) || is_float($amount)) {
                $euros = (float) $amount;
            } elseif (is_string($amount) && preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) === 1) {
                $euros = (float) trim($amount);
            } else {
                throw $refuse('Montant illisible pour « '.self::LABELS[$mode].' » : saisissez un nombre positif.');
            }
            if (! is_finite($euros) || $euros < 0 || $euros > 100000) {
                throw $refuse('Montant invalide pour « '.self::LABELS[$mode].' ».');
            }
            $cents = (int) round($euros * 100);
            if (abs($euros * 100 - $cents) > 0.0001) {
                throw $refuse('Le montant « '.self::LABELS[$mode].' » doit être exprimé au centime (deux décimales au plus).');
            }
            if ($cents < 1) {
                throw $refuse('Le montant « '.self::LABELS[$mode].' » doit être d\'au moins 0,01 €. Retirez ce moyen s\'il n\'est pas utilisé.');
            }
            $byMode[$mode] = $cents;
            $sum += $cents;
        }

        if ($sum !== $totalCents) {
            throw $refuse(sprintf(
                'La somme saisie (%s) doit être exactement le total de la commande (%s).',
                self::eur($sum),
                self::eur($totalCents)
            ));
        }

        $out = [];
        foreach (self::MODES as $mode) {
            if (isset($byMode[$mode])) {
                $out[] = ['mode' => $mode, 'cents' => $byMode[$mode]];
            }
        }

        return $out;
    }

    /** Valeur `PosPaymentMethod` d'un moyen. */
    public static function posMethod(string $mode): int
    {
        return match ($mode) {
            self::CASH => PosPaymentMethod::CASH,
            self::CARD => PosPaymentMethod::CARD,
            self::MEAL_VOUCHER => PosPaymentMethod::TICKET_RESTAURANT,
            default => throw new HttpException(422, 'Moyen de paiement inconnu.'),
        };
    }

    /** Moyen d'une ligne `order_payments`, ou null (mode étranger à la livraison). */
    public static function modeOfPosMethod(int $pos): ?string
    {
        return match ($pos) {
            PosPaymentMethod::CASH => self::CASH,
            PosPaymentMethod::CARD => self::CARD,
            PosPaymentMethod::TICKET_RESTAURANT => self::MEAL_VOUCHER,
            default => null,
        };
    }

    /**
     * Moyen DOMINANT (plus gros montant) : c'est lui que le Z range pour la commande entière (`pos_payment_method`), comme le fait
     * le paiement multi-moyens du comptoir (`PosOrderRequest` : « mode dominant »). À égalité : espèces, puis carte, puis titre.
     *
     * @param  list<array{mode: string, cents: int}>  $tranches  (ordre canonique)
     */
    public static function dominant(array $tranches): string
    {
        $best = null;
        foreach ($tranches as $t) {
            if ($best === null || $t['cents'] > $best['cents']) {
                $best = $t;
            }
        }

        return $best['mode'];
    }

    /** `12,50 €` */
    public static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }
}
