<?php

namespace App\Services\Delivery;

use RuntimeException;

/**
 * [LIVRAISON QR 2026-11-01] Refus métier de la remise par QR (jamais une panne). Porte le statut HTTP, un code stable pour la page,
 * un message français, et de quoi tracer le refus (jamais le jeton).
 */
class HandoverRefused extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $tokenId = null,
        public readonly ?int $orderId = null,
        public readonly ?int $stopId = null,
    ) {
        parent::__construct($message);
    }

    /** UNE réponse pour tout QR inconnu, mort ou qui n'est pas celui de ce livreur : elle ne révèle rien. */
    public static function unavailable(?int $tokenId = null, ?int $orderId = null, ?int $stopId = null): self
    {
        return new self(404, 'QR_UNAVAILABLE', 'Ce QR n\'est plus valable. Utilisez l\'écran Livreur ou voyez la caisse.', $tokenId, $orderId, $stopId);
    }
}
