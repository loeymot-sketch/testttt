<?php

namespace App\Services\Fiscal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class FiscalSealingService
{
    public function signZReport(
        int $branchId,
        string $prevHash,
        int $sequenceNo,
        array $aggregates,
        Carbon $closedAt
    ): string {
        $aggregates = $this->normalise($aggregates);

        if (!is_array($aggregates)) {
            throw new RuntimeException('FiscalSealingService: Z aggregates must be an array.');
        }

        // Keep the historical ZReportService top-level payload order so
        // closed Z reports signed before the extraction remain verifiable.
        $json = json_encode([
            'branch_id' => $branchId,
            'sequence_no' => $sequenceNo,
            'closed_at' => $closedAt->copy()->utc()->toIso8601String(),
            'aggregates' => $aggregates,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('FiscalSealingService: unable to canonicalise Z payload.');
        }

        return hash_hmac('sha256', $prevHash . '|' . $json, $this->zReportSecret());
    }

    public function hmacForPayload(int $branchId, ?string $prevHash, array $payload, ?string $secret = null): string
    {
        $payload['branch_id'] = $branchId;
        $canonical = $this->canonicalJson($payload);

        return hash_hmac('sha256', ($prevHash ?? '') . '|' . $canonical, $secret ?? $this->zReportSecret());
    }

    public function canonicalJson(array $payload): string
    {
        $normalised = $this->normalise($payload);
        $json = json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('FiscalSealingService: unable to canonicalise payload.');
        }

        return $json;
    }

    private function zReportSecret(): string
    {
        $secret = Config::get('fiscal.z_report_secret');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('FiscalSealingService: fiscal.z_report_secret is not configured.');
        }

        return $this->assertProductionSafe($secret, 'fiscal.z_report_secret');
    }

    private function normalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isAssoc($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalise($item);
        }

        return $value;
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function assertProductionSafe(string $secret, string $key): string
    {
        if (app()->environment() !== 'production') {
            return $secret;
        }

        $sentinels = (array) Config::get('fiscal.dev_sentinels', []);
        if (in_array($secret, $sentinels, true)) {
            throw new RuntimeException(
                "FiscalSealingService: {$key} is set to a known dev sentinel in APP_ENV=production. "
                . 'Rotate the secret (see docs/FISCAL_SECRETS.md).'
            );
        }

        $min = (int) Config::get('fiscal.min_secret_length', 32);
        if (strlen($secret) < $min) {
            throw new RuntimeException(
                "FiscalSealingService: {$key} is shorter than {$min} characters in APP_ENV=production. "
                . 'Generate a strong secret (e.g. openssl rand -hex 32).'
            );
        }

        return $secret;
    }
}
