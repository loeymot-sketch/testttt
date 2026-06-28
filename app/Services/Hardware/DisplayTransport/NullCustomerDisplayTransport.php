<?php

namespace App\Services\Hardware\DisplayTransport;

/**
 * Dev / no-hardware transport — swallows the bytes and reports success so the
 * POS flow is identical with or without a physical SAGA display attached.
 */
final class NullCustomerDisplayTransport implements CustomerDisplayTransportInterface
{
    public function send(string $bytes, array $config): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return null;
    }
}
