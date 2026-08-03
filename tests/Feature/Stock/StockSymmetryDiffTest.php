<?php

namespace Tests\Feature\Stock;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockSymmetryDiffTest extends TestCase
{
    public function test_order_service_and_frontend_order_service_stock_hunks_stay_symmetric(): void
    {
        $process = Process::fromShellCommandline('node tools/audit/order-service-symmetry.mjs', base_path());
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
    }
}
