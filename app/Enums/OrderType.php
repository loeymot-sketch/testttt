<?php

namespace App\Enums;

interface OrderType
{
    const DELIVERY = 5;
    const TAKEAWAY = 10;
    const POS = 15;
    const DINING_TABLE = 20;
    const KIOSK = 25;  // [SPRINT 9] Commandes borne
}
