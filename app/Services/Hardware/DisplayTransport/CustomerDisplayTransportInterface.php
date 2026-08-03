<?php

namespace App\Services\Hardware\DisplayTransport;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] Sends raw CD5220 bytes to the pole display.
 */
interface CustomerDisplayTransportInterface
{
    /** @param array<string,mixed> $config (port, baud, …) */
    public function send(string $bytes, array $config): bool;

    public function lastError(): ?string;
}
