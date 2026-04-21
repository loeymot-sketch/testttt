<?php

namespace App\Services\Hardware\PrinterTransport;

final class TcpPrinterTransport implements PrinterTransportInterface
{
    private ?string $lastError = null;

    public function send(string $bytes, array $config): bool
    {
        $this->lastError = null;

        $host = $config['host'] ?? null;
        $port = (int) ($config['port'] ?? 9100);

        if (! $host) {
            $this->lastError = 'missing_host';

            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen((string) $host, $port, $errno, $errstr, 2.0);

        if (! is_resource($socket)) {
            $this->lastError = sprintf('tcp_open_failed:%s(%d)', $errstr ?: 'unknown', $errno);

            return false;
        }

        stream_set_timeout($socket, 2);

        $written = @fwrite($socket, $bytes);
        @fclose($socket);

        if ($written === false || $written < strlen($bytes)) {
            $this->lastError = 'tcp_write_partial';

            return false;
        }

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
