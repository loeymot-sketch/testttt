<?php

namespace App\Services\Hardware\PrinterTransport;

final class NullPrinterTransport implements PrinterTransportInterface
{
    public array $sent = [];

    public function send(string $bytes, array $config): bool
    {
        $this->sent[] = [
            'bytes' => $bytes,
            'config' => $config,
        ];

        return true;
    }

    public function lastError(): ?string
    {
        return null;
    }
}
