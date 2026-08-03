<?php

namespace App\Services\Hardware;

use App\Services\Hardware\DisplayTransport\CustomerDisplayTransportInterface;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] Drives the SAGA 2x20 pole display:
 *   - idle  → a welcome message (config),
 *   - during a sale → ONLY the running total, refreshed on each add.
 *
 * Best-effort: a missing/failed display must NEVER break the POS flow.
 */
final class CustomerDisplayService
{
    /** @param array<string,mixed> $config config('printing.customer_display') */
    public function __construct(
        private readonly CustomerDisplayTransportInterface $transport,
        private readonly array $config = [],
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /** Idle screen — welcome lines. */
    public function welcomeBytes(): string
    {
        return $this->frame(
            (string) ($this->config['welcome_line1'] ?? 'LE CAYENNE'),
            (string) ($this->config['welcome_line2'] ?? 'Bienvenue !'),
            false,
        );
    }

    /** Sale screen — label on top, total right-aligned below (only the total). */
    public function totalBytes(float $total): string
    {
        return $this->frame(
            (string) ($this->config['total_label'] ?? 'TOTAL'),
            $this->money($total),
            true,
        );
    }

    public function showWelcome(): bool
    {
        return $this->enabled() ? $this->transport->send($this->welcomeBytes(), $this->config) : false;
    }

    public function showTotal(float $total): bool
    {
        return $this->enabled() ? $this->transport->send($this->totalBytes($total), $this->config) : false;
    }

    public function lastError(): ?string
    {
        return $this->transport->lastError();
    }

    /** Assemble + transcode one 2x20 frame. $rightAlignLower for the total. */
    private function frame(string $upper, string $lower, bool $rightAlignLower): string
    {
        $cp = (int) ($this->config['code_page'] ?? 19);
        $b = CustomerDisplayCommandBuilder::init()
            . CustomerDisplayCommandBuilder::selectCodePage($cp)
            . CustomerDisplayCommandBuilder::clear()
            . CustomerDisplayCommandBuilder::upperLine($upper)
            . ($rightAlignLower
                ? "\x1B\x51\x42" . CustomerDisplayCommandBuilder::fitRight($lower) . "\x0D"
                : CustomerDisplayCommandBuilder::lowerLine($lower));

        // Transcode the WHOLE frame once (UTF-8 → display code page), like the receipt.
        return EscPosCommandBuilder::encodeForPrinter($b, $cp === 16 ? 'CP1252' : 'CP858');
    }

    private function money(float $v): string
    {
        return number_format(round($v, 2), 2, ',', ' ') . ' EUR';
    }
}
