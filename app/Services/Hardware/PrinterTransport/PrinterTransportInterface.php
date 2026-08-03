<?php

namespace App\Services\Hardware\PrinterTransport;

interface PrinterTransportInterface
{
    /**
     * Send raw ESC/POS bytes to the physical device.
     * Must never block more than the configured transport timeout.
     */
    public function send(string $bytes, array $config): bool;

    public function lastError(): ?string;
}
