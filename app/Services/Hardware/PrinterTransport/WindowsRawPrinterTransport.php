<?php

namespace App\Services\Hardware\PrinterTransport;

/**
 * [PRINT-SAGA 2026-06-24] Raw ESC/POS transport for a USB thermal printer on a
 * Windows caisse (e.g. the counter SAGA plugged in via USB).
 *
 * V1 LOCAL constraint: Laravel/PHP runs ON the same Windows box where the SAGA is
 * plugged in (single box). PHP cannot touch a USB printer on a *different* machine —
 * if the server is remote, a local agent (QZ Tray) is required instead.
 *
 * Method: spool the bytes RAW via the Win32 print spooler (winspool.drv:
 * OpenPrinter → StartDocPrinter datatype "RAW" → WritePrinter → ...). This is the
 * canonical way to send ESC/POS to a Windows printer BY NAME without the driver
 * "cooking" the data (Out-Printer / window.print would mangle raw ESC/POS). The
 * printer NAME is taken from $config['host'] (Printer.host repurposed as the
 * Windows queue name for type=escpos_usb_windows).
 *
 * NOTE: the actual spool can only be verified on the physical Windows+SAGA setup.
 * Use Admin → Printers → "Test print" to validate after configuring.
 */
final class WindowsRawPrinterTransport implements PrinterTransportInterface
{
    private ?string $lastError = null;

    public function send(string $bytes, array $config): bool
    {
        $this->lastError = null;

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->lastError = 'windows_raw_transport_requires_windows_host (PHP_OS_FAMILY=' . PHP_OS_FAMILY . ')';

            return false;
        }

        $printerName = trim((string) ($config['host'] ?? ''));
        if ($printerName === '') {
            $this->lastError = 'missing_windows_printer_name (set Printer.host to the Windows queue name)';

            return false;
        }

        if ($bytes === '') {
            $this->lastError = 'empty_payload';

            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fk_escpos_');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            $this->lastError = 'tempfile_write_failed';

            return false;
        }

        try {
            $cmd = $this->buildSpoolCommand($printerName, $tmp);
            $output = [];
            $exitCode = 0;
            @exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->lastError = sprintf('winspool_spool_failed:exit=%d:%s', $exitCode, trim(implode(' ', $output)) ?: 'no_output');

                return false;
            }

            return true;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Build the PowerShell one-liner that RAW-spools $binPath to $printerName via
     * winspool.drv. Extracted + pure so it can be unit-tested without a printer.
     *
     * Uses -EncodedCommand (base64 UTF-16LE) so the embedded C#/paths survive
     * cmd.exe quoting regardless of spaces/accents in the printer name.
     */
    public function buildSpoolCommand(string $printerName, string $binPath): string
    {
        $ps = $this->powerShellScript($printerName, $binPath);
        $encoded = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));

        return 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>&1';
    }

    private function powerShellScript(string $printerName, string $binPath): string
    {
        // Win32 RawPrinterHelper (Microsoft KB 322090 pattern) — sends RAW bytes
        // to a named printer through the spooler, bypassing driver rendering.
        $csharp = <<<'CS'
using System; using System.IO; using System.Runtime.InteropServices;
public class FKRaw {
 [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Ansi)] public class DI { [MarshalAs(UnmanagedType.LPStr)] public string pDocName; [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile; [MarshalAs(UnmanagedType.LPStr)] public string pDataType; }
 [DllImport("winspool.Drv", EntryPoint="OpenPrinterA", SetLastError=true, CharSet=CharSet.Ansi)] static extern bool OpenPrinter(string s, out IntPtr h, IntPtr p);
 [DllImport("winspool.Drv", EntryPoint="ClosePrinter")] static extern bool ClosePrinter(IntPtr h);
 [DllImport("winspool.Drv", EntryPoint="StartDocPrinterA", SetLastError=true, CharSet=CharSet.Ansi)] static extern bool StartDocPrinter(IntPtr h, int l, [In, MarshalAs(UnmanagedType.LPStruct)] DI di);
 [DllImport("winspool.Drv", EntryPoint="EndDocPrinter")] static extern bool EndDocPrinter(IntPtr h);
 [DllImport("winspool.Drv", EntryPoint="StartPagePrinter")] static extern bool StartPagePrinter(IntPtr h);
 [DllImport("winspool.Drv", EntryPoint="EndPagePrinter")] static extern bool EndPagePrinter(IntPtr h);
 [DllImport("winspool.Drv", EntryPoint="WritePrinter")] static extern bool WritePrinter(IntPtr h, IntPtr buf, int n, out int written);
 public static bool Send(string printer, byte[] data){ IntPtr h; var di=new DI(); di.pDocName="FoodKing Receipt"; di.pDataType="RAW"; if(!OpenPrinter(printer, out h, IntPtr.Zero)) return false; bool ok=false; if(StartDocPrinter(h,1,di)){ if(StartPagePrinter(h)){ IntPtr p=Marshal.AllocCoTaskMem(data.Length); Marshal.Copy(data,0,p,data.Length); int w; ok=WritePrinter(h,p,data.Length,out w); Marshal.FreeCoTaskMem(p); EndPagePrinter(h);} EndDocPrinter(h);} ClosePrinter(h); return ok; }
}
CS;
        // Single-quote the C# (PS here-string) and the printer name / path
        $psPrinter = "'" . str_replace("'", "''", $printerName) . "'";
        $psPath = "'" . str_replace("'", "''", $binPath) . "'";

        return "\$ErrorActionPreference='Stop'\n"
            . "Add-Type -TypeDefinition @\"\n" . $csharp . "\n\"@\n"
            . "\$bytes=[System.IO.File]::ReadAllBytes($psPath)\n"
            . "\$ok=[FKRaw]::Send($psPrinter, \$bytes)\n"
            . "if(-not \$ok){ Write-Error 'winspool_send_returned_false'; exit 1 }\n"
            . "exit 0\n";
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
