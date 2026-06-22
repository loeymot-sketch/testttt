<?php

use App\Enums\OrderStatus;

return [
    OrderStatus::PENDING          => 'En attente',
    OrderStatus::ACCEPT           => 'Acceptée',
    OrderStatus::PREPARING        => 'En préparation',
    OrderStatus::PREPARED         => 'Prête',
    OrderStatus::OUT_FOR_DELIVERY => 'En livraison',
    OrderStatus::DELIVERED        => 'Livrée',
    OrderStatus::CANCELED         => 'Annulée',
    OrderStatus::REJECTED         => 'Refusée',
    OrderStatus::RETURNED         => 'Retournée',
    ''                            => '',


];
