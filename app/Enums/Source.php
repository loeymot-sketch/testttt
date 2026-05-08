<?php

namespace App\Enums;

interface Source
{

    const WEB = 5;
    const APP = 10;
    const POS = 15;
    // [PARALLEL-TRACK-1.1] Order originated from an external delivery
    // aggregator (Uber Eats / Deliveroo / Delicity) and was ingested
    // through the DeliveryServiceProvider adapter pipeline.
    const DELIVERY_PLATFORM = 30;

}
