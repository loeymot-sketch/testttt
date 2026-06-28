<?php

namespace App\Services\Hardware\DisplayTransport;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] Writes CD5220 bytes to the SAGA pole display on a
 * Windows serial (COM) port. The display is USB→serial on the caisse PC, so —
 * like the printer — Laravel must run ON that Windows box (single-box V1).
 *
 * Uses PowerShell System.IO.Ports.SerialPort (base64-encoded payload, same safe
 * pattern as WindowsRawPrinterTransport) so no binary escaping issues on the
 * command line.
 */
final class WindowsSerialDisplayTransport implements CustomerDisplayTransportInterface
{
    private ?string $lastError = null;

    public function send(string $bytes, array $config): bool
    {
        $this->lastError = null;

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->lastError = 'customer_display_requires_windows_host (PHP_OS_FAMILY=' . PHP_OS_FAMILY . ')';

            return false;
        }
        $port = trim((string) ($config['port'] ?? ''));
        if ($port === '') {
            $this->lastError = 'missing_serial_port (set CUSTOMER_DISPLAY_PORT, e.g. COM3)';

            return false;
        }
        $baud = (int) ($config['baud'] ?? 9600);

        $cmd = $this->buildSpoolCommand($port, $baud, $bytes);
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $this->lastError = 'serial_write_failed (' . implode(' | ', (array) $out) . ')';

            return false;
        }

        return true;
    }

    /** Build the PowerShell command that opens the COM port and writes the bytes. */
    public function buildSpoolCommand(string $port, int $baud, string $bytes): string
    {
        $b64 = base64_encode($bytes);
        $ps = '$p=New-Object System.IO.Ports.SerialPort "' . $port . '",' . $baud . ',None,8,One;'
            . '$p.Open();'
            . '$d=[Convert]::FromBase64String("' . $b64 . '");'
            . '$p.Write($d,0,$d.Length);'
            . 'Start-Sleep -Milliseconds 80;'
            . '$p.Close();';
        $encoded = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));

        return 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>&1';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
