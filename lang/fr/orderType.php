<?php

use App\Enums\OrderType;

return [
    OrderType::DELIVERY     => 'Livraison',
    OrderType::TAKEAWAY     => 'À emporter',
    OrderType::POS          => 'Caisse',
    OrderType::DINING_TABLE => 'Sur place',
    ''                      => '',

];
