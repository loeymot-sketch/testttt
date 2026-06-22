<?php

namespace Tests\Feature\Fiscal;

use Tests\TestCase;

class FiscalArchiveTtlTest extends TestCase
{
    public function test_nf525_archive_retention_defaults_to_six_years(): void
    {
        $this->assertSame(6, (int) config('fiscal.archive_retention_years'));
    }
}
