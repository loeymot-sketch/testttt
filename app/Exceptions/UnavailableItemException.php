<?php

namespace App\Exceptions;

/**
 * [HEAL dispute-r3 C-R2-NEW-2 2026-06-12] Rupture / retrait catalogue d'un
 * article référencé par un panier en cours.
 *
 * R2 adversarial (C30) : « Article 34 indisponible dans le catalogue.
 * Commande rejetée. » — ID interne DB exposé au client borne, et AUCUN
 * identifiant structuré dans la 422 → la borne ne pouvait pas marquer la
 * ligne fautive (cul-de-sac, re-checkout en boucle).
 *
 * Extends \InvalidArgumentException : tous les call-sites historiques
 * (PricingService frozen, OrderService, controllers catch(Exception)) gardent
 * EXACTEMENT le même comportement — seuls les controllers qui veulent le
 * payload structuré ajoutent un catch dédié AVANT le générique :
 *   { code: 'ITEM_UNAVAILABLE', item_id, item_name } (422).
 */
class UnavailableItemException extends \InvalidArgumentException
{
    public const ERROR_CODE = 'ITEM_UNAVAILABLE';

    public function __construct(
        string $message,
        public readonly ?int $itemId = null,
        public readonly ?string $itemName = null,
    ) {
        parent::__construct($message, 422);
    }

    /**
     * Payload 422 structuré pour les surfaces qui marquent la ligne (borne).
     *
     * @return array{status: false, message: string, code: string, item_id: ?int, item_name: ?string}
     */
    public function toResponsePayload(): array
    {
        return [
            'status' => false,
            'message' => $this->getMessage(),
            'code' => self::ERROR_CODE,
            'item_id' => $this->itemId,
            'item_name' => $this->itemName,
        ];
    }
}
